# SRVP — System Analysis

_Analyzed October 16, 2026 · static review of all source files (no database running)_

## 1. What this system is

**SRVP (Student Request & Payment Portal)** — a plain PHP 8 + MySQL/MariaDB app with no
framework and no Composer dependencies. Glassmorphism dark UI. Dual deployment targets:
local XAMPP isn't the only target — `vercel.json` + `api/index.php` bridge it to Vercel's
PHP runtime.

**Roles & flows**

| Role | Table | ID format | Powers |
|---|---|---|---|
| Student | `students` | `YYYY-NNNNN` | register/login, request docs, pay (simulated gateway or receipt upload), track statuses, printable receipt |
| Cashier | `cashiers` | `CSH-XXXXX` | verify payments/receipts, delete payment records |
| Registrar | `registrars` | `REG-XXXXX` | everything cashier can, plus approve/reject/advance statuses, CRUD services, edit site settings |

**Key subsystems**

- `config/database.php` — env loading (.env.local → .env → defaults), PDO connection, session bootstrap with hardening (UA fingerprint, 8h idle timeout, secure cookies on https), Clerk env constants.
- `includes/auth.php` — role guards (`require_login/admin/registrar/cashier/student`), `attempt_login` over all three tables, audit logging.
- `includes/clerk.php` — hand-rolled Clerk integration: JWKS fetch + RS256 verify via OpenSSL, Clerk Users API calls, local-user upsert/session bootstrap, DB-only fallback when Clerk API is unreachable. Google OAuth for students, password login for staff.
- `includes/functions.php` — url/base_url helpers, `e()` escaping, CSRF helpers, flash messages, SVG icon library, `save_receipt_upload()` validator, settings key-value store.
- Stored procedures & triggers in `database.sql` (`sp_submit_request`, `sp_start_payment`, `sp_process_request`, `sp_payment_summary`; triggers audit inserts and auto-issue receipts when a payment flips to `paid`, plus a guard that blocks reverting paid → pending).
- `mail.php` — swappable mailer: `MAIL_MODE=log` writes to `mail_outbox/mail.log` (works everywhere), SMTP for real sending.
- Payments: `gateway.php` is a **simulated** third-party checkout (mock gateway ref `MOCK-…`); the real-world flow is the manual receipt-upload → cashier verify/decline loop in `student/payment.php` / `admin/process.php`.

## 2. Strengths

This is genuinely careful code for a no-framework PHP app:

- **SQL injection safe everywhere seen** — 100% prepared statements, `PDO::ATTR_EMULATE_PREPARES => false`.
- **XSS safe** — consistent `e()` (htmlspecialchars) at output.
- **CSRF** on every mutating form, token rotated at login, `hash_equals` compare.
- **Session hardening beyond the norm** — UA fingerprint session invalidation, 8-hour idle timeout, `session_regenerate_id()` on privilege transitions, httponly + SameSite=Lax + Secure-when-https cookies, hardening-aware cookie path for subfolder/`/api` installs.
- **Uploads done right** — real image sniffing via `getimagesize`, allow-list of types, 4 MB cap, randomized filenames, execution blocked by hosting headers for `/uploads/*` on Vercel.
- **Ownership/IDOR checks present** where they matter most (receipt upload checks payment-request ownership; payment gateway checks `r.student_id = user`).
- **Path containment guard** in `admin/view-proof.php` (prefix check against `uploads/` + `basename()` on delete).
- **Transactional MySQL work** — sprocs with `FOR UPDATE` row locks, exit handlers, and DB-level triggers that centralize receipt issuance and audit trail; trigger forecloses payment "unpayment".
- **Audit log** of logins (success/fail), status changes, receipt verify/decline, payment deletes.
- Self-healing setup (`setup.php` re-runnable), env-driven config with sane XAMPP defaults, ROBUST fallbacks (Clerk offline → DB fallback; sessions dir unwritable → sys temp).

