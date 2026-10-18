<?php
/**
 * URL + view helpers. The app lives directly in the web root (e.g. http://localhost/),
 * so links are rooted at "/" and this file normalizes subfolder installs.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

/**
 * Absolute web root with trailing slash, e.g. "/" or "/mysite/" or full URL.
 *
 * If APP_URL is set in env / .env.local (e.g. "https://srvp.vercel.app"),
 * it is used verbatim — required for hosting behind reverse proxies like Vercel,
 * Cloudflare, or an AWS ALB. Otherwise auto-detects from the request context so
 * the app also works from a subfolder on XAMPP.
 */
function base_url(): string
{
    static $base = null;
    if ($base === null) {
        $override = (string) (defined('APP_URL') ? APP_URL : '');
        if ($override !== '') {
            $base = rtrim($override, '/') . '/';
            return $base;
        }
        $scheme =
            ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443))
            ? 'https' : 'http';
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
        if ($host !== '') {
            $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
            $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
            foreach (['/student', '/admin', '/api'] as $sub) {
                if (str_ends_with($dir, $sub)) {
                    $dir = substr($dir, 0, -strlen($sub));
                    break;
                }
            }
            $path = ($dir === '' || $dir === '.') ? '/' : $dir . '/';
            $base = $scheme . '://' . $host . $path;
            return $base;
        }
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        foreach (['/student', '/admin', '/api'] as $sub) {
            if (str_ends_with($dir, $sub)) {
                $dir = substr($dir, 0, -strlen($sub));
                break;
            }
        }
        $base = ($dir === '' || $dir === '.') ? '/' : $dir . '/';
    }
    return $base;
}

function url(string $path = ''): string
{
    if ($path === '' || $path[0] === '#') {
        return base_url();
    }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) {
        return $path;
    }
    return base_url() . ltrim($path, '/');
}

function redirect(string $path): never
{
    $url = (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path)) ? $path : url($path);
    header('Location: ' . $url);
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Format a number as Philippine pesos. */
function peso(float|int|string|null $amount): string
{
    return '₱' . number_format((float) $amount, 2);
}

/** Human friendly date: "Sep 25, 2026 3:45 pm". */
function nice_date(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    return $ts ? date('M j, Y g:i a', $ts) : '—';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = $_POST['csrf'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf'] ?? '', $sent)) {
        http_response_code(419);
        exit('Security token expired or invalid. Please go back and try again.');
    }
}

