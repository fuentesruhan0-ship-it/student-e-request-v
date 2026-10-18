<?php
/**
 * Database configuration + PDO connection.
 *
 * Loads credentials from (in order of precedence):
 *   1. Environment variables (Vercel, docker, CI, system env)
 *   2. .env.local file in the project root
 *   3. Hardcoded XAMPP-friendly defaults
 */

declare(strict_types=1);

/**
 * Lightweight .env file parser — no Composer / vlucas/phpdotenv required.
 * Reads KEY=VALUE lines into $_ENV and $_SERVER (getenv()-compatible).
 */
function load_env_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if ($value === '') {
            continue;
        }
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        if ($key === '' || preg_match('/[^A-Z0-9_]/i', $key)) {
            continue;
        }
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
        }
        if (!array_key_exists($key, $_SERVER)) {
            $_SERVER[$key] = $value;
        }
        if (getenv($key) === false) {
            putenv("$key=$value");
        }
    }
}

$projectRoot = dirname(__DIR__);
load_env_file($projectRoot . DIRECTORY_SEPARATOR . '.env.local');
load_env_file($projectRoot . DIRECTORY_SEPARATOR . '.env');

function env(string $key, mixed $default = null): mixed
{
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($val === false || $val === null) {
        return $default;
    }
    $lower = strtolower((string) $val);
    if ($lower === 'true')  return true;
    if ($lower === 'false') return false;
    if ($lower === 'null')  return null;
    return $val;
}

