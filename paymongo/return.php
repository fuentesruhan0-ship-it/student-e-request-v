<?php
/**
 * PayMongo checkout return page — the browser lands here after paying
 * (success_url). We do NOT trust the browser: only a confirmed paid status
 * from PayMongo's API (paymongo_checkout_is_paid) or the webhook marks the
 * payment paid. On webhook-only flows this page is a friendly poller.
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';
$user = require_student();

$paymentId = (int) ($_GET['payment_id'] ?? 0);
$backUrl   = 'student/requests.php';

if ($paymentId <= 0) redirect($backUrl);

$stmt = db()->prepare(
    'SELECT p.*, r.ref_no AS req_ref, r.student_id
     FROM payments p
     JOIN requests r ON r.id = p.request_id
     WHERE p.id = ? AND r.student_id = ?
     LIMIT 1'
);
$stmt->execute([$paymentId, $user['id']]);
$payment = $stmt->fetch();
if (!$payment) {
    flash_set('error', 'Payment session not found for your account.');
    redirect($backUrl);
}

if ($payment['status'] === 'paid') {
    flash_set('success', 'Payment confirmed! Your official receipt is ready.');
    redirect('student/receipt.php?id=' . (int) $payment['request_id']);
}

if (empty($payment['payment_url'])) {
    flash_set('error', 'This payment session has no gateway reference.');
    redirect($backUrl);
}

// Ask PayMongo, server-side, whether this checkout session is paid.
// The checkout session id was stored in gateway_reference at creation time,
// so we never scrape or parse the hosted checkout URL.
$csId = (string) ($payment['gateway_reference'] ?? '');
$attrs = $csId !== '' ? paymongo_get_checkout($csId) : null;

if ($attrs !== null && paymongo_checkout_is_paid($attrs)) {
    // Flip the payment to paid; receipt is auto-issued by DB trigger.
    [$changed, $method] = paymongo_complete_payment($paymentId, 'PAYMONGO-' . ($csId ?: $payment['transaction_number']));
    if ($changed) {
        audit_log($user['id'], 'payment_paid', 'PayMongo confirmed payment via return page for txn ' . $payment['transaction_number']);
        flash_set('success', 'Payment confirmed! Your official receipt is ready.');
        redirect('student/receipt.php?id=' . (int) $payment['request_id']);
    }
    flash_set('success', 'Payment is confirmed. Your request is now processing.');
    redirect($backUrl);
}

// Not paid yet (or lookup failed): offer to re-check or go back.
$title = 'Payment status';
require __DIR__ . '/../includes/header.php';
?>
<div class="page page-narrow">
    <div class="page-head">
        <h1>Checking your payment…</h1>
        <p class="muted">
            PayMongo has not confirmed your payment yet. If you already completed it,
            this usually resolves within a few seconds — use <strong>Re-check status</strong> below.
        </p>
    </div>

    <div class="panel glass">
        <dl class="kv">
            <dt>Transaction</dt><dd><code><?= e($payment['transaction_number']) ?></code></dd>
            <dt>Request</dt><dd><code><?= e($payment['req_ref']) ?></code></dd>
            <dt>Amount</dt><dd class="money"><?= peso($payment['amount']) ?></dd>
        </dl>
    </div>

    <div class="panel-actions" style="margin-top:16px">
        <a class="btn btn-primary" href="<?= url('paymongo/return.php?payment_id=' . $paymentId) ?>">
            <?= icon('clock') ?> Re-check status
        </a>
        <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">Back to my requests</a>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
