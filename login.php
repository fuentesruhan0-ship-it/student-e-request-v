<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    $u = current_user();
    redirect($u['role'] === 'student' ? 'student/dashboard.php' : 'admin/dashboard.php');
}

$useClerk = clerk_enabled();
$fromRegister = isset($_GET['from']) && $_GET['from'] === 'register';

if ($useClerk) {
    $needId = $_SESSION['clerk_needs_school_id'] ?? null;
    if ($needId && is_array($needId)) {
        $errors = [];
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            csrf_check();
            $schoolId = strtoupper(trim((string) ($_POST['school_id'] ?? '')));
            $course   = trim((string) ($_POST['course'] ?? ''));
            $year     = (int) ($_POST['year_level'] ?? 0);
            $role     = detect_role_from_id($schoolId);

            if ($role === 'cashier' && !preg_match('/^CSH-[A-Za-z0-9]{3,}$/', $schoolId))
                $errors[] = 'Cashier employee number must be CSH-XXXXX (e.g. CSH-00001).';
            elseif ($role === 'registrar' && !preg_match('/^REG-[A-Za-z0-9]{3,}$/', $schoolId))
                $errors[] = 'Registrar employee number must be REG-XXXXX (e.g. REG-00001).';
            elseif ($role === 'student') {
                if (!preg_match('/^\d{4}-\d{5,}$/', $schoolId))
                    $errors[] = 'Student number must follow YYYY-NNNNN (e.g. 2024-10001).';
                if ($course === '')
                    $errors[] = 'Enter your course.';
                if (!in_array($year, [1,2,3,4,5], true))
                    $errors[] = 'Select your year level.';
            }

            if (!$errors) {
                $map = ROLE_TABLES[$role];
                $table = $map['table'];
                $db = db();
                $stmt = $db->prepare("SELECT id FROM $table WHERE email = ? OR " . $map['id_col'] . " = ? LIMIT 1");
                $stmt->execute([$needId['clerk_email'], $schoolId]);
                if ($stmt->fetch()) {
                    $errors[] = 'That email or school ID already has an account. Please try again or contact the registrar.';
                }
            }

            if (!$errors) {
                $email = $needId['clerk_email'];
                $name  = $needId['clerk_name'];
                $hash  = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $clerkUserId = (string) $needId['clerk_user_id'];
                if ($role === 'student') {
                    $stmt = $db->prepare('INSERT INTO students (student_no, full_name, email, password_hash, clerk_user_id, course, year_level) VALUES (?, ?, ?, ?, ?, ?, ?)');
                    $stmt->execute([$schoolId, $name, $email, $hash, $clerkUserId, $course, $year]);
                } elseif ($role === 'cashier') {
                    $stmt = $db->prepare('INSERT INTO cashiers (employee_no, full_name, email, password_hash, clerk_user_id) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$schoolId, $name, $email, $hash, $clerkUserId]);
                } else {
                    $stmt = $db->prepare('INSERT INTO registrars (employee_no, full_name, email, password_hash, clerk_user_id) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$schoolId, $name, $email, $hash, $clerkUserId]);
                }
                $localId = (int) $db->lastInsertId();

                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['user_id']        = $localId;
                $_SESSION['user_type']      = $role;
                $_SESSION['role']           = $role;
                $_SESSION['role_label']     = role_label($role);
                $_SESSION['login_id']       = $schoolId;
                $_SESSION['login_email']    = $email;
                $_SESSION['login_name']     = $name;
                $_SESSION['login_at']       = time();
                $_SESSION['login_ip']       = $_SERVER['REMOTE_ADDR'] ?? 'cli';
                $_SESSION['csrf']           = bin2hex(random_bytes(32));
                $_SESSION['clerk_user_id']  = $clerkUserId;
                $_SESSION['auth_provider']  = 'clerk';
                unset($_SESSION['clerk_needs_school_id']);

                flash_set('success', "Welcome, $name! Your account is now linked to your $role ID.");
                redirect($role === 'student' ? 'student/dashboard.php' : 'admin/dashboard.php');
            }
        }

        $title = 'Link Your Institutional ID';
        require __DIR__ . '/includes/header.php';
        ?>
        <section class="auth-wrap">
            <div class="auth-card glass-strong clay auth-card-wide">
                <div class="oauth-verified-banner">
                    <span class="badge-dot-live"></span>
                    <span>Google Authentication Verified</span>
                </div>

                <div class="auth-head">
                    <span class="brand-mark brand-mark-lg"><?= icon('graduation', 'ic ic-lg') ?></span>
                    <?php if ($fromRegister): ?>
                        <h1>Complete Your Registration</h1>
                        <p class="muted">
                            Your Google account is verified! Enter your school or employee ID to finish creating your account.
                        </p>
                    <?php else: ?>
                        <h1>Link Your School ID</h1>
                        <p class="muted">
                            Welcome to the SRVP Portal. Complete this quick one-time step to link your official academic or staff profile.
                        </p>
                    <?php endif; ?>
                </div>

                <!-- Verified Identity Plate -->
                <div class="oauth-identity-plate">
                    <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                        <div class="oauth-avatar-wrap">
                            <?= icon('user', 'ic') ?>
                        </div>
                        <div class="oauth-user-details">
                            <div class="oauth-user-name"><?= e($needId['clerk_name']) ?></div>
                            <div class="oauth-user-email">
                                <svg width="14" height="14" viewBox="0 0 48 48" aria-hidden="true" style="flex-shrink:0;">
                                    <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.9 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.1 7.9 3l5.7-5.7C33.9 5.6 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/>
                                    <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.8 1.1 7.9 3l5.7-5.7C33.9 5.6 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                                    <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2c-2 1.4-4.5 2.4-7.2 2.4-5.3 0-9.7-3.1-11.3-7.5l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                                    <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.2-4.1 5.6l6.2 5.2C41 35.2 44 30 44 24c0-1.3-.1-2.3-.4-3.5z"/>
                                </svg>
                                <span><?= e($needId['clerk_email']) ?></span>
                            </div>
                        </div>
                    </div>
                    <span class="badge-verified"><?= icon('check') ?> Connected</span>
                </div>

                <!-- Why is this required explainer -->
                <div class="link-explainer-card">
                    <h4><?= icon('info') ?> Why is an Institutional ID required?</h4>
                    <p>
                        SRVP manages official transcripts, certifications, clearances, and fee payments.
                        These legal documents must be tied to your school-issued ID. <strong>This is a one-time link</strong> — subsequent sign-ins via Google will take you straight to your dashboard.
                    </p>
                </div>

                <?php if ($errors): ?>
                    <div class="alert alert-error glass" style="margin-bottom:18px;"><span><?= e($errors[0]) ?></span></div>
                <?php endif; ?>

                <!-- Interactive ID Guide Grid -->
                <div class="id-guide-grid">
                    <div class="id-guide-card" id="card-guide-student" onclick="setSampleId('2024-')">
                        <div class="id-guide-tag"><?= icon('graduation') ?> Student</div>
                        <span class="id-guide-pattern">YYYY-NNNNN</span>
                        <span class="id-guide-ex">e.g. 2024-10001</span>
                    </div>
                    <div class="id-guide-card" id="card-guide-cashier" onclick="setSampleId('CSH-')">
                        <div class="id-guide-tag" style="color:#34d399;"><?= icon('credit-card') ?> Cashier</div>
                        <span class="id-guide-pattern">CSH-XXXXX</span>
                        <span class="id-guide-ex">e.g. CSH-00001</span>
                    </div>
                    <div class="id-guide-card" id="card-guide-registrar" onclick="setSampleId('REG-')">
                        <div class="id-guide-tag" style="color:#c084fc;"><?= icon('shield') ?> Registrar</div>
                        <span class="id-guide-pattern">REG-XXXXX</span>
                        <span class="id-guide-ex">e.g. REG-00001</span>
                    </div>
                </div>

                <form method="post" class="stack" id="clerk-link-form">
                    <?= csrf_field() ?>
                    <div class="form-grid">
                        <div class="field span2">
                            <label for="school_id">School ID / Employee No.</label>
                            <div class="input-icon"><?= icon('grid') ?>
                                <input id="school_id" name="school_id" value="<?= e($_POST['school_id'] ?? '') ?>"
                                       placeholder="2024-10001 or CSH-00001 or REG-00001"
                                       style="text-transform:uppercase;font-family:var(--f-mono);font-weight:600;"
                                       required oninput="updateStudentFields(this.value)">
                            </div>
                            <div class="role-badge-box">
                                <span id="clerk-role-badge" class="live-role-badge">Enter your ID — your role will be detected automatically</span>
                            </div>
                        </div>

                        <div class="field span2" id="student-only-fields" style="<?= (!empty($_POST['school_id']) && (str_starts_with(strtoupper($_POST['school_id']), 'CSH-') || str_starts_with(strtoupper($_POST['school_id']), 'REG-'))) ? 'display:none;' : '' ?>">
                            <div class="form-grid">
                                <div class="field">
                                    <label for="course">Academic Program / Course</label>
                                    <div class="input-icon"><?= icon('doc') ?>
                                        <input id="course" name="course" value="<?= e($_POST['course'] ?? '') ?>" placeholder="e.g. BS Information Technology">
                                    </div>
                                </div>
                                <div class="field">
                                    <label for="year_level">Current Year Level</label>
                                    <select id="year_level" name="year_level">
                                        <option value="" disabled selected>Select year level…</option>
                                        <?php foreach (['1'=>'1st Year','2'=>'2nd Year','3'=>'3rd Year','4'=>'4th Year','5'=>'5th Year'] as $v=>$l): ?>
                                            <option value="<?= $v ?>" <?= (($_POST['year_level'] ?? '') === (string)$v) ? 'selected' : '' ?>><?= e($l) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="panel-actions" style="margin-top:14px;">
                        <button class="btn btn-primary btn-lg" type="submit"><?= icon('check') ?> Link ID & Enter Portal</button>
                        <a class="btn btn-ghost" href="<?= htmlspecialchars(clerk_signout_url()) ?>"><?= icon('user') ?> Switch Google Account</a>
                    </div>
                </form>
            </div>

            <!-- ID Link Loading Overlay -->
            <div id="clerk-link-loading-overlay" class="login-loader-overlay" aria-hidden="true" style="display:none;">
                <div class="login-loader-modal glass-strong clay">
                    <div class="login-loader-stage">
                        <div class="loader"></div>
                    </div>
                    <h3 class="login-loader-title">Linking your account…</h3>
                    <p class="login-loader-desc">Securing your institutional credentials and preparing your dashboard…</p>
                </div>
            </div>
        </section>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const linkForm = document.getElementById('clerk-link-form');
            if (linkForm) {
                linkForm.addEventListener('submit', function () {
                    const sid = (document.getElementById('school_id')?.value || '').trim();
                    if (sid) {
                        const overlay = document.getElementById('clerk-link-loading-overlay');
                        if (overlay) overlay.style.display = 'flex';
                    }
                });
            }
        });

        function setSampleId(prefix) {
            const input = document.getElementById('school_id');
            if (!input) return;
            if (!input.value || input.value.trim().length <= 4) {
                input.value = prefix;
            }
            input.focus();
            updateStudentFields(input.value);
        }

        function updateStudentFields(val) {
            const up = (val || '').toUpperCase().trim();
            const studentFields = document.getElementById('student-only-fields');
            const badge = document.getElementById('clerk-role-badge');
            const cardStudent = document.getElementById('card-guide-student');
            const cardCashier = document.getElementById('card-guide-cashier');
            const cardRegistrar = document.getElementById('card-guide-registrar');

            // Reset card active styles
            if (cardStudent) cardStudent.classList.remove('active');
            if (cardCashier) cardCashier.classList.remove('active');
            if (cardRegistrar) cardRegistrar.classList.remove('active');

            if (up.startsWith('CSH-')) {
                studentFields.style.display = 'none';
                badge.className = 'live-role-badge is-cashier';
                badge.textContent = '💳 Detected Role: Cashier Staff (Employee Account)';
                if (cardCashier) cardCashier.classList.add('active');
            } else if (up.startsWith('REG-')) {
                studentFields.style.display = 'none';
                badge.className = 'live-role-badge is-registrar';
                badge.textContent = '📜 Detected Role: Registrar Staff (Employee Account)';
                if (cardRegistrar) cardRegistrar.classList.add('active');
            } else if (up.length > 0) {
                studentFields.style.display = '';
                badge.className = 'live-role-badge is-student';
                badge.textContent = '🎓 Detected Role: Student — Please provide Course & Year Level below';
                if (cardStudent) cardStudent.classList.add('active');
            } else {
                studentFields.style.display = '';
                badge.className = 'live-role-badge';
                badge.textContent = 'Enter your ID — your role will be detected automatically';
            }
        }
        updateStudentFields(document.getElementById('school_id').value);
        </script>
        <?php require __DIR__ . '/includes/footer.php';
        exit;
    }
}

