<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    
    if ($action === 'mark_received') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM requests WHERE id = ? AND student_id = ? AND status = "ready"');
        $stmt->execute([$requestId, $user['id']]);
        $request = $stmt->fetch();
        
        if ($request) {
            $stmt = db()->prepare('UPDATE requests SET status = "released", updated_at = NOW() WHERE id = ?');
            $stmt->execute([$requestId]);
            audit_log($user['id'], 'document_received', 'Student received document for ' . $request['ref_no'], 'student');
            flash_set('success', 'Document marked as received. Thank you!');
            redirect('student/requests.php');
        }
        flash_set('error', 'Could not mark document as received.');
        redirect('student/requests.php');
    }
}

$stmt = db()->prepare(
    'SELECT r.*, s.name AS service_name,
            p.id                 AS payment_id,
            p.transaction_number AS pay_ref,
            p.method             AS pay_method,
            p.status             AS pay_status,
            p.proof_image        AS receipt,
            p.failure_reason     AS decline_reason,
            rc.receipt_number
     FROM requests r
     JOIN services s ON s.id = r.service_id
     LEFT JOIN payments p ON p.request_id = r.id
        AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id)
     LEFT JOIN receipts rc ON rc.payment_id = p.id
     WHERE r.student_id = ?
     ORDER BY r.created_at DESC'
);
$stmt->execute([$user['id']]);
$requests = $stmt->fetchAll();

$payMethodNames = [
    'gcash'         => 'GCash',
    'maya'          => 'Maya',
    'card'          => 'Card',
    'bank_transfer' => 'Bank Transfer',
    'mock'          => 'Legacy (mock)'
];

