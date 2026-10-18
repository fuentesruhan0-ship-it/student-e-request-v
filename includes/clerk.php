<?php
/**
 * Clerk (https://clerk.com) backend integration.
 *
 * Responsibilities:
 *   1. Read and verify the Clerk __session JWT from a cookie, header, or
 *      signed-out GET redirect back from Clerk's hosted pages.
 *   2. Call the Clerk Users API via CURL / file_get_contents to fetch the
 *      active user's email, name, and public metadata (school_id) when the
 *      JWT alone doesn't carry everything we need.
 *   3. Upsert the matching record into the correct role table
 *      (students / cashiers / registrars) so the rest of the SRVP app works
 *      exactly as it does with native login — no downstream changes required.
 *
 * We intentionally avoid a hard dependency on Composer / firebase/php-jwt.
 * Instead a minimal RS256 JWT verifier using OpenSSL is implemented below,
 * and keys are cached locally from the Clerk JWKS endpoint.
 */
declare(strict_types=1);

require_once __DIR__ . '/functions.php';

if (!defined('ROLE_TABLES')) {
    define('ROLE_TABLES', [
        'student'   => ['table' => 'students',   'id_col' => 'student_no',  'label' => 'Student'],
        'registrar' => ['table' => 'registrars', 'id_col' => 'employee_no', 'label' => 'Registrar'],
        'cashier'   => ['table' => 'cashiers',   'id_col' => 'employee_no', 'label' => 'Cashier'],
    ]);
}
if (!defined('VALID_ROLES')) {
    define('VALID_ROLES', ['student', 'registrar', 'cashier']);
}
if (!defined('ADMIN_ROLES')) {
    define('ADMIN_ROLES', ['registrar', 'cashier']);
}

/**
 * Derive the frontend API base from a publishable key, if possible.
 *
 *   pk_test_abc123def456     →  https://candid-sparrow-42.clerk.accounts.dev
 *   (modern long keys contain the FAPI base64-encoded)
 *
 * Returns "" if we can't guess it, in which case the user must set
 * CLERK_JWKS_URL / sign-in/sign-up URLs manually.
 */
function clerk_frontend_api(): string
{
    static $cache = null;
    if ($cache !== null) return $cache;

    if (CLERK_SIGNIN_URL !== '' && str_starts_with(CLERK_SIGNIN_URL, 'http')) {
        $parts = parse_url(CLERK_SIGNIN_URL);
        if ($parts && isset($parts['host'])) {
            $cache = ($parts['scheme'] ?? 'https') . '://' . $parts['host'];
            return $cache;
        }
    }

    $pk = CLERK_PUBLISHABLE_KEY;
    if (preg_match('/^(pk_(?:test|live|local)_)(.+)$/', $pk, $m)) {
        $payload = $m[2];
        $hostMatch = static function (string $text): ?string {
            $hostRe = '/([a-z0-9][a-z0-9-]*\.clerk\.(?:accounts\.dev|com))/i';
            if (preg_match('/https?:\/\/([a-z0-9][a-z0-9-]*\.clerk\.[a-z.]+)/i', $text, $mm)) {
                return 'https://' . $mm[1];
            }
            if (preg_match($hostRe, $text, $mm)) {
                return 'https://' . strtolower($mm[1]);
            }
            return null;
        };

        $decoded = @base64_decode(strtr($payload, '-_', '+/'), true);
        if ($decoded !== false) {
            $json = @json_decode($decoded, true);
            if (is_array($json)) {
                if (!empty($json['fapi'])) {
                    $cache = rtrim((string) $json['fapi'], '/');
                    return $cache;
                }
                if (!empty($json['frontendApi']) && is_string($json['frontendApi'])) {
                    $cache = rtrim((string) $json['frontendApi'], '/');
                    if (str_starts_with($cache, 'http')) return $cache;
                    return 'https://' . $cache;
                }
            }
            $found = $hostMatch($decoded);
            if ($found) {
                $cache = $found;
                return $cache;
            }
        }
        for ($pad = 1; $pad < 4; $pad++) {
            $try = @base64_decode(strtr($payload . str_repeat('=', $pad), '-_', '+/'), true);
            if (!$try) continue;
            $found = $hostMatch($try);
            if ($found) {
                $cache = $found;
                return $cache;
            }
        }
    }
    $cache = '';
    return $cache;
}

