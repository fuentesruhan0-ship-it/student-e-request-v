<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user()) {
    $u = current_user();
    redirect($u['role'] === 'student' ? 'student/dashboard.php' : 'admin/dashboard.php');
}

$useClerk = clerk_enabled();

$errors = [];
$old = [
    'full_name'  => '',
    'email'      => '',
    'student_no' => '',
    'course'     => '',
    'year_level' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($old as $k => $v) $old[$k] = trim((string) ($_POST[$k] ?? ''));
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['password_confirm'] ?? '';

    $submittedId = $old['student_no'];
    $role        = detect_role_from_id($submittedId);

    // ---- Validation --------------------------------------------------------
    if ($old['full_name'] === '' || mb_strlen($old['full_name']) < 3)
        $errors[] = 'Enter your full name (3+ characters).';

    // Gmail enforcement
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL))
        $errors[] = 'Enter a valid email address.';
    elseif (!str_ends_with(strtolower($old['email']), '@gmail.com'))
        $errors[] = 'You must use a @gmail.com address to register.';

    // ID format validation per role
    if ($role === 'student') {
        // Student no: YYYY-NNNNN  (4-digit year, dash, 5+ digits)
        if (!preg_match('/^\d{4}-\d{5,}$/', $submittedId))
            $errors[] = 'Student number must follow the format YYYY-NNNNN (e.g. 2024-10001).';
        if ($old['course'] === '')
            $errors[] = 'Enter your course.';
        if (!in_array($old['year_level'], ['1','2','3','4','5'], true))
            $errors[] = 'Select your year level.';
    } elseif ($role === 'cashier') {
        // Cashier employee_no: CSH-XXXXX
        if (!preg_match('/^CSH-[A-Za-z0-9]{3,}$/i', $submittedId))
            $errors[] = 'Cashier employee number must follow the format CSH-XXXXX (e.g. CSH-00001).';
    } elseif ($role === 'registrar') {
        // Registrar employee_no: REG-XXXXX
        if (!preg_match('/^REG-[A-Za-z0-9]{3,}$/i', $submittedId))
            $errors[] = 'Registrar employee number must follow the format REG-XXXXX (e.g. REG-00001).';
    }

    if (strlen($password) < 8)  $errors[] = 'Password must be 8+ characters.';
    if ($password !== $confirm)  $errors[] = 'Passwords do not match.';

    // ---- Duplicate check ---------------------------------------------------
    if (!$errors) {
        if ($role === 'student') {
            $stmt = db()->prepare('SELECT id FROM students WHERE email = ? OR student_no = ? LIMIT 1');
            $stmt->execute([$old['email'], $submittedId]);
        } elseif ($role === 'cashier') {
            $stmt = db()->prepare('SELECT id FROM cashiers WHERE email = ? OR employee_no = ? LIMIT 1');
            $stmt->execute([$old['email'], strtoupper($submittedId)]);
        } else {
            $stmt = db()->prepare('SELECT id FROM registrars WHERE email = ? OR employee_no = ? LIMIT 1');
            $stmt->execute([$old['email'], strtoupper($submittedId)]);
        }
        if ($stmt->fetch()) $errors[] = 'Email or ID number is already registered.';
    }

    // ---- Insert ------------------------------------------------------------
    if (!$errors) {
        $hash = password_hash($password, PASSWORD_DEFAULT);

        if ($role === 'student') {
            $stmt = db()->prepare(
                'INSERT INTO students (student_no, full_name, email, password_hash, course, year_level)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $submittedId, $old['full_name'], $old['email'],
                $hash, $old['course'], (int) $old['year_level'],
            ]);
        } elseif ($role === 'cashier') {
            $stmt = db()->prepare(
                'INSERT INTO cashiers (employee_no, full_name, email, password_hash)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([strtoupper($submittedId), $old['full_name'], $old['email'], $hash]);
        } else {
            $stmt = db()->prepare(
                'INSERT INTO registrars (employee_no, full_name, email, password_hash)
                 VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([strtoupper($submittedId), $old['full_name'], $old['email'], $hash]);
        }

        flash_set('success', 'Account created! You can now sign in.');
        redirect('login.php');
    }
}

$title = 'Create account';
require __DIR__ . '/includes/header.php';
?>

