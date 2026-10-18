<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

$services = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY name')->fetchAll();

$title = 'Services';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Registrar services</h1>
        <p class="muted">Choose a document to request. Fees are fixed and shown up front.</p>
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
                <a class="btn btn-primary btn-block" href="<?= url('student/request.php?service=' . (int) $s['id']) ?>">
                    <?= icon('plus') ?> Request this
                </a>
            </article>
        <?php endforeach; ?>
        <?php if (!$services): ?>
            <div class="glass empty-note wide">No services are currently available. Please check back later.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