define('APP_NAME',    (string) env('APP_NAME',    'SRVP — Student Request & Payment Portal'));
define('APP_ENV',     (string) env('APP_ENV',     'development'));
define('APP_URL',     (string) env('APP_URL',     ''));
define('DB_HOST',     (string) env('DB_HOST',     '127.0.0.1'));
define('DB_PORT',     (string) env('DB_PORT',     '3306'));
define('DB_NAME',     (string) env('DB_NAME',     'student-req'));
define('DB_USER',     (string) env('DB_USER',     'root'));
define('DB_PASS',     (string) env('DB_PASS',     ''));
define('DB_SSL',      (bool)   env('DB_SSL',      false));
define('UPLOAD_DIR',  dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads');
define('UPLOAD_WEB',  'uploads/');

define('MAIL_MODE',       (string) env('MAIL_MODE',       'log'));
define('MAIL_FROM',       (string) env('MAIL_FROM',       'no-reply@srvp.test'));
define('MAIL_FROM_NAME',  (string) env('MAIL_FROM_NAME',  'SRVP Student Portal'));
define('MAIL_HOST',       (string) env('MAIL_HOST',       'smtp.example.com'));
define('MAIL_PORT',       (int)    env('MAIL_PORT',       587));
define('MAIL_USER',       (string) env('MAIL_USER',       ''));
define('MAIL_PASS',       (string) env('MAIL_PASS',       ''));

// ---- Clerk (https://clerk.com) — hosted login/auth -------------------------
// Set CLERK_ENABLED=true and fill the keys below to replace the native
// login/register forms with Clerk hosted sign-in/sign-up pages.
// Leave empty / false to keep the app's built-in password-based forms.
//
// Clerk's own default variable names (NEXT_PUBLIC_CLERK_*) are also accepted
// so you can copy-paste from the Clerk dashboard unchanged.
function clerk_env(string $name, mixed $default = null): mixed
{
    $ours = env('CLERK_' . $name, null);
    if ($ours !== null && $ours !== '') return $ours;
    $next = env('NEXT_PUBLIC_CLERK_' . $name, null);
    if ($next !== null && $next !== '') return $next;
    return $default;
}

$_explicitClerk = null;
foreach (['CLERK_ENABLED', 'NEXT_PUBLIC_CLERK_ENABLED'] as $var) {
    $raw = (string) env($var, '');
    if ($raw !== '') {
        $_explicitClerk = (strtolower($raw) !== 'false' && $raw !== '0');
        break;
    }
}
if ($_explicitClerk === null) {
    $_explicitClerk = (clerk_env('PUBLISHABLE_KEY', null) !== null && clerk_env('SECRET_KEY', null) !== null);
}
$_defEnabled = $_explicitClerk;
define('CLERK_ENABLED',         (bool)   $_defEnabled);
define('CLERK_PUBLISHABLE_KEY', (string) (clerk_env('PUBLISHABLE_KEY', '') ?? ''));
define('CLERK_SECRET_KEY',      (string) (clerk_env('SECRET_KEY', '')      ?? ''));
define('CLERK_JWKS_URL',        (string) (clerk_env('JWKS_URL', '')        ?? ''));
define('CLERK_SIGNIN_URL',      (string) (clerk_env('SIGNIN_URL', '')      ?? ''));
define('CLERK_SIGNUP_URL',      (string) (clerk_env('SIGNUP_URL', '')      ?? ''));
define('CLERK_AFTER_SIGNIN_URL',(string) (clerk_env('AFTER_SIGNIN_URL','') ?? ''));
define('CLERK_AFTER_SIGNOUT_URL',(string)(clerk_env('AFTER_SIGNOUT_URL','')?? ''));

function clerk_enabled(): bool
{
    return CLERK_ENABLED
        && CLERK_PUBLISHABLE_KEY !== ''
        && CLERK_SECRET_KEY !== '';
}

// ---- Error handling ------------------------------------------------------
$isDev = APP_ENV === 'development' || APP_ENV === 'dev';
error_reporting(E_ALL);
ini_set('display_errors', $isDev ? '1' : '0');
ini_set('log_errors', '1');

// ---- Session -------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {
    $projectRoot = dirname(__DIR__);
    $sessionDir = $projectRoot . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'sessions';
    if (!is_dir($sessionDir)) {
        @mkdir($sessionDir, 0775, true);
    }
    if (is_dir($sessionDir) && is_writable($sessionDir)) {
        session_save_path($sessionDir);
    } else {
        $candidateDirs = [
            dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'tmp',
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'srvp_sessions',
            sys_get_temp_dir(),
        ];
        foreach ($candidateDirs as $candidate) {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0775, true);
            }
            if (is_dir($candidate) && is_writable($candidate)) {
                session_save_path($candidate);
                break;
            }
        }
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    $cookiePath = '/';
    if (!empty($_SERVER['SCRIPT_NAME'])) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME']);
        $dir = rtrim(dirname($script), '/');
        foreach (['/student', '/admin', '/api'] as $sub) {
            if (str_ends_with($dir, $sub)) { $dir = substr($dir, 0, -strlen($sub)); break; }
        }
        if ($dir !== '' && $dir !== '.') $cookiePath = rtrim($dir, '/') . '/';
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $isHttps,
    ]);
    session_name('srvp_session');
    session_start();

    // ---- Session hardening ------------------------------------------------
    // 1. User-Agent fingerprint: if the UA changes after login, destroy the
    //    session so a different user on the same machine can't inherit it.
    $currentUA = hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? 'cli');
    if (!empty($_SESSION['user_id'])) {
        if (isset($_SESSION['_ua_fp']) && $_SESSION['_ua_fp'] !== $currentUA) {
            // UA mismatch — destroy the old session and start fresh
            $_SESSION = [];
            session_destroy();
            session_start();
        }
    }
    // Store/refresh the fingerprint on every request
    if (empty($_SESSION['_ua_fp'])) {
        $_SESSION['_ua_fp'] = $currentUA;
    }

    // 2. Idle timeout: automatically expire sessions after 8 hours of inactivity
    //    so students don't stay logged in on shared computers indefinitely.
    if (!empty($_SESSION['user_id'])) {
        $idleLimit = 8 * 3600; // 8 hours
        if (isset($_SESSION['_last_active']) && (time() - $_SESSION['_last_active']) > $idleLimit) {
            $_SESSION = [];
            session_destroy();
            session_start();
        }
    }
    $_SESSION['_last_active'] = time();
}

// ---- Timezone ------------------------------------------------------------
date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Manila'));

// ---- Connection ----------------------------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            if (DB_SSL) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = true;
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            }
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            $isDev = APP_ENV === 'development' || APP_ENV === 'dev';
            exit($isDev ? 'Database connection failed: ' . htmlspecialchars($e->getMessage()) : 'Database connection failed.');
        }
    }
    return $pdo;
}