$errors = [];
$oldId  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $identifier = trim($_POST['identifier'] ?? '');
    $password   = $_POST['password'] ?? '';
    $oldId      = $identifier;

    if ($identifier === '' || $password === '') {
        $errors[] = 'Please fill in both fields.';
    } elseif (attempt_login($identifier, $password)) {
        $user = current_user();
        $where = $user['role'] === 'student' ? 'student/dashboard.php' : 'admin/dashboard.php';
        if (!empty($_SESSION['login_redirect'])) {
            $where = $_SESSION['login_redirect'];
            unset($_SESSION['login_redirect']);
        }
        flash_set('success', 'Welcome back, ' . ($user['full_name'] ?? 'user') . '! — Signed in as ' . role_label($user['role']) . '.');
        redirect($where);
    } else {
        $errors[] = 'Invalid credentials. Check your Gmail / ID and password.';
    }
}

$title = 'Sign in';
require __DIR__ . '/includes/header.php';
?>

<section class="auth-wrap">
    <div class="auth-card glass-strong clay">
        <div class="auth-head">
            <span class="brand-mark brand-mark-lg"><?= icon('graduation', 'ic ic-lg') ?></span>
            <?php if ($fromRegister && $useClerk && !empty($_SESSION['clerk_needs_school_id'])): ?>
                <h1>Complete Your Registration</h1>
                <p class="muted">Link your Google account to your school ID to finish setting up.</p>
            <?php elseif ($fromRegister): ?>
                <h1>Almost there!</h1>
                <p class="muted">Your Google account is verified. Sign in or link your school ID below.</p>
            <?php else: ?>
                <h1>Welcome back</h1>
                <p class="muted">Sign in with your Gmail address or school/employee ID.</p>
            <?php endif; ?>
        </div>
        <?php if ($errors): ?>
            <div class="alert alert-error glass"><span><?= e($errors[0]) ?></span></div>
        <?php endif; ?>

        <?php if ($useClerk): ?>
        <div class="clerk-social-stack" style="display:flex;flex-direction:column;gap:10px;margin-bottom:12px;">
            <button id="clerk-google-btn" type="button" class="btn-google">
                <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true">
                    <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.9 29.3 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.1 7.9 3l5.7-5.7C33.9 5.6 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.3-.4-3.5z"/>
                    <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 16 19 13 24 13c3.1 0 5.8 1.1 7.9 3l5.7-5.7C33.9 5.6 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                    <path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2c-2 1.4-4.5 2.4-7.2 2.4-5.3 0-9.7-3.1-11.3-7.5l-6.5 5C9.5 39.6 16.2 44 24 44z"/>
                    <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.3-2.3 4.2-4.1 5.6l6.2 5.2C41 35.2 44 30 44 24c0-1.3-.1-2.3-.4-3.5z"/>
                </svg>
                <span id="clerk-google-label">Continue with Google (Students)</span>
            </button>
        </div>
        <div class="auth-divider" role="separator">
            <span class="auth-divider-line"></span>
            <span class="auth-divider-text">Staff sign in with password below</span>
            <span class="auth-divider-line"></span>
        </div>
        <?php endif; ?>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            function showLoginLoader(title, desc) {
                const overlay = document.getElementById('login-loading-overlay');
                if (!overlay) return;
                if (title) document.getElementById('login-loader-title').textContent = title;
                if (desc) document.getElementById('login-loader-desc').textContent = desc;
                overlay.style.display = 'flex';
            }
            window.showLoginLoader = showLoginLoader;

            window.addEventListener('pageshow', function () {
                const overlay = document.getElementById('login-loading-overlay');
                if (overlay) overlay.style.display = 'none';
            });

            function waitForClerk(cb, tries) {
                tries = tries || 0;
                if (window.Clerk && window.Clerk.loaded) return cb();
                if (window.Clerk && typeof window.Clerk.addListener === 'function') {
                    try { return window.Clerk.addListener(() => cb()); } catch (e) {}
                }
                if (tries > 60) return;
                setTimeout(() => waitForClerk(cb, tries + 1), 100);
            }
            const hostedBtn = document.getElementById('clerk-hosted-btn');
            const googleBtn = document.getElementById('clerk-google-btn');
            const loginForm = document.getElementById('login-form');
            const callbackUrl = <?= json_encode(url('sso-callback.php')) ?>;
            const completeUrl = <?= json_encode(url('login.php')) ?>;

            if (loginForm) {
                loginForm.addEventListener('submit', function () {
                    const id = document.getElementById('identifier').value.trim();
                    const pw = document.getElementById('password').value;
                    if (id && pw) {
                        showLoginLoader('Signing you in…', 'Verifying your credentials and preparing your dashboard…');
                    }
                });
            }

            if (hostedBtn) {
                hostedBtn.addEventListener('click', () => {
                    showLoginLoader('Opening sign-in…', 'Connecting to authentication services…');
                    waitForClerk(() => {
                        if (window.Clerk && typeof window.Clerk.openSignIn === 'function') {
                            window.Clerk.openSignIn({
                                forceRedirectUrl: callbackUrl,
                                fallbackRedirectUrl: callbackUrl,
                                signInForceRedirectUrl: callbackUrl,
                                signUpForceRedirectUrl: callbackUrl,
                                signInFallbackRedirectUrl: callbackUrl,
                                signUpFallbackRedirectUrl: callbackUrl,
                                afterSignInUrl: callbackUrl,
                                afterSignUpUrl: callbackUrl,
                            });
                            setTimeout(() => {
                                const overlay = document.getElementById('login-loading-overlay');
                                if (overlay) overlay.style.display = 'none';
                            }, 1200);
                        } else {
                            const overlay = document.getElementById('login-loading-overlay');
                            if (overlay) overlay.style.display = 'none';
                            alert('Clerk is still initializing. Please try again in a moment.');
                        }
                    });
                });
            }

            if (googleBtn) {
                googleBtn.addEventListener('click', () => {
                    const callbackUrl = <?= json_encode(url('sso-callback.php')) ?>;
                    const signinUrl   = <?= json_encode(clerk_signin_url()) ?>;
                    const loginUrl    = <?= json_encode(url('login.php')) ?>;

                    // If Clerk isn't wired up at all, say so and bail.
                    if (signinUrl === loginUrl) {
                        alert('Google sign-in is not configured. Please contact the administrator to set up Clerk authentication, or use the password login below.');
                        return;
                    }

                    showLoginLoader('Connecting with Google…', 'Redirecting to secure Google authentication…');

                    // Preferred: start the OAuth flow via the already-loaded Clerk.js.
                    // This does NOT rely on Clerk's hosted /sign-in page (which 404s on
                    // modern *.clerk.accounts.dev instances) — it begins the redirect
                    // straight from this page.
                    const startGoogle = () => {
                        if (window.Clerk && window.Clerk.client &&
                            typeof window.Clerk.client.signIn.authenticateWithRedirect === 'function') {
                            try {
                                window.Clerk.client.signIn.authenticateWithRedirect({
                                    strategy: 'oauth_google',
                                    redirectUrl: callbackUrl,
                                    redirectUrlComplete: callbackUrl,
                                });
                                return;
                            } catch (e) { /* fall through to the hosted page below */ }
                        }
                        // Fallback: Clerk's hosted sign-in modal (client-side, not a URL).
                        if (window.Clerk && typeof window.Clerk.openSignIn === 'function') {
                            window.Clerk.openSignIn({
                                fallbackRedirectUrl: callbackUrl,
                                signInFallbackRedirectUrl: callbackUrl,
                                signUpFallbackRedirectUrl: callbackUrl,
                                afterSignInUrl: callbackUrl,
                                afterSignUpUrl: callbackUrl,
                            });
                            return;
                        }
                        // Last resort: the hosted page URL (may 404 on some instances).
                        window.location.href = signinUrl;
                    };

                    // Wait for Clerk.js to be ready before kicking off the flow.
                    const poll = (tries) => {
                        if (window.Clerk && window.Clerk.loaded) return startGoogle();
                        if (tries > 60) {
                            document.getElementById('login-loading-overlay').style.display = 'none';
                            alert('Google sign-in is still initializing. Please refresh and try again, or use the password login below.');
                            return;
                        }
                        setTimeout(() => poll(tries + 1), 100);
                    };
                    poll(0);
                });
            }
        });
        </script>

        <form method="post" action="<?= url('login.php') ?>" class="stack" id="login-form" novalidate>
            <?= csrf_field() ?>
            <div class="field">
                <label for="identifier">Gmail address or ID number</label>
                <div class="input-icon"><?= icon('user') ?>
                    <input type="text" id="identifier" name="identifier"
                           value="<?= e($oldId) ?>"
                           placeholder="yourname@gmail.com  or  2024-10001"
                           required autofocus>
                </div>
                <span class="hint">
                    Students: Gmail or student no. &nbsp;|&nbsp;
                    Cashiers: Gmail or CSH-XXXXX &nbsp;|&nbsp;
                    Registrars: Gmail or REG-XXXXX
                </span>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="input-icon"><?= icon('shield') ?>
                    <input type="password" id="password" name="password" required>
                    <button type="button" class="pw-toggle" data-pw-toggle="#password"><?= icon('eye') ?></button>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Sign in <?= icon('login') ?></button>
        </form>
        <p class="auth-alt muted">No account yet? <a href="<?= url('register.php') ?>">Create one</a></p>
    </div>

    <!-- Login Loading Phase Overlay -->
    <div id="login-loading-overlay" class="login-loader-overlay" aria-hidden="true" style="display:none;">
        <div class="login-loader-modal glass-strong clay">
            <div class="login-loader-stage">
                <div class="loader"></div>
            </div>
            <h3 class="login-loader-title" id="login-loader-title">Signing you in…</h3>
            <p class="login-loader-desc" id="login-loader-desc">Please wait while we verify your session.</p>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>
