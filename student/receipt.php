<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT r.*, s.name AS service_name, u.full_name, u.student_no, u.course, u.year_level,
            p.method AS pay_method, p.transaction_number AS pay_ref, p.paid_at, p.proof_image,
            rc.receipt_number, rc.issued_at AS receipt_issued_at
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     LEFT JOIN payments p ON p.request_id = r.id
     LEFT JOIN receipts rc ON rc.payment_id = p.id
     WHERE r.id = ? AND r.student_id = ?
     ORDER BY p.id DESC LIMIT 1'
);
$stmt->execute([$id, $user['id']]);
$r = $stmt->fetch();

if (!$r) {
    flash_set('error', 'Receipt not found for this request.');
    redirect('student/requests.php');
}

$payMethodNames = [
    'gcash'         => 'GCash',
    'maya'          => 'Maya',
    'card'          => 'Card',
    'bank_transfer' => 'Bank Transfer',
    'mock'          => 'Legacy (mock)'
];

$title = 'Receipt ' . $r['ref_no'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Official receipt</h1>
        <p class="muted">Reference <code><?= e($r['ref_no']) ?></code></p>
    </div>

    <div class="panel glass-strong receipt-panel">
        <div class="receipt-head">
            <span class="brand-mark"><?= icon('graduation', 'ic ic-lg') ?></span>
            <div>
                <strong>SRVP — Student Request &amp; Payment Portal</strong><br>
                <span class="muted small">Office of the Registrar · Official payment receipt</span>
            </div>
        </div>

        <hr class="glass-hr">

        <dl class="kv">
            <dt>Issued to</dt><dd><strong><?= e($r['full_name']) ?></strong></dd>
            <dt>Student No.</dt><dd><code><?= e($r['student_no']) ?></code></dd>
            <dt>Program</dt><dd><?= e(($r['course'] ?: '—') . ($r['year_level'] ? ' · Year ' . (int) $r['year_level'] : '')) ?></dd>
            <dt>Document</dt><dd><?= e($r['service_name']) ?></dd>
            <dt>Payment method</dt><dd><?= e($payMethodNames[$r['pay_method']] ?? ucfirst((string) $r['pay_method'])) ?></dd>
            <?php if (!empty($r['receipt_number'])): ?>
                <dt>Receipt No.</dt><dd><strong style="color:var(--color-ok,#10b981)"><code><?= e($r['receipt_number']) ?></code></strong></dd>
            <?php endif; ?>
            <dt>Payment ref.</dt><dd><code><?= e($r['pay_ref'] ?: '—') ?></code></dd>
            <dt>Paid at</dt><dd><?= nice_date($r['paid_at']) ?></dd>
            <dt>Request status</dt><dd><?= status_pill($r['status']) ?></dd>
        </dl>

        <hr class="glass-hr">

        <?php if ($r['proof_image']): ?>
            <div style="margin:14px 0;text-align:center">
                <p class="dim small" style="margin-bottom:8px">Payment proof (uploaded receipt)</p>
                <img src="<?= url($r['proof_image']) ?>" alt="Payment receipt" style="max-width:100%;height:auto;max-height:300px;border:1px solid #ddd;border-radius:8px;">
            </div>
            <hr class="glass-hr">
        <?php endif; ?>

        <div class="receipt-total">
            <span>Total paid</span>
            <span class="money-lg"><?= peso($r['fee']) ?></span>
        </div>

        <p class="dim small" style="text-align:center;margin:14px 0 0">
            This receipt was generated electronically<?= $r['paid_at'] ? ' on ' . e(date('F j, Y', strtotime($r['paid_at']))) : '' ?> and is valid without a wet signature.
        </p>
    </div>

    <div class="panel-actions" style="margin-top:18px">
        <a class="btn btn-primary" href="javascript:window.print()"><?= icon('receipt') ?> Print / save PDF</a>
        <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">Back to requests</a>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