/** Flash message helpers (survive one redirect). */
function flash_set(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function flash_take(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Tiny SVG icon library (no external dependencies).
 */
function icon(string $name, string $class = 'ic'): string
{
    $paths = [
        'home'      => 'M3 10.5 12 3l9 7.5M5.5 9.5V20h13V9.5',
        'login'     => 'M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3',
        'logout'    => 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
        'grid'      => 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
        'doc'       => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm0 0v6h6M9 15h6M9 11h3',
        'file-text' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm0 0v6h6M9 13h6M9 17h6',
        'wallet'    => 'M20 12V8H6a2 2 0 0 1-2-2 2 2 0 0 1 2-2h12v4M4 6v12a2 2 0 0 0 2 2h14v-4M18 12a2 2 0 0 0 0 4h4v-4z',
        'receipt'   => 'M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1zM8 7h8M8 11h8M8 15h5',
        'check'     => 'M20 6 9 17l-5-5',
        'clock'     => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
        'x'         => 'M18 6 6 18M6 6l12 12',
        'user'      => 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
        'users'     => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
        'shield'    => 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
        'settings'  => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z',
        'trash'     => 'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6',
        'edit'      => 'M17 3a2.83 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z',
        'plus'      => 'M12 5v14M5 12h14',
        'list'      => 'M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01',
        'download'  => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
        'eye'       => 'M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
        'alert'     => 'M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0zM12 9v4M12 17h.01',
        'graduation'=> 'M22 10 12 5 2 10l10 5 10-5zM6 12v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5M22 10v6',
        'star'      => 'M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14l-5-4.87 6.91-1.01z',
        'menu'      => 'M3 6h18M3 12h18M3 18h18',
        'search'    => 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM21 21l-4.35-4.35',
        'money'     => 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
        'calendar'  => 'M19 4H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2zM16 2v4M8 2v4M3 10h18',
        'trend'     => 'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6',
        'info'      => 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 16v-4M12 8h.01',
        'save'      => 'M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2zM17 21v-8H7v8M7 3v5h8',
        'upload'    => 'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12',
        'bell'      => 'M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0',
        'mail'      => 'M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2zM22 6l-10 7L2 6',
        'book'      => 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z',
    ];
    $d = $paths[$name] ?? $paths['info'];
    return '<svg class="' . e($class) . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}

/** Pretty status pill. */
function status_pill(string $status, ?string $customLabel = null): string
{
    $map = [
        'pending'    => ['Pending',    'pill-warn'],
        'processing' => ['Processing', 'pill-info'],
        'paid'       => ['Paid',       'pill-info'],
        'approved'   => ['Approved',   'pill-ok'],
        'ready'      => ['Ready',      'pill-ok'],
        'released'   => ['Released',   'pill-ok'],
        'rejected'   => ['Rejected',   'pill-bad'],
    ];
    $st = strtolower($status);
    $defaultLabel = $map[$st][0] ?? ucfirst($status);
    $class = $map[$st][1] ?? 'pill-info';
    $label = $customLabel ?? $defaultLabel;
    return '<span class="pill ' . $class . '">' . e($label) . '</span>';
}

/** Validate + normalize an uploaded receipt image. Returns relative path or null. */
function save_receipt_upload(array $file, int $userId): ?string
{
    if (!isset($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] <= 0 || $file['size'] > 4 * 1024 * 1024) {
        return null;
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        return null; // not a real image
    }
    $exts = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    $ext  = $exts[$info[2]] ?? null;
    if (!$ext) {
        return null;
    }
    if (!is_dir(UPLOAD_DIR)) {
        @mkdir(UPLOAD_DIR, 0775, true);
    }
    $name = 'receipt_u' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], UPLOAD_DIR . DIRECTORY_SEPARATOR . $name)) {
        return null;
    }
    return UPLOAD_WEB . $name;
}

function audit_log(int $actorId, string $action, string $detail, ?string $actorType = null): void
{
    try {
        if ($actorType === null) {
            $actorType = $_SESSION['user_type'] ?? 'system';
            if (!in_array($actorType, ['student', 'cashier', 'registrar', 'system'], true)) {
                $actorType = 'system';
            }
        }
        $stmt = db()->prepare('INSERT INTO audit_logs (actor_type, student_id, cashier_id, registrar_id, action, detail, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())');
        $studentId = $actorType === 'student' ? $actorId : null;
        $cashierId = $actorType === 'cashier' ? $actorId : null;
        $registrarId = $actorType === 'registrar' ? $actorId : null;
        $stmt->execute([$actorType, $studentId, $cashierId, $registrarId, $action, $detail]);
    } catch (Throwable $e) {
        error_log('audit_log failed: ' . $e->getMessage());
    }
}

function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        try {
            $rows = db()->query('SELECT setting_key, setting_value FROM site_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
            $cache = is_array($rows) ? $rows : [];
        } catch (Throwable) {
            $cache = [];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    $registrarId = null;
    if (($_SESSION['user_type'] ?? '') === 'registrar') {
        $registrarId = (int) ($_SESSION['user_id'] ?? 0) ?: null;
    }
    $stmt = db()->prepare('INSERT INTO site_settings (setting_key, setting_value, registrar_id) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), registrar_id = VALUES(registrar_id)');
    $stmt->execute([$key, $value, $registrarId]);
}

if (!function_exists('detect_role_from_id')) {
    function detect_role_from_id(string $id): string
    {
        $clean = strtoupper(trim($id));
        if (str_starts_with($clean, 'CSH-')) return 'cashier';
        if (str_starts_with($clean, 'REG-')) return 'registrar';
        return 'student';
    }
}

if (!function_exists('role_label')) {
    function role_label(string $role): string
    {
        $map = [
            'student'   => 'Student',
            'registrar' => 'Registrar',
            'cashier'   => 'Cashier',
        ];
        return $map[$role] ?? ucfirst($role);
    }
}