$title = 'My requests';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>My requests</h1>
        <p class="muted">Track every request from submission to release.</p>
    </div>

    <?php if (!$requests): ?>
        <div class="panel glass empty-note">
            No requests yet.<br><br>
            <a class="btn btn-primary" href="<?= url('student/services.php') ?>"><?= icon('plus') ?> Make your first request</a>
        </div>
    <?php endif; ?>

    <div class="stack">
        <?php foreach ($requests as $r): ?>
            <article class="panel glass" id="r<?= (int) $r['id'] ?>">
                <div class="panel-head">
                    <div>
                        <h2><?= e($r['service_name']) ?></h2>
                        <span class="muted small">
                            <code data-copy="<?= e($r['ref_no']) ?>"><?= e($r['ref_no']) ?></code> · requested <?= nice_date($r['created_at']) ?>
                        </span>
                    </div>
                    <div class="panel-actions">
                        <?= status_pill($r['status']) ?>
                        <span class="money"><?= peso($r['fee']) ?></span>
                    </div>
                </div>

                <?php if ($r['notes']): ?>
                    <p class="muted small" style="margin-top:0"><strong class="dim">Notes:</strong> <?= e($r['notes']) ?></p>
                <?php endif; ?>

                <?php if ($r['admin_note']): ?>
                    <div class="alert <?= $r['status'] === 'rejected' ? 'alert-error' : '' ?> glass" style="margin-top:8px">
                        <?= icon($r['status'] === 'rejected' ? 'x' : 'info') ?><span><strong><?= $r['status'] === 'rejected' ? 'Request rejected:' : 'Registrar\'s note:' ?></strong> <?= e($r['admin_note']) ?></span>
                    </div>
                <?php endif; ?>

                <div class="two-col">
                    <div>
                        <h3 class="dim small" style="text-transform:uppercase;letter-spacing:.1em">Progress</h3>
                        <ul class="timeline">
                            <?php
                            $stages = [
                                'pending'    => 'Request submitted — awaiting approval',
                                'approved'   => 'Approved — awaiting payment',
                                'processing' => 'Payment verified — being processed',
                                'ready'      => 'Document ready for claiming',
                                'released'   => 'Document released',
                            ];
                            $order  = ['pending' => 0, 'approved' => 1, 'processing' => 2, 'ready' => 3, 'released' => 4];
                            $status = strtolower($r['status']);
                            if ($status === 'rejected') {
                                echo '<li><strong>Request rejected.</strong><span class="t-when">' . nice_date($r['updated_at']) . '</span></li>';
                            } else {
                                $current = $order[$status] ?? 0;
                                foreach ($stages as $i => $label) {
                                    $done = $i < $current ? 'ok' : '';
                                    echo '<li class="' . $done . '"><strong>' . e($label) . '</strong>'
                                       . ($i == $current ? '<span class="t-when">current stage</span>' : '')
                                       . '</li>';
                                }
                            }
                            ?>
                        </ul>
                    </div>
                    <div>
                        <h3 class="dim small" style="text-transform:uppercase;letter-spacing:.1em">Payment</h3>
                        <?php if ($r['pay_ref']): ?>
                            <dl class="kv">
                                <dt>Transaction</dt><dd><code data-copy="<?= e($r['pay_ref']) ?>"><?= e($r['pay_ref']) ?></code></dd>
                                <dt>Method</dt><dd><?= e($payMethodNames[$r['pay_method']] ?? ucfirst((string) $r['pay_method'])) ?></dd>
                                <dt>Status</dt>
                                <dd>
                                    <span class="pill <?= $r['pay_status'] === 'paid' ? 'pill-ok' : ($r['pay_status'] === 'failed' ? 'pill-bad' : 'pill-warn') ?>">
                                        <?= e(ucfirst((string) $r['pay_status'])) ?>
                                    </span>
                                </dd>
                                <dt>Amount</dt><dd class="money"><?= peso($r['fee']) ?></dd>
                                <?php if (!empty($r['receipt_number'])): ?>
                                    <dt>Receipt</dt><dd><code><?= e($r['receipt_number']) ?></code></dd>
                                <?php endif; ?>
                            </dl>
                            <?php if ($r['pay_status'] === 'paid'): ?>
                                <a class="btn btn-ghost btn-sm" style="margin-top:10px" href="<?= url('student/receipt.php?id=' . (int) $r['id']) ?>">
                                    <?= icon('receipt') ?> View official receipt
                                </a>
                            <?php elseif ($r['pay_status'] === 'failed'): ?>
                                <div class="alert alert-error glass" style="margin-top:10px">
                                    <?= icon('x') ?><span><strong>Receipt declined:</strong> <?= e($r['decline_reason'] ?? 'Please upload a valid receipt.') ?></span>
                                </div>
                                <a class="btn btn-primary btn-sm" style="margin-top:10px" href="<?= url('student/payment.php?request_id=' . (int) $r['id']) ?>">
                                    <?= icon('upload') ?> Upload new receipt
                                </a>
                            <?php elseif ($r['pay_status'] === 'pending' && $r['status'] === 'approved'): ?>
                                <?php if ($r['receipt']): ?>
                                    <a class="btn btn-ghost btn-sm" style="margin-top:10px" href="<?= url('student/view-receipt.php?img=' . urlencode($r['receipt'])) ?>">
                                        <?= icon('eye') ?> View uploaded receipt
                                    </a>
                                <?php else: ?>
                                    <a class="btn btn-primary btn-sm" style="margin-top:10px" href="<?= url('student/payment.php?request_id=' . (int) $r['id']) ?>">
                                        <?= icon('upload') ?> Upload receipt
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="muted small">No payment recorded yet.</p>
                            <?php if ($r['status'] === 'approved'): ?>
                                <a class="btn btn-primary btn-sm" href="<?= url('student/payment.php?request_id=' . (int) $r['id']) ?>">
                                    <?= icon('upload') ?> Upload payment receipt
                                </a>
                            <?php elseif ($r['status'] === 'pending'): ?>
                                <p class="muted small">Waiting for registrar approval.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($r['status'] === 'ready'): ?>
                    <div class="alert alert-success glass" style="margin-top:14px">
                        <?= icon('check') ?><span>Your document is ready! Present your reference number <code><?= e($r['ref_no']) ?></code> at the registrar's office and bring your student ID.</span>
                    </div>
                    <form method="post" action="<?= url('student/requests.php') ?>" style="margin-top:14px">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="mark_received">
                        <input type="hidden" name="request_id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-primary" type="submit" data-confirm="Confirm that you have received this document?">
                            <?= icon('check') ?> Mark as received
                        </button>
                    </form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>