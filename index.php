<?php
require_once __DIR__ . '/includes/auth.php';

$user = current_user();
if ($user) {
    redirect($user['role'] === 'student' ? 'student/dashboard.php' : 'admin/dashboard.php');
}

$services = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY fee, name')->fetchAll();
$services_subtitle = get_setting('services_subtitle', 'Official registrar documents with fixed, transparent fees.');
$stats = [
    'services' => count($services),
    'requests' => (int) db()->query('SELECT COUNT(*) FROM requests')->fetchColumn(),
    'revenue'  => (float) db()->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "paid"')->fetchColumn(),
];

$title = 'Home';
require __DIR__ . '/includes/header.php';
?>

<section class="hero">
    <div class="hero-inner">
        <span class="hero-eyebrow"><span class="dot"></span> Registrar services, fully online</span>
        <h1>Request official documents.<br><span class="grad-text">Track every peso.</span></h1>
        <p class="hero-sub">
            A single portal for students to request transcripts, certificates and clearances —
            with transparent fees, online payment, and real-time status tracking.
        </p>
        <div class="hero-cta">
            <a class="btn btn-primary btn-lg" href="<?= url('register.php') ?>"><?= icon('user') ?> Get started</a>
            <a class="btn btn-ghost btn-lg" href="<?= url('login.php') ?>"><?= icon('login') ?> Sign in</a>
        </div>

        <div class="hero-stats">
            <div class="stat glass">
                <span class="stat-num"><?= (int) $stats['services'] ?></span>
                <span class="stat-label">Available services</span>
            </div>
            <div class="stat glass">
                <span class="stat-num"><?= number_format($stats['requests']) ?></span>
                <span class="stat-label">Requests processed</span>
            </div>
            <div class="stat glass">
                <span class="stat-num"><?= peso($stats['revenue']) ?></span>
                <span class="stat-label">Payments recorded</span>
            </div>
        </div>
    </div>
</section>

<section class="section" id="services">
    <div class="container">
        <div class="section-head">
            <h2>What you can request</h2>
            <p class="muted"><?= e($services_subtitle) ?></p>
        </div>

        <div class="card-grid">
            <?php foreach ($services as $s): ?>
                <article class="glass card service-card">
                    <span class="service-icon"><?= icon('doc', 'ic ic-lg') ?></span>
                    <h3><?= e($s['name']) ?></h3>
                    <p class="muted"><?= e($s['description'] ?: 'Official document issued by the registrar.') ?></p>
                    <div class="service-foot">
                        <span class="price"><?= peso($s['fee']) ?></span>
                        <span class="muted small">≈ <?= (int) $s['processing_days'] ?> day<?= (int) $s['processing_days'] === 1 ? '' : 's' ?></span>
                    </div>
                    <a class="btn btn-soft btn-block" href="<?= url('login.php') ?>">Request now</a>
                </article>
            <?php endforeach; ?>
            <?php if (!$services): ?>
                <div class="glass empty-note">
                    No services available yet.<br>
                    <span class="muted small">The registrar will add services from the admin panel.</span>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="section section-tight">
    <div class="container">
        <div class="section-head">
            <h2>How it works</h2>
            <p class="muted">Four steps, no queues.</p>
        </div>
        <div class="steps">
            <div class="step glass"><span class="step-no">1</span><h3>Create an account</h3><p class="muted">Register with your student number, course and year level.</p></div>
            <div class="step glass"><span class="step-no">2</span><h3>Request a document</h3><p class="muted">Pick a service, add a note, and submit your request.</p></div>
            <div class="step glass"><span class="step-no">3</span><h3>Pay online</h3><p class="muted">Pay through the gateway — GCash, Maya, card, or bank.</p></div>
            <div class="step glass"><span class="step-no">4</span><h3>Track &amp; claim</h3><p class="muted">Follow the status until your document is ready for release.</p></div>
        </div>
    </div>
</section>

<section class="section section-tight">
    <div class="container">
        <div class="feature-band glass-strong">
            <div class="feature">
                <?= icon('clock', 'ic ic-xl feature-ic') ?>
                <h3>Real-time status</h3>
                <p class="muted">Every request moves through pending → processing → ready → released.</p>
            </div>
            <div class="feature">
                <?= icon('wallet', 'ic ic-xl feature-ic') ?>
                <h3>Online payments</h3>
                <p class="muted">Pay through the gateway and get an official receipt the moment it's confirmed.</p>
            </div>
            <div class="feature">
                <?= icon('shield', 'ic ic-xl feature-ic') ?>
                <h3>Full accountability</h3>
                <p class="muted">A complete audit log of every request and payment, kept for the record.</p>
            </div>
        </div>
    </div>
</section>

<?php require __DIR__ . '/includes/footer.php'; ?>