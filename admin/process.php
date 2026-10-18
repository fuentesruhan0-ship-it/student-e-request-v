<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

$stmt = db()->prepare(
    'SELECT r.*, s.name AS service_name, s.processing_days,
            u.full_name, u.student_no, u.email, u.course, u.year_level
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     WHERE r.id = ?'
);
$stmt->execute([$id]);
$r = $stmt->fetch();

if (!$r) {
    flash_set('error', 'Request not found.');
    redirect('admin/requests.php');
}

$errors = [];
$validStatuses = ['pending', 'approved', 'processing', 'ready', 'released', 'rejected'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'status') {
        $status = strtolower(trim((string) ($_POST['status'] ?? '')));
        $note   = trim((string) ($_POST['admin_note'] ?? ''));

        if (!in_array($status, $validStatuses, true)) {
            $errors[] = 'Invalid status.';
        } elseif (mb_strlen($note) > 500) {
            $errors[] = 'Note is limited to 500 characters.';
        } elseif ($status === 'rejected' && empty($note)) {
            $errors[] = 'Please provide a reason for rejecting this request.';
        }

        if (!$errors) {
            $stmt = db()->prepare('UPDATE requests SET status = ?, admin_note = ?, updated_at = NOW() WHERE id = ?');
            $stmt->execute([$status, $note !== '' ? $note : null, $id]);
            audit_log($admin['id'], 'request_status', 'Set ' . $r['ref_no'] . ' to "' . $status . '"' . ($note !== '' ? ' — note: ' . $note : ''));

            // Send notification email to student
            require_once __DIR__ . '/../includes/mail.php';
            $studentEmail = $r['email'];
            $studentName  = $r['full_name'];
            $serviceName  = $r['service_name'];
            $refNo        = $r['ref_no'];

            $statusTitles = [
                'approved'   => "Request Approved: $refNo",
                'processing' => "Request is Now Processing: $refNo",
                'ready'      => "Your Document is Ready for Pickup: $refNo",
                'released'   => "Document Released: $refNo",
                'rejected'   => "Update Regarding Your Request: $refNo",
            ];
            $statusBodies = [
                'approved'   => "<p>Hello <strong>" . e($studentName) . "</strong>,</p><p>Good news! Your document request for <strong>" . e($serviceName) . "</strong> (Reference: <code>$refNo</code>) has been <strong>approved</strong> by the registrar.</p><p>Please log in to the portal to proceed with your payment or monitor your request status.</p>",
                'processing' => "<p>Hello <strong>" . e($studentName) . "</strong>,</p><p>Your request for <strong>" . e($serviceName) . "</strong> (Reference: <code>$refNo</code>) is now being <strong>processed</strong> by the registrar's office.</p>",
                'ready'      => "<p>Hello <strong>" . e($studentName) . "</strong>,</p><p>Your requested document, <strong>" . e($serviceName) . "</strong> (Reference: <code>$refNo</code>), is now <strong>ready for claiming</strong> at the Registrar's Office. Please bring your student ID and reference number.</p>",
                'released'   => "<p>Hello <strong>" . e($studentName) . "</strong>,</p><p>Your requested document <strong>" . e($serviceName) . "</strong> (Reference: <code>$refNo</code>) has been marked as officially <strong>released</strong>.</p>",
                'rejected'   => "<p>Hello <strong>" . e($studentName) . "</strong>,</p><p>Your request for <strong>" . e($serviceName) . "</strong> (Reference: <code>$refNo</code>) was declined.</p>" . ($note !== '' ? "<p><strong>Reason provided:</strong> " . e($note) . "</p>" : ""),
            ];

            if (isset($statusTitles[$status])) {
                @send_mail($studentEmail, $statusTitles[$status], $statusBodies[$status]);
            }

            flash_set('success', $r['ref_no'] . ' is now marked "' . $status . '".');
            redirect('admin/process.php?id=' . $id);
        }
    } elseif ($action === 'delete_payment') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND request_id = ?');
        $stmt->execute([$pid, $id]);
        if ($row = $stmt->fetch()) {
            if (!empty($row['proof_image'])) {
                $abs = UPLOAD_DIR . DIRECTORY_SEPARATOR . basename($row['proof_image']);
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
            $stmt = db()->prepare('DELETE FROM payments WHERE id = ?');
            $stmt->execute([$pid]);
            audit_log($admin['id'], 'payment_delete', 'Deleted payment #' . $pid . ' on ' . $r['ref_no']);
            flash_set('success', 'Payment record removed.');
        }
        redirect('admin/process.php?id=' . $id);
    } elseif ($action === 'verify_payment') {
        // Admin manually marks request as processing (e.g. cash walk-in with no online payment record)
        $stmt = db()->prepare('UPDATE requests SET status = "processing", updated_at = NOW() WHERE id = ? AND status IN ("pending", "approved")');
        $stmt->execute([$id]);
        audit_log($admin['id'], 'payment_verify', 'Manually verified payment / started processing ' . $r['ref_no']);

        require_once __DIR__ . '/../includes/mail.php';
        @send_mail(
            $r['email'],
            "Payment Recorded: " . $r['ref_no'],
            "<p>Hello <strong>" . e($r['full_name']) . "</strong>,</p><p>Payment for your request <strong>" . e($r['service_name']) . "</strong> (Reference: <code>" . e($r['ref_no']) . "</code>) has been manually confirmed. Your request is now being processed.</p>"
        );

        flash_set('success', 'Marked as payment verified — request is now processing.');
        redirect('admin/process.php?id=' . $id);
    } elseif ($action === 'verify_receipt') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND request_id = ?');
        $stmt->execute([$pid, $id]);
        $payment = $stmt->fetch();
        if ($payment && $payment['status'] === 'pending' && $payment['proof_image']) {
            $stmt = db()->prepare('UPDATE payments SET status = "paid", paid_at = NOW() WHERE id = ?');
            $stmt->execute([$pid]);
            $stmtReq = db()->prepare('UPDATE requests SET status = "processing", updated_at = NOW() WHERE id = ? AND status IN ("pending", "approved")');
            $stmtReq->execute([$id]);
            audit_log($admin['id'], 'receipt_verify', 'Verified receipt for payment #' . $pid . ' on ' . $r['ref_no']);

            require_once __DIR__ . '/../includes/mail.php';
            @send_mail(
                $r['email'],
                "Payment Verified: " . $r['ref_no'],
                "<p>Hello <strong>" . e($r['full_name']) . "</strong>,</p><p>Your payment receipt for request <strong>" . e($r['service_name']) . "</strong> (Reference: <code>" . e($r['ref_no']) . "</code>) has been verified by the cashier. Your request is now proceeding to document processing.</p>"
            );

            flash_set('success', 'Receipt verified — payment marked as paid.');
            redirect('admin/process.php?id=' . $id);
        }
        flash_set('error', 'Could not verify receipt.');
        redirect('admin/process.php?id=' . $id);
    } elseif ($action === 'decline_receipt') {
        $pid = (int) ($_POST['payment_id'] ?? 0);
        $reason = trim((string) ($_POST['decline_reason'] ?? ''));
        $customReason = trim((string) ($_POST['decline_reason_custom'] ?? ''));
        if ($reason === 'Other' && !empty($customReason)) {
            $reason = $customReason;
        }
        if (empty($reason)) {
            $errors[] = 'Please provide a reason for declining the receipt.';
        } else {
            $stmt = db()->prepare('SELECT * FROM payments WHERE id = ? AND request_id = ?');
            $stmt->execute([$pid, $id]);
            $payment = $stmt->fetch();
            if ($payment && $payment['status'] === 'pending' && $payment['proof_image']) {
                $stmt = db()->prepare('UPDATE payments SET status = "failed", failure_reason = ? WHERE id = ?');
                $stmt->execute([$reason, $pid]);
                audit_log($admin['id'], 'receipt_decline', 'Declined receipt for payment #' . $pid . ' on ' . $r['ref_no'] . ': ' . $reason);

                require_once __DIR__ . '/../includes/mail.php';
                @send_mail(
                    $r['email'],
                    "Payment Receipt Action Required: " . $r['ref_no'],
                    "<p>Hello <strong>" . e($r['full_name']) . "</strong>,</p><p>Your payment receipt for request <strong>" . e($r['service_name']) . "</strong> (Reference: <code>" . e($r['ref_no']) . "</code>) could not be verified.</p><p><strong>Reason:</strong> " . e($reason) . "</p><p>Please log in to the portal and upload a valid receipt or choose a different payment method.</p>"
                );

                flash_set('success', 'Receipt declined. Student will be notified to upload a new receipt.');
                redirect('admin/process.php?id=' . $id);
            }
            flash_set('error', 'Could not decline receipt.');
            redirect('admin/process.php?id=' . $id);
        }
    }
}