## 3. Critical findings

### C1 — Reject-default signature fallback in JWT verification (auth bypass risk)
`includes/clerk.php`, `clerk_verify_jwt()`: if OpenSSL signature verification fails
(**returns non-1**) and `CLERK_SECRET_KEY` is set, the code calls
`clerk_fetch_user($userId)` and — if that user exists — **returns the payload as valid**.

A forged, garbage-signed JWT with any known/attacker-chooseable `sub` (here matching an
existing Clerk user ID) is accepted. Combined with `clerk_get_raw_jwt()` reading a token
straight from `$_GET['__session']`, a crafted URL can bootstrap a PHP session as that
Clerk user (students map to local student records). This must be: signature verify or
die, and remove the fetch-user "confirmation" fallback entirely.

### C2 — No issuer/audience validation
Right above the expiry checks sits a literal no-op: the issuer comparison computes a
match and then does nothing (`// issuers can vary; ...`). Tokens from any issuer that
holds a key known to Clerk would verify if the JWKS agreed (they won't normally, but
that's defense-in-depth being explicitly disabled, not just missing). Add validation
that `iss` == your Clerk Frontend API origin and `aud`/`azp` belongs to your app.

### C3 — Token accepted from GET (`$_GET['__session']`)
CLERK tokens belong in `Set-Cookie` headers. Accepting `?__session=` means JWTs end up
in access logs, browser history, Referer headers on subsequent navigations, etc.
`sso-callback.php` *actively* builds such a URL (`?__session=...`). Move to cookie-only.

### C4 — Self-registration as staff with zero approval
`register.php` + `detect_role_from_id()` mean **any visitor can register a cashier or
registrar account** simply by typing an ID starting `CSH-`/`REG-`. Nothing gates this: no
invite flow, no registrar approval, no admin-set seed quota. From there one can
verify/pay receipts and see all student data. This is the #1 business-logic hole to fix.
Recommend: staff accounts provisioned only by an existing registrar (admin quick-create),
or a secret staff-registration link from env, and make `CSH-`/`REG-` unacceptable in the
public register form.

### C5 — Seed credentials shipped in-repo
`database.sql` hardcodes bcrypt hashes for `registrar.admin@gmail.com` / `cashier.admin@gmail.com`
(password `Admin@1234`) and **README documents both**. Rotate these credentials before
any real deployment and move default password generation out of the repo (or force
first-login change). Note MySQL `IF NOT EXISTS`/`INSERT IGNORE` means re-running the
schema **will not** rotate an existing compromised password.

### C6 — No brute-force protection / rate limiting
`attempt_login()` loops all three role tables with no throttling, no lockout, no
CAPTCHA, no sleep/delay — only audit logging of failures. With `@gmail.com` being a
required (and often publicly-known) format, credential stuffing is trivial. Add
per-identifier + per-IP failure tracking with exponential backoff and/or an account
lockout table before production.

## 4. Deployment correctness (Vercel)

The Vercel story is functional-looking but will misbehave in real life:

- **Ephemeral filesystem = broken sessions.** `var/sessions` (or a temp dir) is used as
  the session save path. Vercel function instances are ephemeral — half of your logins
  won't survive across instances. This is a correctness bug, not a preference — pick
  Redis/Upstash, DB-backed sessions, or plain JWT cookies for a serverless target.
- **Ephemeral uploads.** `move_uploaded_file()` writes into `uploads/` per-instance —
  receipt images will silently disappear between invocations. Use S3/R2/object storage,
  or (simpler) store the image bytes in MySQL.
- **`.env.local` handling.** Vercel will not ship `.env.local` as part of the function
  build the way XAMPP reads it — either use Vercel-project env vars or a synced secret
  store. As-is, a local DB with `root`/empty password will simply 500 on deploy
  (`Database connection failed.` generic page = good).
- **No MySQL connectivity from Vercel functions by default** — you need a publicly
  reachable MySQL (PlanetScale-like, Aiven, or RDS behind IPs). Nothing here documents
  that, so first deploy is a garanteed surprise.
- **CSP for `/uploads/(.*)` sets `default-src 'none'`** — the pipeline is fine, but there
  is no equivalent `frame-ancestors`/`sandbox` for the rest of the site; consider a
  site-wide CSP for defense.
- **No HSTS, no `strict-dynamic` CSP on HTML pages, no `X-Frame-Options` for /uploads**
  (only SAMEORIGIN setting for other paths; fine but could be uniform).

## 5. Notable but lower-priority findings

- **`setup.php`, `init.php`, `database.sql` publicly reachable on a live host.**
  `init.php` redirects to setup, but `setup.php` itself is still a runnable endpoint —
  Google it: everyone recommends deleting setup files post-install; this repo even says
  so in a comment that nothing actually enforces. Faff to auto-delete or guard by
  `APP_ENV=development`.
- **`admin/view-proof.php` path check is prefix-only.** `str_starts_with($image,
  'uploads/')` accepts `uploads/../config/.env.local`. There's no actual readfile here
  (it emits an `<img src>`), so it isn't direct disclosure — but it's weaker than it
  looks; use `realpath` + `str_starts_with(UPLOAD_DIR, realpath)`.
- **Static per-session CSRF token.** One token used across the whole session lifetime.
  Acceptable for this scope, but rotate per-request or at least per-form-generation if
  you want stronger web-form defense.
- **Dead code & oddities.** `init.php` has the redirect-then-unreachable block that more
  or less hides a full, older setup script; `header('Location: setup.php')` before
  exiting means none of the body executes — fine, but confusing to maintainers.
- **`icon('arrow-left')` / `icon('credit-card')` don't exist as paths** — `icon()` falls
  back to the `info` icon gracefully; harmless but visible UI mistake.
- **`@vercel/php@0.11.6`** is quite old; Vercel's PHP runtime is legacy and semi-frozen.
  Worth flagging as a risk if you want this long-term on Vercel (think containerization
  or a PHP host like Railway/Fly/Render/regular VPS).
- **No automated tests of any kind**, no CI. Everything is eyeball-driven. Given the
  payment money path, even a few PHPUnit smoke/happy-path tests on `attempt_login`,
  `save_receipt_upload`, and status-transition rules would pay for themselves fast.
- **Migration drift.** `migrations/add_clerk_user_id.sql` and the unified `database.sql`
  both touch schema; a fresh install applies both (idempotent via IF NOT EXISTS) but
  there's no real migration tooling/ordering.

## 6. Prioritized recommendations

**Fix now (before anyone uses this with real data):**
1. C4 — close public staff self-registration; provision staff via registrar-only flows.
2. C1 + C2 + C3 — signature-verify-or-die; validate issuer; cookie-only session tokens.
3. C6 — add rate limiting / lockout on `attempt_login`.
4. C5 — rotate + delete seed credentials; force admin password change on first login.

**Fix before going to Vercel:**
5. DB-backed or Redis-backed sessions; object storage for uploads.
6. Document/automate the external MySQL setup; wire Vercel env vars.
7. Guard `setup.php`/`init.php` behind `APP_ENV=development` or self-delete.

**Quality-of-life / hardening:**
8. Add a status-state-machine (PHP side) mirroring the payment trigger logic so admin
   UI can't walk statuses backward.
9. Add `realpath` containment to `view-proof.php`.
10. Add PHPUnit smoke tests + a CI job; add `composer.json` to vendor the Clerk code
    into testable units if you are going to keep extending it.
11. Uniform CSP + HSTS headers; consider `SameSite=Strict` for internal nav links.
12. Replace the mock gateway with real provider integration (PayMongo / GCash / Maya
    *sandbox*), or clearly badge "DEMO — no real money moves" everywhere students touch
    it (it currently does say this on the gateway page — good).
