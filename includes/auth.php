<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
const ROLE_TABLES = [
    'student'   => ['table' => 'students',   'id_col' => 'student_no',  'label' => 'Student'],
    'registrar' => ['table' => 'registrars', 'id_col' => 'employee_no', 'label' => 'Registrar'],
    'cashier'   => ['table' => 'cashiers',   'id_col' => 'employee_no', 'label' => 'Cashier'],
];

const VALID_ROLES = ['student', 'registrar', 'cashier'];
const ADMIN_ROLES = ['registrar', 'cashier'];

require_once __DIR__ . '/clerk.php';

if (clerk_enabled()) {
    clerk_try_authenticate();
}

/**
 * Detect a user's role from their submitted ID string.
 *
 *   CSH-*   -> cashier
 *   REG-*   -> registrar
 *   other   -> student (format YYYY-NNNNN is validated separately in register.php)
 *
 * Single source of truth — used by both register.php and any admin-side
 * "quick create" forms. Always returns a VALID_ROLES entry.
 */
function detect_role_from_id(string $id): string
{
    $clean = strtoupper(trim($id));
    if (str_starts_with($clean, 'CSH-')) return 'cashier';
    if (str_starts_with($clean, 'REG-')) return 'registrar';
    return 'student';
}

/**
 * Human-readable label for a role constant ("student" → "Student").
 */
function role_label(string $role): string
{
    return ROLE_TABLES[$role]['label'] ?? ucfirst($role);
}

function current_user(): ?array
{
    static $cache = null;

    $uid  = (int) ($_SESSION['user_id'] ?? 0);
    $role = (string) ($_SESSION['user_type'] ?? $_SESSION['role'] ?? '');

    if ($uid <= 0 || !in_array($role, VALID_ROLES, true)) {
        return null;
    }
    if ($cache !== null && $cache['id'] === $uid && $cache['role'] === $role) {
        return $cache;
    }
    if (!isset(ROLE_TABLES[$role])) {
        return null;
    }

    $map   = ROLE_TABLES[$role];
    $table = $map['table'];

    $sql = $role === 'student'
        ? "SELECT id, student_no, full_name, email, course, year_level, created_at FROM students WHERE id = ?"
        : "SELECT id, employee_no AS student_no, full_name, email, created_at FROM $table WHERE id = ?";

    $stmt = db()->prepare($sql);
    $stmt->execute([$uid]);
    $user = $stmt->fetch();

    if ($user) {
        $user['role']       = $role;
        $user['role_label'] = role_label($role);
    }
    $cache = $user ?: null;
    return $cache;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        $target = $_SERVER['REQUEST_URI'] ?? '';
        if ($target && $target !== '/' && !str_contains($target, 'login')) {
            $_SESSION['login_redirect'] = $target;
        }
        flash_set('error', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $user;
}

function require_admin(): array
{
    $user = require_login();
    if (!in_array($user['role'], ADMIN_ROLES, true)) {
        flash_set('error', 'Staff access required.');
        redirect('student/dashboard.php');
    }
    return $user;
}

function require_registrar(): array
{
    $user = require_login();
    if ($user['role'] !== 'registrar') {
        flash_set('error', 'Registrar access required.');
        redirect('admin/dashboard.php');
    }
    return $user;
}

function require_cashier(): array
{
    $user = require_login();
    if ($user['role'] !== 'cashier') {
        flash_set('error', 'Cashier access required.');
        redirect('admin/dashboard.php');
    }
    return $user;
}

function require_student(): array
{
    $user = require_login();
    if ($user['role'] !== 'student') {
        redirect('admin/dashboard.php');
    }
    return $user;
}

/**
 * Attempt a login. Returns true on success and bootstraps the session with
 * every piece of role info the rest of the app relies on.
 *
 * The identifier may be a student_no / employee_no OR a Gmail address —
 * both are looked up in each role table, so students can sign in with
 * their ID even though their email is required at registration.
 */
function attempt_login(string $identifier, string $password): bool
{
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') {
        return false;
    }

    foreach (ROLE_TABLES as $role => $map) {
        $table = $map['table'];
        $idCol = $map['id_col'];
        $stmt = db()->prepare(
            "SELECT id, password_hash, full_name, email, $idCol AS login_id
             FROM $table WHERE ($idCol = ? OR email = ?) LIMIT 1"
        );
        $stmt->execute([$identifier, $identifier]);
        $row = $stmt->fetch();

        if ($row && is_string($row['password_hash']) && password_verify($password, $row['password_hash'])) {
            session_regenerate_id(true);

            $_SESSION = [];
            $_SESSION['user_id']      = (int) $row['id'];
            $_SESSION['user_type']    = $role;
            $_SESSION['role']         = $role;
            $_SESSION['role_label']   = role_label($role);
            $_SESSION['login_id']     = (string) $row['login_id'];
            $_SESSION['login_email']  = (string) $row['email'];
            $_SESSION['login_name']   = (string) $row['full_name'];
            $_SESSION['login_at']     = time();
            $_SESSION['login_ip']     = $_SERVER['REMOTE_ADDR'] ?? 'cli';
            $_SESSION['csrf']         = bin2hex(random_bytes(32));

            return true;
        }
    }

    return false;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}