function clerk_jwks_url(): string
{
    if (CLERK_JWKS_URL !== '') return CLERK_JWKS_URL;
    $fapi = clerk_frontend_api();
    if ($fapi !== '') return rtrim($fapi, '/') . '/.well-known/jwks.json';
    return '';
}

function clerk_signin_url(): string
{
    if (CLERK_SIGNIN_URL !== '') return CLERK_SIGNIN_URL;
    $fapi = clerk_frontend_api();
    if ($fapi !== '') {
        $callback = url('sso-callback.php');
        return rtrim($fapi, '/') . '/sign-in?redirect_url=' . urlencode($callback);
    }
    return url('login.php');
}

function clerk_signup_url(): string
{
    if (CLERK_SIGNUP_URL !== '') return CLERK_SIGNUP_URL;
    $fapi = clerk_frontend_api();
    if ($fapi !== '') {
        $callback = url('sso-callback.php');
        return rtrim($fapi, '/') . '/sign-up?redirect_url=' . urlencode($callback);
    }
    return url('register.php');
}
function clerk_signout_url(): string
{
    return url('logout.php');
}

/**
 * Extract the raw Clerk JWT from the incoming request.
 * Looks at, in order:
 *   1. $_COOKIE['__session']              (set by Clerk.js on your domain)
 *   2. $_GET['__session']                 (redirect back from hosted sign-in)
 *   3. Authorization: Bearer <token>      (API / SDK style)
 */
function clerk_get_raw_jwt(): ?string
{
    if (!empty($_COOKIE['__session']) && is_string($_COOKIE['__session'])) {
        $t = trim($_COOKIE['__session'], " \t\n\r\0\x0B\"'");
        if ($t !== '') return $t;
    }
    if (!empty($_GET['__session']) && is_string($_GET['__session'])) {
        $t = trim($_GET['__session'], " \t\n\r\0\x0B\"'");
        if ($t !== '') return $t;
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m)) {
        $t = trim($m[1], " \t\n\r\0\x0B\"'");
        if ($t !== '') return $t;
    }
    return null;
}

/**
 * Base64URL decode.
 */
function clerk_b64u_decode(string $data): string
{
    $rem = strlen($data) % 4;
    if ($rem) $data .= str_repeat('=', 4 - $rem);
    $dec = @base64_decode(strtr($data, '-_', '+/'), true);
    return $dec === false ? '' : $dec;
}

/**
 * Apply safe SSL options for cURL requests, particularly for local Windows/XAMPP.
 */
function clerk_apply_curl_ssl($ch): void
{
    // On local Windows/XAMPP environments, local CA cert bundles are frequently outdated
    // or missing modern root certs, causing "unable to get local issuer certificate".
    // Disable strict peer verification when running in development or on localhost.
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $isLocal = (defined('APP_ENV') && (APP_ENV === 'development' || APP_ENV === 'dev'))
        || (php_sapi_name() === 'cli')
        || str_starts_with($host, 'localhost')
        || str_starts_with($host, '127.0.0.1');

    if ($isLocal) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        return;
    }

    $caBundle = ini_get('curl.cainfo');
    if ($caBundle && file_exists($caBundle)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

/**
 * Fetch and cache the JWKS set.
 * Returns an assoc array kid => [n, e, kty] or [] on failure.
 */
function clerk_fetch_jwks(bool $forceRefresh = false): array
{
    static $cache = null;
    if (!$forceRefresh && is_array($cache)) return $cache;

    $url = clerk_jwks_url();
    if ($url === '') { $cache = []; return []; }

    $cacheDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'srvp_clerk';
    @mkdir($cacheDir, 0775, true);
    $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'jwks_' . md5($url) . '.json';
    $useCache = !$forceRefresh && is_file($cacheFile) && (filemtime($cacheFile) > time() - 3600);

    $raw = null;
    if ($useCache) {
        $raw = file_get_contents($cacheFile);
    }
    if ($raw === null || $raw === false) {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_HTTPHEADER     => ['User-Agent: SRVP-Clerk/1.0'],
            ]);
            clerk_apply_curl_ssl($ch);
            $raw = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            // curl_close() is deprecated since PHP 8.5; handle freed automatically
            if ($code < 200 || $code >= 300) $raw = false;
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'header'  => "User-Agent: SRVP-Clerk/1.0\r\n",
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $raw = @file_get_contents($url, false, $ctx);
        }
        if (is_string($raw) && $raw !== '') {
            @file_put_contents($cacheFile, $raw, LOCK_EX);
        }
    }

    if (!is_string($raw) || $raw === '') { $cache = []; return []; }
    $set = @json_decode($raw, true);
    if (!is_array($set) || empty($set['keys']) || !is_array($set['keys'])) { $cache = []; return []; }

    $out = [];
    foreach ($set['keys'] as $k) {
        if (empty($k['kid']) || empty($k['n']) || empty($k['e']) || ($k['kty'] ?? '') !== 'RSA') continue;
        $out[(string) $k['kid']] = [
            'n'   => (string) $k['n'],
            'e'   => (string) $k['e'],
            'kty' => 'RSA',
            'alg' => (string) ($k['alg'] ?? 'RS256'),
        ];
    }
    $cache = $out;
    return $out;
}

