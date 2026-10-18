<?php
if (!isset($mode)) {
    $self = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $mode = (str_contains($self, '/student/') || str_contains($self, '/admin/')) ? 'app' : 'public';
}
require_once __DIR__ . '/auth.php';

$user       = ($mode === 'app') ? require_login() : current_user();
$pageTitle  = isset($title) ? $title . ' · ' . APP_NAME : APP_NAME;
$flash      = flash_take();
$isStudent  = $user && $user['role'] === 'student';
$isRegistrar= $user && $user['role'] === 'registrar';
$isCashier  = $user && $user['role'] === 'cashier';
$scriptBase = basename($_SERVER['SCRIPT_NAME'] ?? '');
$phpSelf    = $_SERVER['PHP_SELF'] ?? '';
$useClerk   = clerk_enabled();
$clerkPk    = CLERK_PUBLISHABLE_KEY;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="color-scheme" content="dark">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="<?= url('assets/style.css') ?>">
<link rel="stylesheet" href="<?= url('assets/glass.css') ?>">
<link rel="stylesheet" href="<?= url('assets/clay.css') ?>">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="6" fill="#ffffff"/><path d="M6 13.5 11 18l7-11" stroke="#0a0a0a" stroke-width="2.4" fill="none" stroke-linecap="round" stroke-linejoin="round"/></svg>') ?>">
<?php if ($useClerk): 
    $fapi = rtrim(clerk_frontend_api() ?: '', '/');
    $clerkScriptSrc = $fapi !== '' 
        ? "{$fapi}/npm/@clerk/clerk-js@5/dist/clerk.browser.js"
        : "https://cdn.jsdelivr.net/npm/@clerk/clerk-js@5/dist/clerk.browser.js";
?>
<script
    async
    crossorigin="anonymous"
    data-clerk-publishable-key="<?= e($clerkPk) ?>"
    src="<?= e($clerkScriptSrc) ?>"
    type="text/javascript">
</script>
<script>
window.addEventListener('load', async function () {
    if (!window.Clerk) return;
    const pk = "<?= e($clerkPk) ?>";
    if (!window.Clerk.loaded) {
        try {
            await window.Clerk.load({
                publishableKey: pk,
                afterSignInUrl: "<?= e(url('sso-callback.php')) ?>",
                afterSignUpUrl: "<?= e(url('sso-callback.php')) ?>",
                signInForceRedirectUrl: "<?= e(url('sso-callback.php')) ?>",
                signUpForceRedirectUrl: "<?= e(url('sso-callback.php')) ?>",
            });
        } catch (e) {
            console.debug('Clerk init note:', e);
        }
    }

    const syncClerkSession = async () => {
        if (!window.Clerk || !window.Clerk.session) return;
        try {
            const token = await window.Clerk.session.getToken();
            if (token) {
                document.cookie = '__session=' + encodeURIComponent(token) + '; path=/; SameSite=Lax';
                <?php if (!$user && empty($_SESSION['clerk_needs_school_id']) && basename($_SERVER['SCRIPT_NAME'] ?? '') !== 'sso-callback.php'): ?>
                if (!sessionStorage.getItem('clerk_sync_in_progress')) {
                    sessionStorage.setItem('clerk_sync_in_progress', '1');
                    window.location.reload();
                }
                <?php else: ?>
                sessionStorage.removeItem('clerk_sync_in_progress');
                <?php endif; ?>
            }
        } catch (e) {
            console.debug('Clerk token sync:', e);
        }
    };

    if (window.Clerk.loaded) {
        syncClerkSession();
    }
    if (typeof window.Clerk.addListener === 'function') {
        window.Clerk.addListener(() => syncClerkSession());
    }
});
</script>
<?php endif; ?>
</head>
<body class="mode-<?= e($mode) ?>">

<div class="backdrop" aria-hidden="true">
    <div class="orb orb-a"></div><div class="orb orb-b"></div>
    <div class="orb orb-center"></div>
    <div class="orb orb-c"></div><div class="orb orb-d"></div>
    <div class="grid-overlay"></div>
</div>

