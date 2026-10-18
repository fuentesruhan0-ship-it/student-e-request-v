<?php
require_once __DIR__ . '/includes/auth.php';

// If already authenticated via Clerk, redirect to appropriate dashboard
$user = current_user();
if ($user) {
    $target = $user['role'] === 'student' ? 'student/dashboard.php' : 'admin/dashboard.php';
    redirect($target);
}

$title = 'Authenticating...';
require __DIR__ . '/includes/header.php';
?>
<section class="auth-wrap">
    <div class="auth-card glass-strong clay" style="text-align:center;padding:3rem 2rem 2.5rem;max-width:440px;">
        <div class="login-loader-stage" style="height:140px;margin-bottom:1.25rem;">
            <div class="loader"></div>
        </div>
        <h2 style="font-size:1.35rem;font-family:var(--f-head);margin-bottom:.5rem;color:#ffffff;">Authenticating…</h2>
        <p class="muted" id="callback-status" style="font-size:.9rem;line-height:1.5;">Securing connection and initializing session, please wait…</p>
        <div style="margin-top:1.5rem;display:flex;justify-content:center;gap:.5rem;">
            <span class="pill pill-info" id="callback-badge">Completing security handshake</span>
        </div>
    </div>
</section>

<script>
document.addEventListener('DOMContentLoaded', async function () {
    let finished = false;
    const statusEl = document.getElementById('callback-status');
    const badgeEl  = document.getElementById('callback-badge');
    const loginTarget = <?= json_encode(url('login.php')) ?>;
    const studentDashboard = <?= json_encode(url('student/dashboard.php')) ?>;
    const adminDashboard = <?= json_encode(url('admin/dashboard.php')) ?>;
    const appBaseUrl    = <?= json_encode(base_url()) ?>;

    // Detect if this callback originated from the register (sign-up) page
    const fromRegister = (document.referrer && document.referrer.indexOf('register') !== -1)
                       || (window.location.search && window.location.search.indexOf('from=register') !== -1)
                       || (document.referrer && document.referrer.indexOf('sign-up') !== -1);

    async function finishAuth(targetUrl) {
        if (finished) return;
        finished = true;
        if (statusEl) statusEl.textContent = 'Redirecting to your portal...';
        if (badgeEl) badgeEl.textContent = 'Session verified';

        let token = null;
        for (let i = 0; i < 35; i++) {
            if (window.Clerk && window.Clerk.session) {
                try {
                    token = await window.Clerk.session.getToken();
                    if (token) break;
                } catch (e) {}
            }
            await new Promise(r => setTimeout(r, 100));
        }

        // Redirect to login.php with token, PHP will handle the dashboard redirect
        let target = loginTarget;
        if (token) {
            document.cookie = '__session=' + encodeURIComponent(token) + '; path=/; SameSite=Lax';
            target += (target.indexOf('?') === -1 ? '?' : '&') + '__session=' + encodeURIComponent(token);
        }
        // Carry registration context so login.php can show appropriate messaging
        if (fromRegister) {
            target += (target.indexOf('?') === -1 ? '?' : '&') + 'from=register';
        }
        window.location.href = target;
    }

    async function processCallback() {
        if (!window.Clerk) {
            await finishAuth(loginTarget);
            return;
        }
        try {
            if (typeof window.Clerk.handleRedirectCallback === 'function') {
                // Pass custom navigate callback that forces redirect back to our portal
                await window.Clerk.handleRedirectCallback({
                    afterSignInUrl: loginTarget,
                    afterSignUpUrl: loginTarget,
                    signInForceRedirectUrl: loginTarget,
                    signUpForceRedirectUrl: loginTarget,
                }, async (toUrl) => {
                    await finishAuth(loginTarget);
                });
            }
        } catch (err) {
            console.warn('Clerk handleRedirectCallback notice:', err);
        }
        await finishAuth(loginTarget);
    }

    // Ensure window.Clerk is fully loaded before invoking callback handlers
    const maxWait = 100; // up to 10s
    for (let i = 0; i < maxWait; i++) {
        if (window.Clerk && window.Clerk.loaded) break;
        if (window.Clerk && !window.Clerk.loaded && typeof window.Clerk.load === 'function') {
            try {
                await window.Clerk.load({
                    publishableKey: <?= json_encode(CLERK_PUBLISHABLE_KEY) ?>,
                    afterSignInUrl: loginTarget,
                    afterSignUpUrl: loginTarget,
                    signInForceRedirectUrl: loginTarget,
                    signUpForceRedirectUrl: loginTarget,
                });
                if (window.Clerk.loaded) break;
            } catch (e) {}
        }
        await new Promise(r => setTimeout(r, 100));
    }

    if (window.Clerk && window.Clerk.loaded) {
        await processCallback();
    } else {
        await finishAuth(loginTarget);
    }
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