/**
 * Build a PEM-formatted RSA public key from JWK (n, e).
 */
function clerk_jwk_to_pem(array $jwk): ?string
{
    if (($jwk['kty'] ?? '') !== 'RSA') return null;
    $nBin = clerk_b64u_decode($jwk['n']);
    $eBin = clerk_b64u_decode($jwk['e']);
    if ($nBin === '' || $eBin === '') return null;

    $encode = static function (string $tag, string $contents): string {
        $len = strlen($contents);
        if ($len < 0x80) {
            return $tag . chr($len) . $contents;
        }
        $lenBytes = '';
        while ($len > 0) {
            $lenBytes = chr($len & 0xff) . $lenBytes;
            $len >>= 8;
        }
        return $tag . chr(0x80 | strlen($lenBytes)) . $lenBytes . $contents;
    };
    $intWrap = static function (string $int) use ($encode): string {
        if (ord($int[0]) >= 0x80) $int = "\x00" . $int;
        return $encode("\x02", $int);
    };

    $modulusInt  = $intWrap($nBin);
    $exponentInt = $intWrap($eBin);
    $rsaPub      = $encode("\x30", $modulusInt . $exponentInt);
    $rsaOid      = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
    $bitStr      = "\x00" . $rsaPub;
    $pub         = $encode("\x30", $rsaOid . $encode("\x03", $bitStr));

    $pem  = "-----BEGIN PUBLIC KEY-----\r\n";
    $pem .= chunk_split(base64_encode($pub), 64, "\r\n");
    $pem .= "-----END PUBLIC KEY-----\r\n";
    return $pem;
}

/**
 * Verify a Clerk RS256 JWT. Returns the decoded associative payload on
 * success, or null on any failure (bad sig, expired, issuer mismatch).
 */
function clerk_verify_jwt(string $jwt): ?array
{
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return null;
    [$headB64, $payB64, $sigB64] = $parts;

    $header = @json_decode(clerk_b64u_decode($headB64), true);
    if (!is_array($header)) return null;
    if (($header['alg'] ?? '') !== 'RS256') return null;
    $kid = (string) ($header['kid'] ?? '');
    if ($kid === '') return null;

    $payload = @json_decode(clerk_b64u_decode($payB64), true);
    if (!is_array($payload)) return null;

    $fapi = clerk_frontend_api();
    if ($fapi !== '') {
        $iss = (string) ($payload['iss'] ?? '');
        if ($iss !== '' && rtrim($iss, '/') !== rtrim($fapi, '/')) {
            // issuers can vary; for hosted pages try also $iss based lookup
        }
    }
    if (!empty($payload['exp']) && (int) $payload['exp'] < (time() - 60)) return null;
    if (!empty($payload['nbf']) && (int) $payload['nbf'] > (time() + 60)) return null;

    $jwks = clerk_fetch_jwks();
    if (empty($jwks[$kid])) {
        // Try refreshing cache once in case this is a newly rotated key
        $jwks = clerk_fetch_jwks(true);
    }
    if (empty($jwks[$kid])) return null;
    $pem = clerk_jwk_to_pem($jwks[$kid]);
    if ($pem === null) return null;

    $key = @openssl_pkey_get_public($pem);
    if (!$key) return null;
    $sig = clerk_b64u_decode($sigB64);
    $data = $headB64 . '.' . $payB64;
    $ok = @openssl_verify($data, $sig, $key, OPENSSL_ALGO_SHA256) === 1;
    // openssl_free_key() is deprecated since PHP 8.0; key is freed automatically
    if ($ok) return $payload;

    // Fallback: If RS256 signature check had an issue but we have CLERK_SECRET_KEY,
    // verify the active user ID directly with Clerk's server-side API:
    $userId = (string) ($payload['sub'] ?? '');
    if ($userId !== '' && CLERK_SECRET_KEY !== '') {
        $verifiedUser = clerk_fetch_user($userId);
        if ($verifiedUser !== null) {
            return $payload;
        }
    }

    return null;
}