<header class="topbar glass">
    <div class="topbar-inner">
        <a class="brand" href="<?= $user ? ($isStudent ? url('student/dashboard.php') : url('admin/dashboard.php')) : url('index.php') ?>">
            <span class="brand-mark"><?= icon('graduation', 'ic ic-lg') ?></span>
            <span class="brand-text">SRVP<small>Student Portal</small></span>
        </a>
        <input type="checkbox" id="nav-toggle" class="nav-toggle" aria-label="Toggle navigation">
        <label for="nav-toggle" class="nav-burger" aria-hidden="true"><?= icon('menu', 'ic') ?></label>
        <nav class="topnav">
            <?php if ($mode === 'public' || $mode === 'auth'): ?>
                <a href="<?= url('index.php') ?>" class="<?= $scriptBase === 'index.php' ? 'active' : '' ?>"><?= icon('home') ?> Home</a>
                <a href="<?= url('index.php') ?>#services"><?= icon('grid') ?> Services</a>
                <?php if ($user): ?>
                    <a class="btn btn-primary btn-sm" href="<?= $isStudent ? url('student/dashboard.php') : url('admin/dashboard.php') ?>"><?= icon('grid') ?> Dashboard</a>
                    <a class="btn btn-ghost btn-sm" href="<?= url('logout.php') ?>"
                       data-confirm-title="Sign out?" data-confirm="Are you sure you want to sign out?"
                       data-confirm-yes="Yes, sign out" data-confirm-no="No, stay"><?= icon('logout') ?> Sign out</a>
                <?php else: ?>
                    <a href="<?= url('login.php') ?>"><?= icon('login') ?> Sign in</a>
                    <a class="btn btn-primary btn-sm" href="<?= url('register.php') ?>"><?= icon('user') ?> Create account</a>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($isStudent): ?>
                    <a href="<?= url('student/dashboard.php') ?>" class="<?= str_contains($phpSelf, 'student/dashboard') ? 'active' : '' ?>"><?= icon('grid') ?> Dashboard</a>
                    <a href="<?= url('student/services.php') ?>" class="<?= str_contains($phpSelf, 'student/services') ? 'active' : '' ?>"><?= icon('doc') ?> Services</a>
                    <a href="<?= url('student/requests.php') ?>" class="<?= str_contains($phpSelf, 'student/requests') ? 'active' : '' ?>"><?= icon('list') ?> My Requests</a>
                    <a href="<?= url('student/payment.php') ?>"  class="<?= str_contains($phpSelf, 'student/payment') ? 'active' : '' ?>"><?= icon('wallet') ?> Payments</a>
                    <a href="<?= url('student/instructions.php') ?>" class="<?= str_contains($phpSelf, 'student/instructions') ? 'active' : '' ?>"><?= icon('book') ?> Instructions</a>
                    <a href="<?= url('student/profile.php') ?>"  class="<?= str_contains($phpSelf, 'student/profile') ? 'active' : '' ?>"><?= icon('user') ?> Profile</a>
                <?php elseif ($isRegistrar): ?>
                    <a href="<?= url('admin/dashboard.php') ?>" class="<?= str_contains($phpSelf, 'admin/dashboard') ? 'active' : '' ?>"><?= icon('grid') ?> Dashboard</a>
                    <a href="<?= url('admin/requests.php') ?>"  class="<?= str_contains($phpSelf, 'admin/requests') || str_contains($phpSelf, 'admin/process') ? 'active' : '' ?>"><?= icon('list') ?> Requests</a>
                    <a href="<?= url('admin/payments.php') ?>"  class="<?= str_contains($phpSelf, 'admin/payments') ? 'active' : '' ?>" title="Payments & Reports"><?= icon('receipt') ?> Payments</a>
                    <a href="<?= url('admin/services.php') ?>"  class="<?= str_contains($phpSelf, 'admin/services') ? 'active' : '' ?>"><?= icon('settings') ?> Services</a>
                    <a href="<?= url('admin/instructions.php') ?>" class="<?= str_contains($phpSelf, 'admin/instructions') ? 'active' : '' ?>"><?= icon('book') ?> Instructions</a>
                    <a href="<?= url('admin/audit.php') ?>"     class="<?= str_contains($phpSelf, 'admin/audit') ? 'active' : '' ?>"><?= icon('shield') ?> Audit Log</a>
                <?php elseif ($isCashier): ?>
                    <a href="<?= url('admin/dashboard.php') ?>" class="<?= str_contains($phpSelf, 'admin/dashboard') ? 'active' : '' ?>"><?= icon('grid') ?> Dashboard</a>
                    <a href="<?= url('admin/payments.php') ?>"  class="<?= str_contains($phpSelf, 'admin/payments') ? 'active' : '' ?>"><?= icon('wallet') ?> Payments</a>
                    <a href="<?= url('admin/requests.php') ?>"  class="<?= str_contains($phpSelf, 'admin/requests') ? 'active' : '' ?>"><?= icon('list') ?> Requests</a>
                    <a href="<?= url('admin/audit.php') ?>"     class="<?= str_contains($phpSelf, 'admin/audit') ? 'active' : '' ?>"><?= icon('shield') ?> Audit Log</a>
                <?php endif; ?>
                <span class="user-chip" title="Logged in as: <?= e($user['full_name'] ?? 'User') ?> (<?= e($user['student_no'] ?? '') ?>) · <?= e($user['email'] ?? '') ?>">
                    <span class="avatar"><?= e(strtoupper(mb_substr($user['full_name'] ?? 'U', 0, 1))) ?></span>
                    <span class="user-chip-text">
                        <strong><?= e($user['full_name'] ?? 'User') ?></strong>
                        <small><?= e(ucfirst($user['role'] ?? '')) ?></small>
                    </span>
                </span>
                <a class="btn btn-ghost btn-sm" href="<?= url('logout.php') ?>" title="Sign out"
                   data-confirm-title="Sign out?" data-confirm="Are you sure you want to sign out?"
                   data-confirm-yes="Yes, sign out" data-confirm-no="No, stay"><?= icon('logout') ?><span class="hide-sm">Sign out</span></a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<?php if ($flash): ?>
<div class="container">
    <div class="alert alert-<?= e($flash['type']) ?> glass" role="status">
        <?= icon($flash['type'] === 'error' ? 'alert' : 'check') ?>
        <span><?= e($flash['message']) ?></span>
        <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Dismiss">&times;</button>
    </div>
</div>
<?php endif; ?>

<main class="main">