$stmt = db()->prepare('SELECT * FROM payments WHERE request_id = ? AND status != "failed" ORDER BY created_at DESC');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$payMethodNames = [
    'cash'          => 'Cash',
    'gcash'         => 'GCash',
    'maya'          => 'Maya',
    'card'          => 'Card',
    'bank'          => 'Bank transfer',
    'bank_transfer' => 'Bank Transfer',
    'mock'          => 'Legacy (mock)'
];

$payStatusNames = [
    'pending' => 'Pending',
    'paid'    => 'Paid',
    'failed'  => 'Declined',
];

$title = 'Review ' . $r['ref_no'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Request review</h1>
        <p class="muted">Reference <code><?= e($r['ref_no']) ?></code></p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass"><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <?php if ($admin['role'] === 'cashier'): ?>
        <div class="panel glass">
            <div class="panel-head">
                <h2><?= e($r['service_name']) ?></h2>
                <?php if ($r['status'] === 'approved' && (!$payments || $payments[0]['status'] !== 'paid')): ?>
                    <?= status_pill('approved', 'Approved - awaiting payment') ?>
                <?php else: ?>
                    <?= status_pill($r['status']) ?>
                <?php endif; ?>
            </div>
            <dl class="kv">
                <dt>Student</dt><dd><strong><?= e($r['full_name']) ?></strong></dd>
                <dt>Student No.</dt><dd><code><?= e($r['student_no']) ?></code></dd>
                <dt>Fee</dt><dd class="money"><?= peso($r['fee']) ?></dd>
            </dl>
        </div>
    <?php else: ?>
        <div class="two-col">
            <div class="panel glass">
                <div class="panel-head">
                    <h2><?= e($r['service_name']) ?></h2>
                    <?php if ($r['status'] === 'approved' && (!$payments || $payments[0]['status'] !== 'paid')): ?>
                        <?= status_pill('approved', 'Approved - awaiting payment') ?>
                    <?php else: ?>
                        <?= status_pill($r['status']) ?>
                    <?php endif; ?>
                </div>
                <dl class="kv">
                    <dt>Student</dt><dd><strong><?= e($r['full_name']) ?></strong></dd>
                    <dt>Student No.</dt><dd><code><?= e($r['student_no']) ?></code></dd>
                    <dt>Email</dt><dd><?= e($r['email']) ?></dd>
                    <dt>Program</dt><dd><?= e(($r['course'] ?: '—') . ($r['year_level'] ? ' · Year ' . (int) $r['year_level'] : '')) ?></dd>
                    <dt>Fee</dt><dd class="money"><?= peso($r['fee']) ?></dd>
                    <dt>Processing</dt><dd>≈ <?= (int) $r['processing_days'] ?> day<?= (int) $r['processing_days'] === 1 ? '' : 's' ?></dd>
                    <dt>Last update</dt><dd><?= nice_date($r['updated_at']) ?></dd>
                </dl>
                <?php if ($r['notes']): ?>
                    <div class="alert glass" style="margin-top:14px">
                        <?= icon('info') ?><span><strong>Student notes:</strong> <?= e($r['notes']) ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="panel glass">
                <div class="panel-head"><h2>Update status</h2></div>
                <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="status">
                    <div class="field">
                        <label for="status">Pipeline status</label>
                        <?php if ($r['status'] === 'pending'): ?>
                            <select id="status" name="status">
                                <option value="approved" <?= $r['status'] === 'approved' ? 'selected' : '' ?>>Approve</option>
                                <option value="rejected" <?= $r['status'] === 'rejected' ? 'selected' : '' ?>>Decline</option>
                            </select>
                            <span class="hint">Approve to allow student to pay, or Decline with a reason.</span>
                        <?php elseif (in_array($r['status'], ['approved', 'processing'])): ?>
                            <select id="status" name="status">
                                <option value="approved" <?= $r['status'] === 'approved' ? 'selected' : '' ?>>Approved (awaiting payment)</option>
                                <option value="processing" <?= $r['status'] === 'processing' ? 'selected' : '' ?>>Processing</option>
                                <option value="ready" <?= $r['status'] === 'ready' ? 'selected' : '' ?>>Ready to receive</option>
                            </select>
                            <span class="hint">Move to Processing after payment is verified, then Ready when document is prepared.</span>
                        <?php elseif ($r['status'] === 'ready'): ?>
                            <select id="status" name="status">
                                <option value="ready" <?= $r['status'] === 'ready' ? 'selected' : '' ?>>Ready to receive</option>
                                <option value="released" <?= $r['status'] === 'released' ? 'selected' : '' ?>>Released (student received)</option>
                            </select>
                            <span class="hint">Mark as Released when student receives the document.</span>
                        <?php else: ?>
                            <select id="status" name="status">
                                <?php foreach ($validStatuses as $st): ?>
                                    <option value="<?= $st ?>" <?= $r['status'] === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="admin_note">Registrar note <span class="dim">(shown to the student)</span></label>
                        <textarea id="admin_note" name="admin_note" maxlength="500"
                                  placeholder="e.g. Please bring valid ID when claiming. Required if rejecting request."><?= e($r['admin_note']) ?></textarea>
                        <?php if ($r['status'] === 'rejected'): ?>
                            <span class="hint">This note explains to the student why the request was rejected.</span>
                        <?php endif; ?>
                    </div>
                    <div class="panel-actions">
                        <button type="submit" class="btn btn-primary"><?= icon('check') ?> Save update</button>
                        <a class="btn btn-ghost" href="<?= url('admin/requests.php') ?>">Back to list</a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <div class="panel glass">
        <div class="panel-head"><h2>Payments (<?= count($payments) ?>)</h2></div>
        <?php if ($payments): ?>
            <div class="stack">
                <?php foreach ($payments as $p): ?>
                    <div class="payment-row glass">
                        <div>
                            <strong class="money"><?= peso($p['amount']) ?></strong>
                            <span class="pill pill-info"><?= e($payMethodNames[$p['method']] ?? ucfirst($p['method'])) ?></span>
                            <span class="small muted">ref <code><?= e($p['transaction_number']) ?></code> · <span class="pill <?= $p['status'] === 'paid' ? 'pill-ok' : ($p['status'] === 'failed' ? 'pill-bad' : 'pill-warn') ?>"><?= e($payStatusNames[$p['status']] ?? ucfirst($p['status'])) ?></span> · <?= nice_date($p['created_at']) ?></span>
                        </div>
                        <div class="row-actions">
                            <?php if ($p['proof_image']): ?>
                                <a class="btn btn-ghost btn-sm" href="<?= url('admin/view-proof.php?img=' . urlencode($p['proof_image'])) ?>">
                                    <?= icon('eye') ?> View proof
                                </a>
                            <?php endif; ?>
                            <?php if ($p['status'] === 'pending' && $p['proof_image']): ?>
                                <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="verify_receipt">
                                    <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                    <button class="btn btn-ok btn-sm" type="submit"
                                            data-confirm="Verify this receipt and mark payment as paid?">
                                        <?= icon('check') ?> Verify
                                    </button>
                                </form>
                                <button class="btn btn-danger btn-sm" type="button"
                                        onclick="document.getElementById('decline-<?= (int) $p['id'] ?>').style.display='block'">
                                    <?= icon('x') ?> Decline
                                </button>
                                <div id="decline-<?= (int) $p['id'] ?>" style="display:none;margin-top:10px;padding:10px;background:var(--glass-bg);border-radius:8px;">
                                    <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="decline_receipt">
                                        <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                                        <div class="field">
                                            <label>Reason for decline</label>
                                            <select name="decline_reason" required>
                                                <option value="">Select a reason...</option>
                                                <option value="Invalid receipt - image unclear">Invalid receipt - image unclear</option>
                                                <option value="Invalid receipt - wrong reference number">Invalid receipt - wrong reference number</option>
                                                <option value="Invalid receipt - amount does not match">Invalid receipt - amount does not match</option>
                                                <option value="Invalid receipt - expired">Invalid receipt - expired</option>
                                                <option value="Invalid receipt - not from GCash/Maya/Bank">Invalid receipt - not from GCash/Maya/Bank</option>
                                                <option value="Other">Other (specify below)</option>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <input type="text" name="decline_reason_custom" placeholder="Or type custom reason..." style="width:100%">
                                        </div>
                                        <button class="btn btn-danger btn-sm" type="submit">Submit decline</button>
                                        <button class="btn btn-ghost btn-sm" type="button"
                                                onclick="document.getElementById('decline-<?= (int) $p['id'] ?>').style.display='none'">Cancel</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($r['status'] === 'pending'): ?>
            <form method="post" action="<?= url('admin/process.php?id=' . $id) ?>" style="margin-top:14px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify_payment">
                <button class="btn btn-ok" type="submit"><?= icon('check') ?> Verify payment &amp; start processing</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