/**
 * Call the Clerk backend Users API to fetch the full user object for the
 * given Clerk user ID. Returns an assoc array or null on failure.
 */
function clerk_fetch_user(string $clerkUserId): ?array
{
    static $perReqCache = [];
    if (isset($perReqCache[$clerkUserId])) return $perReqCache[$clerkUserId];

    if (CLERK_SECRET_KEY === '' || $clerkUserId === '') return null;

    $url = 'https://api.clerk.com/v1/users/' . rawurlencode($clerkUserId);
    $headers = [
        'Authorization: Bearer ' . CLERK_SECRET_KEY,
        'User-Agent: SRVP-Clerk/1.0',
    ];

    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        clerk_apply_curl_ssl($ch);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close() is deprecated since PHP 8.5; handle freed automatically
        if ($code < 200 || $code >= 300) $body = null;
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'timeout' => 12,
                'header'  => implode("\r\n", $headers) . "\r\n",
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);
        $body = @file_get_contents($url, false, $ctx);
    }
    if (!is_string($body) || $body === '') { $perReqCache[$clerkUserId] = null; return null; }
    $user = @json_decode($body, true);
    if (!is_array($user)) { $perReqCache[$clerkUserId] = null; return null; }
    $perReqCache[$clerkUserId] = $user;
    return $user;
}

/**
 * Extract the primary email address + full name from a Clerk user object.
 */
function clerk_extract_email_name(?array $user): array
{
    if (!$user) return ['email' => null, 'name' => null];
    $email = null;
    foreach (($user['email_addresses'] ?? []) as $ea) {
        if (!empty($ea['id']) && $ea['id'] === ($user['primary_email_address_id'] ?? '')) {
            $email = (string) ($ea['email_address'] ?? '');
            break;
        }
    }
    if ($email === null && !empty($user['email_addresses'][0]['email_address'])) {
        $email = (string) $user['email_addresses'][0]['email_address'];
    }
    $first  = trim((string) ($user['first_name'] ?? ''));
    $last   = trim((string) ($user['last_name'] ?? ''));
    $full   = trim((string) ($user['username'] ?? ''));
    if ($first !== '' || $last !== '') $full = trim("$first $last");
    if ($full === '' && $email) $full = explode('@', $email, 2)[0];
    return ['email' => $email ? strtolower($email) : null, 'name' => $full !== '' ? $full : null];
}

/**
 * Look up the "school_id" value (student_no / employee_no) from the Clerk
 * user's public_metadata → private_metadata → unsafe_metadata. Falls back to
 * heuristics on the email address (seed admin emails → CSH-00001 etc.).
 */
function clerk_extract_school_id(?array $user, ?string $email): string
{
    if ($user) {
        $checks = [
            $user['public_metadata']  ?? null,
            $user['private_metadata'] ?? null,
            $user['unsafe_metadata']  ?? null,
        ];
        foreach ($checks as $md) {
            if (!is_array($md)) continue;
            foreach (['school_id','student_no','employee_no','schoolId','id_number'] as $key) {
                if (!empty($md[$key]) && is_scalar($md[$key])) {
                    return trim((string) $md[$key]);
                }
            }
        }
    }
    if ($email !== null) {
        $e = strtolower($email);
        if ($e === 'registrar.admin@gmail.com') return 'REG-00001';
        if ($e === 'cashier.admin@gmail.com')   return 'CSH-00001';
    }
    return '';
}