<section class="auth-wrap">
    <div class="auth-card glass-strong clay auth-card-wide">
        <div class="auth-head">
            <span class="brand-mark brand-mark-lg"><?= icon('graduation', 'ic ic-lg') ?></span>
            <h1>Create your account</h1>
            <p class="muted">Register with your school ID and Gmail address.</p>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error glass">
                <?= icon('alert') ?>
                <?php if (count($errors) === 1): ?>
                    <span><?= e($errors[0]) ?></span>
                <?php else: ?>
                    <ul style="margin:0;padding-left:1.25rem;">
                        <?php foreach ($errors as $err): ?>
                            <li><?= e($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
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
                <span id="clerk-google-label">Continue with Google</span>
            </button>
            <button id="clerk-hosted-btn" type="button" class="btn-clerk-alt">
                <?= icon('shield') ?> Continue with email / other providers (Clerk)
            </button>
        </div>
        <div class="auth-divider" role="separator">
            <span class="auth-divider-line"></span>
            <span class="auth-divider-text">or create account manually</span>
            <span class="auth-divider-line"></span>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            function showRegisterLoader(title, desc) {
                const overlay = document.getElementById('register-loading-overlay');
                if (!overlay) return;
                if (title) document.getElementById('register-loader-title').textContent = title;
                if (desc) document.getElementById('register-loader-desc').textContent = desc;
                overlay.style.display = 'flex';
            }
            window.showRegisterLoader = showRegisterLoader;

            window.addEventListener('pageshow', function () {
                const overlay = document.getElementById('register-loading-overlay');
                if (overlay) overlay.style.display = 'none';
            });

            const regForm = document.getElementById('register-form');
            if (regForm) {
                regForm.addEventListener('submit', function () {
                    const fn = (document.getElementById('full_name')?.value || '').trim();
                    const em = (document.getElementById('email')?.value || '').trim();
                    if (fn && em) {
                        showRegisterLoader('Creating your account…', 'Registering your details and setting up your personal dashboard…');
                    }
                });
            }

            function waitForClerk(cb, tries) {
                tries = tries || 0;
                if (window.Clerk && window.Clerk.loaded) return cb();
                if (window.Clerk && typeof window.Clerk.addListener === 'function') {
                    try { return window.Clerk.addListener(() => cb()); } catch (e) {}
                }
                if (tries > 60) return;
                setTimeout(() => waitForClerk(cb, tries + 1), 100);
            }

            const hostedBtn   = document.getElementById('clerk-hosted-btn');
            const googleBtn   = document.getElementById('clerk-google-btn');
            const callbackUrl = <?= json_encode(url('sso-callback.php?from=register')) ?>;
            const completeUrl = <?= json_encode(url('login.php')) ?>;

            // "Continue with email / other providers" → open Clerk sign-up modal
            if (hostedBtn) {
                hostedBtn.addEventListener('click', () => {
                    showRegisterLoader('Opening sign-up…', 'Connecting to authentication services…');
                    waitForClerk(() => {
                        if (window.Clerk && typeof window.Clerk.openSignUp === 'function') {
                            window.Clerk.openSignUp({
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
                                const overlay = document.getElementById('register-loading-overlay');
                                if (overlay) overlay.style.display = 'none';
                            }, 1200);
                        } else if (window.Clerk && typeof window.Clerk.openSignIn === 'function') {
                            // Fallback: sign-in modal also allows sign-up
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
                                const overlay = document.getElementById('register-loading-overlay');
                                if (overlay) overlay.style.display = 'none';
                            }, 1200);
                        } else {
                            window.location.href = <?= json_encode(clerk_signup_url()) ?>;
                        }
                    });
                });
            }

            // "Continue with Google" → direct OAuth redirect
            if (googleBtn) {
                googleBtn.addEventListener('click', () => {
                    showRegisterLoader('Connecting with Google…', 'Redirecting to secure Google authentication…');
                    waitForClerk(() => {
                        const doAuth = () => {
                            // Preferred: direct OAuth redirect via signUp (new account flow)
                            if (window.Clerk && window.Clerk.client &&
                                window.Clerk.client.signUp &&
                                typeof window.Clerk.client.signUp.authenticateWithRedirect === 'function') {
                                try {
                                    window.Clerk.client.signUp.authenticateWithRedirect({
                                        strategy: 'oauth_google',
                                        redirectUrl: callbackUrl,
                                        redirectUrlComplete: callbackUrl,
                                    });
                                    return;
                                } catch (e) { /* fall through to signIn fallback */ }
                            }
                            // Fallback: try signIn (Clerk auto-creates accounts via Google either way)
                            if (window.Clerk && window.Clerk.client &&
                                typeof window.Clerk.client.signIn.authenticateWithRedirect === 'function') {
                                try {
                                    window.Clerk.client.signIn.authenticateWithRedirect({
                                        strategy: 'oauth_google',
                                        redirectUrl: callbackUrl,
                                        redirectUrlComplete: callbackUrl,
                                    });
                                    return;
                                } catch (e) { /* fall through to modal */ }
                            }
                            // Fallback: open sign-up modal
                            if (window.Clerk && typeof window.Clerk.openSignUp === 'function') {
                                window.Clerk.openSignUp({
                                    forceRedirectUrl: callbackUrl,
                                    fallbackRedirectUrl: callbackUrl,
                                    signInForceRedirectUrl: callbackUrl,
                                    signUpForceRedirectUrl: callbackUrl,
                                    signInFallbackRedirectUrl: callbackUrl,
                                    signUpFallbackRedirectUrl: callbackUrl,
                                    afterSignInUrl: callbackUrl,
                                    afterSignUpUrl: callbackUrl,
                                });
                            } else {
                                window.location.href = <?= json_encode(clerk_signup_url()) ?>;
                            }
                        };

                        // Sign out any stale Clerk session first
                        if (window.Clerk && window.Clerk.user) {
                            try {
                                window.Clerk.signOut({ redirectUrl: false }).then(doAuth).catch(doAuth);
                            } catch (e) {
                                doAuth();
                            }
                        } else {
                            doAuth();
                        }
                    });
                });
            }
        });
        </script>
        <?php endif; ?>

        <div class="id-guide-grid">
            <div class="id-guide-card" id="card-guide-student" onclick="setRegId('2024-')">
                <div class="id-guide-tag"><?= icon('graduation') ?> Student</div>
                <span class="id-guide-pattern">YYYY-NNNNN</span>
                <span class="id-guide-ex">e.g. 2024-10001</span>
            </div>
            <div class="id-guide-card" id="card-guide-cashier" onclick="setRegId('CSH-')">
                <div class="id-guide-tag" style="color:#34d399;"><?= icon('credit-card') ?> Cashier</div>
                <span class="id-guide-pattern">CSH-XXXXX</span>
                <span class="id-guide-ex">e.g. CSH-00001</span>
            </div>
            <div class="id-guide-card" id="card-guide-registrar" onclick="setRegId('REG-')">
                <div class="id-guide-tag" style="color:#c084fc;"><?= icon('shield') ?> Registrar</div>
                <span class="id-guide-pattern">REG-XXXXX</span>
                <span class="id-guide-ex">e.g. REG-00001</span>
            </div>
        </div>

        <form method="post" action="<?= url('register.php') ?>" class="stack" novalidate id="register-form">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div class="field">
                    <label for="full_name">Full name</label>
                    <div class="input-icon"><?= icon('user') ?>
                        <input id="full_name" name="full_name"
                               value="<?= e($old['full_name']) ?>"
                               placeholder="Juan D. Dela Cruz" required>
                    </div>
                </div>
                <div class="field">
                    <label for="student_no" id="id-label">School ID / Employee No.</label>
                    <div class="input-icon"><?= icon('grid') ?>
                        <input id="student_no" name="student_no"
                               value="<?= e($old['student_no']) ?>"
                               placeholder="YYYY-NNNNN or CSH-XXXXX or REG-XXXXX"
                               style="text-transform:uppercase;font-family:var(--f-mono);font-weight:600;"
                               required oninput="updateRoleHint(this.value)">
                    </div>
                    <div class="role-badge-box">
                        <span class="live-role-badge" id="role-hint">Enter your ID — your role will be detected automatically</span>
                    </div>
                </div>
                <div class="field span2">
                    <label for="email">Gmail address</label>
                    <div class="input-icon"><?= icon('file-text') ?>
                        <input type="email" id="email" name="email"
                               value="<?= e($old['email']) ?>"
                               placeholder="yourname@gmail.com" required>
                    </div>
                    <span class="hint">Only @gmail.com addresses are accepted.</span>
                </div>
                <div class="field span2" id="student-fields" style="<?= (!empty($old['student_no']) && (str_starts_with(strtoupper($old['student_no']), 'CSH-') || str_starts_with(strtoupper($old['student_no']), 'REG-'))) ? 'display:none;' : '' ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="course">Course</label>
                            <div class="input-icon"><?= icon('doc') ?>
                                <input id="course" name="course"
                                       value="<?= e($old['course']) ?>"
                                       placeholder="BS Information Technology">
                            </div>
                        </div>
                        <div class="field">
                            <label for="year_level">Year level</label>
                            <select id="year_level" name="year_level">
                                <option value="" disabled <?= $old['year_level'] === '' ? 'selected' : '' ?>>Choose…</option>
                                <?php foreach (['1'=>'1st Year','2'=>'2nd Year','3'=>'3rd Year','4'=>'4th Year','5'=>'5th Year'] as $v => $label): ?>
                                    <option value="<?= $v ?>" <?= $old['year_level'] === $v ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-icon"><?= icon('shield') ?>
                        <input type="password" id="password" name="password"
                                placeholder="At least 8 characters" required>
                        <button type="button" class="pw-toggle" data-pw-toggle="#password"><?= icon('eye') ?></button>
                    </div>
                </div>
                <div class="field">
                    <label for="password_confirm">Confirm password</label>
                    <div class="input-icon"><?= icon('shield') ?>
                        <input type="password" id="password_confirm" name="password_confirm" required>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-block">Create account <?= icon('check') ?></button>
        </form>
        <p class="auth-alt muted">Already registered? <a href="<?= url('login.php') ?>">Sign in</a></p>
    </div>

    <!-- Register Loading Phase Overlay -->
    <div id="register-loading-overlay" class="login-loader-overlay" aria-hidden="true" style="display:none;">
        <div class="login-loader-modal glass-strong clay">
            <div class="login-loader-stage">
                <div class="loader"></div>
            </div>
            <h3 class="login-loader-title" id="register-loader-title">Setting up…</h3>
            <p class="login-loader-desc" id="register-loader-desc">Please wait while we connect to authentication services.</p>
        </div>
    </div>
