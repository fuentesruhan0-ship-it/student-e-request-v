<?php
require_once __DIR__ . '/includes/auth.php';

$wasClerk = !empty($_SESSION['auth_provider']) && $_SESSION['auth_provider'] === 'clerk';
if (isset($_COOKIE['__session'])) {
    setcookie('__session', '', time() - 3600, '/');
}
logout_user();

// If Clerk was used, show a clean sign-out transition with the bouncing ball loader
// to guarantee Clerk client tokens in localStorage are cleared before going to login.
if ($wasClerk && clerk_enabled()):
    $title = 'Signing out...';
    require __DIR__ . '/includes/header.php';
?>
<section class="auth-wrap">
    <div class="auth-card glass-strong clay" style="text-align:center;padding:3rem 2rem 2.5rem;max-width:440px;margin:3rem auto;">
        <div class="login-loader-stage">
            <div class="loader"></div>
        </div>
        <h2 style="font-size:1.35rem;margin-bottom:.5rem;">Signing out…</h2>
        <p class="muted" id="signout-status" style="font-size:.9rem;line-height:1.5;">Ending session and securing your account…</p>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', function () {
    let done = false;
    const dest = <?= json_encode(url('login.php')) ?>;
    function finish() {
        if (done) return;
        done = true;
        window.location.href = dest;
    }

    async function executeSignOut() {
        try {
            sessionStorage.removeItem('clerk_sync_in_progress');
            document.cookie = '__session=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT; SameSite=Lax';
        } catch (e) {}
        if (window.Clerk && typeof window.Clerk.signOut === 'function') {
            try {
                await window.Clerk.signOut();
            } catch (e) {}
        }
        finish();
    }

    if (window.Clerk && window.Clerk.loaded) {
        executeSignOut();
    } else {
        const timer = setInterval(() => {
            if (window.Clerk && window.Clerk.loaded) {
                clearInterval(timer);
                executeSignOut();
            }
        }, 100);
        setTimeout(() => {
            clearInterval(timer);
            finish();
        }, 1200);
    }
});
</script>
<?php
    require __DIR__ . '/includes/footer.php';
    exit;
endif;

redirect('login.php');