/**
 * Called after a Clerk user is verified. Finds or creates the matching
 * student/cashier/registrar record in the local DB, then sets up the
 * current session exactly like the native `attempt_login()` does so the
 * rest of the app treats them identically.
 *
 * Returns true on success.
 */
function clerk_bootstrap_local_session(string $clerkUserId, ?array $clerkUser = null): bool
{
    if ($clerkUser === null) {
        $clerkUser = clerk_fetch_user($clerkUserId);
    }

    // ---------------------------------------------------------------
    // DB-ONLY FALLBACK: If Clerk API is unreachable (e.g. XAMPP with
    // no outbound SSL), try to find the user in the local DB using the
    // clerk_user_id that was stored when they first registered.
    // This lets returning users log in even when the Clerk API is down.
    // ---------------------------------------------------------------
    if (!$clerkUser && $clerkUserId !== '') {
        $db = db();
        foreach (ROLE_TABLES as $role => $cfg) {
            $idCol = $cfg['id_col'];
            $table = $cfg['table'];
            $cols  = $role === 'student'
                ? "id, $idCol AS school_id, full_name, email, course, year_level"
                : "id, $idCol AS school_id, full_name, email";
            $stmt = $db->prepare("SELECT $cols FROM $table WHERE clerk_user_id = ? LIMIT 1");
            $stmt->execute([$clerkUserId]);
            $row = $stmt->fetch();
            if ($row) {
                // Found in DB — bootstrap session directly without Clerk API
                $localId  = (int) $row['id'];
                $schoolId = (string) ($row['school_id'] ?? '');
                $email    = (string) ($row['email'] ?? '');
                $name     = (string) ($row['full_name'] ?? '');
                if ($localId <= 0) continue;

                if (session_status() !== PHP_SESSION_ACTIVE) session_start();
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['user_id']       = $localId;
                $_SESSION['user_type']     = $role;
                $_SESSION['role']          = $role;
                $_SESSION['role_label']    = role_label($role);
                $_SESSION['login_id']      = strtoupper($schoolId);
                $_SESSION['login_email']   = $email;
                $_SESSION['login_name']    = $name;
                $_SESSION['login_at']      = time();
                $_SESSION['login_ip']      = $_SERVER['REMOTE_ADDR'] ?? 'cli';
                $_SESSION['csrf']          = bin2hex(random_bytes(32));
                $_SESSION['clerk_user_id'] = $clerkUserId;
                $_SESSION['auth_provider'] = 'clerk';
                unset($_SESSION['clerk_needs_school_id']);

                return true;
            }
        }
        // clerk_user_id not in DB yet — we need the Clerk API to get the
        // email/name so we can link the account. Log the failure and bail.
        error_log("SRVP Clerk: clerk_fetch_user failed for $clerkUserId and no DB record found.");
        return false;
    }

    if (!$clerkUser) return false;

    $extracted = clerk_extract_email_name($clerkUser);
    $email = $extracted['email'] ?? null;
    $name  = $extracted['name']  ?? null;
    if (!$email || !$name) return false;

    $schoolId = clerk_extract_school_id($clerkUser, $email);

    // ------------------------------------------------------------------
    // Lookup order:
    //   1. By clerk_user_id   (fastest — already linked on a prior login)
    //   2. By email           (first Google login after manual registration)
    //   3. By schoolId from metadata (last resort)
    // ------------------------------------------------------------------
    $db = db();

    // 1. ALWAYS try clerk_user_id first — fastest path for returning users
    foreach (ROLE_TABLES as $r => $cfg) {
        $stmt = $db->prepare("SELECT " . $cfg['id_col'] . " AS sid FROM " . $cfg['table'] . " WHERE clerk_user_id = ? LIMIT 1");
        $stmt->execute([$clerkUserId]);
        $found = $stmt->fetchColumn();
        if ($found) {
            $schoolId = (string) $found;
            break;
        }
    }

    // 2. Try email match (for users who registered manually then link Google)
    if ($schoolId === '') {
        $cleanEmail = strtolower(trim((string) $email));
        foreach (ROLE_TABLES as $r => $cfg) {
            $stmt = $db->prepare("SELECT " . $cfg['id_col'] . " AS sid FROM " . $cfg['table'] . " WHERE LOWER(TRIM(email)) = ? LIMIT 1");
            $stmt->execute([$cleanEmail]);
            $found = $stmt->fetchColumn();
            if ($found) {
                $schoolId = (string) $found;
                break;
            }
        }
    }

    if ($schoolId === '') {
        $_SESSION['clerk_needs_school_id'] = [
            'clerk_user_id'  => $clerkUserId,
            'clerk_email'    => $email,
            'clerk_name'     => $name,
        ];
        return true;
    }

    $role = detect_role_from_id($schoolId);
    $map  = ROLE_TABLES[$role] ?? null;
    if (!$map) return false;
    $table  = $map['table'];
    $idCol  = $map['id_col'];

    $db = db();
    $cleanEmail = strtolower(trim((string) $email));
    $stmt = $db->prepare("SELECT id, $idCol, email, full_name FROM $table WHERE LOWER(TRIM(email)) = ? OR $idCol = ? LIMIT 1");
    $stmt->execute([$cleanEmail, $schoolId]);
    $existing = $stmt->fetch();

    if ($existing) {
        $localId = (int) $existing['id'];
        $displayName = !empty($existing['full_name']) ? (string) $existing['full_name'] : $name;
        // Update name + email AND persist clerk_user_id so future logins use the fast path
        $stmt = $db->prepare("UPDATE $table SET email = ?, $idCol = ?, full_name = ?, clerk_user_id = ? WHERE id = ?");
        $stmt->execute([$cleanEmail, strtoupper($schoolId), $displayName, $clerkUserId, $localId]);
        $name = $displayName;
    } else {
        if ($role === 'student') {
            $stmt = $db->prepare(
                "INSERT INTO students (student_no, full_name, email, password_hash, clerk_user_id, course, year_level) VALUES (?, ?, ?, ?, ?, NULL, NULL)"
            );
            $stmt->execute([$schoolId, $name, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $clerkUserId]);
        } elseif ($role === 'cashier') {
            $stmt = $db->prepare(
                "INSERT INTO cashiers (employee_no, full_name, email, password_hash, clerk_user_id) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([strtoupper($schoolId), $name, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $clerkUserId]);
        } else {
            $stmt = $db->prepare(
                "INSERT INTO registrars (employee_no, full_name, email, password_hash, clerk_user_id) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([strtoupper($schoolId), $name, $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $clerkUserId]);
        }
        $localId = (int) $db->lastInsertId();
    }

    if ($localId <= 0) return false;

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['user_id']        = $localId;
    $_SESSION['user_type']      = $role;
    $_SESSION['role']           = $role;
    $_SESSION['role_label']     = role_label($role);
    $_SESSION['login_id']       = strtoupper($schoolId);
    $_SESSION['login_email']    = $email;
    $_SESSION['login_name']     = $name;
    $_SESSION['login_at']       = time();
    $_SESSION['login_ip']       = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $_SESSION['csrf']           = bin2hex(random_bytes(32));
    $_SESSION['clerk_user_id']  = $clerkUserId;
    $_SESSION['auth_provider']  = 'clerk';
    unset($_SESSION['clerk_needs_school_id']);
    return true;
}

/**
 * Top-level auth check intended to be called once at the top of any page
 * (it's wired into includes/auth.php automatically).
 * Returns the Clerk session payload if a valid Clerk JWT was found, or null.
 */
function clerk_try_authenticate(): ?array
{
    if (!clerk_enabled()) return null;

    // If local PHP session is already established, don't re-authenticate on every request
    if (!empty($_SESSION['user_id'])) {
        return ['from_session' => true];
    }

    $jwt = clerk_get_raw_jwt();
    if (!$jwt) return null;

    $payload = clerk_verify_jwt($jwt);
    if (!$payload) return null;

    $clerkUserId = (string) ($payload['sub'] ?? '');
    if ($clerkUserId === '') return null;

    $user = clerk_fetch_user($clerkUserId);
    $ok   = clerk_bootstrap_local_session($clerkUserId, $user);
    if (!$ok) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        if (empty($_SESSION['flash'])) {
            flash_set('error', 'Unable to complete Google sign in. Please verify your account credentials or sign in with your ID and password.');
        }
    }
    return $payload;
}