</section>

<script>
function setRegId(prefix) {
    const input = document.getElementById('student_no');
    if (!input) return;
    if (!input.value || input.value.trim().length <= 4) {
        input.value = prefix;
    }
    input.focus();
    updateRoleHint(input.value);
}

function updateRoleHint(val) {
    const hint = document.getElementById('role-hint');
    const studentFields = document.getElementById('student-fields');
    const cardStudent = document.getElementById('card-guide-student');
    const cardCashier = document.getElementById('card-guide-cashier');
    const cardRegistrar = document.getElementById('card-guide-registrar');
    const up = (val || '').toUpperCase().trim();

    if (cardStudent) cardStudent.classList.remove('active');
    if (cardCashier) cardCashier.classList.remove('active');
    if (cardRegistrar) cardRegistrar.classList.remove('active');

    if (up.startsWith('CSH-')) {
        hint.className = 'live-role-badge is-cashier';
        hint.textContent = '💳 Detected Role: Cashier Staff (Employee Account)';
        if (studentFields) studentFields.style.display = 'none';
        if (cardCashier) cardCashier.classList.add('active');
    } else if (up.startsWith('REG-')) {
        hint.className = 'live-role-badge is-registrar';
        hint.textContent = '📜 Detected Role: Registrar Staff (Employee Account)';
        if (studentFields) studentFields.style.display = 'none';
        if (cardRegistrar) cardRegistrar.classList.add('active');
    } else if (up.length > 0) {
        hint.className = 'live-role-badge is-student';
        hint.textContent = '🎓 Detected Role: Student — course & year level required below';
        if (studentFields) studentFields.style.display = '';
        if (cardStudent) cardStudent.classList.add('active');
    } else {
        hint.className = 'live-role-badge';
        hint.textContent = 'Role: Student by default (or enter CSH-/REG- for staff accounts)';
        if (studentFields) studentFields.style.display = '';
    }
}
// Run on page load in case the field has a value (browser autofill / form error)
updateRoleHint(document.getElementById('student_no').value);
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>