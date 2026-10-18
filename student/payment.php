<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';
$user = require_student();

$specificRequestId = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;

if ($specificRequestId) {
    $stmt = db()->prepare(
        'SELECT r.*, s.name AS service_name,
                p.id      AS payment_id,
                p.status  AS payment_status,
                p.transaction_number,
                p.method  AS payment_method,
                p.proof_image,
                p.payment_url,
                p.gateway_reference,
                p.failure_reason
         FROM requests r
         JOIN services s ON s.id = r.service_id
         LEFT JOIN payments p ON p.request_id = r.id
            AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id)
         WHERE r.id = ? AND r.student_id = ?'
    );
    $stmt->execute([$specificRequestId, $user['id']]);
    $request = $stmt->fetch();
    if (!$request) {
        flash_set('error', 'Request not found.');
        redirect('student/requests.php');
    }
} else {
    $stmt = db()->prepare(
        'SELECT r.*, s.name AS service_name,
                p.id      AS payment_id,
                p.status  AS payment_status,
                p.transaction_number,
                p.method  AS payment_method,
                p.proof_image
         FROM requests r
         JOIN services s ON s.id = r.service_id
         LEFT JOIN payments p ON p.request_id = r.id
            AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id)
         WHERE r.student_id = ? AND r.status NOT IN ("released","rejected")
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([$user['id']]);
    $requests = $stmt->fetchAll();
}

$errors = [];
$online = paymongo_enabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    // ---------- ONLINE PAYMENT: create local session + PayMongo checkout --
    if ($action === 'start_online_payment') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $channel   = (string) ($_POST['channel'] ?? 'gcash');

        $methodMap = ['gcash' => 'gcash', 'paymaya' => 'maya', 'card' => 'card'];
        if (!isset($methodMap[$channel])) {
            $errors[] = 'Unsupported payment channel.';
        } elseif (!$online) {
            $errors[] = 'Online payment is temporarily unavailable. Please use the manual receipt upload below.';
        } else {
            try {
                $conn = db();
                $stmt = $conn->prepare('CALL sp_start_payment(?, ?, ?, @pid, @txn)');
                $stmt->execute([$requestId, $user['id'], $methodMap[$channel]]);
                $out = $conn->query('SELECT @pid AS pid, @txn AS txn')->fetch();
                $paymentId = (int) ($out['pid'] ?? 0);
                if ($paymentId <= 0) throw new RuntimeException('No payment session.');

                $stmt = $conn->prepare(
                    'SELECT p.amount, p.transaction_number, r.ref_no, s.name AS service_name
                     FROM payments p
                     JOIN requests r ON r.id = p.request_id
                     JOIN services s ON s.id = r.service_id
                     WHERE p.id = ? LIMIT 1'
                );
                $stmt->execute([$paymentId]);
                $p = $stmt->fetch();
                if (!$p) throw new RuntimeException('Payment row vanished.');

                [$ok, $checkoutUrl, $sessionId, $err] = paymongo_create_checkout(
                    (int) round(((float) $p['amount']) * 100), // PHP -> centavos
                    'SRVP - ' . $p['service_name'] . ' (' . $p['ref_no'] . ')',
                    [$channel],
                    url('paymongo/return.php?payment_id=' . $paymentId),
                    url('student/requests.php'),
                    [
                        'payment_id' => (string) $paymentId,
                        'request_id' => (string) $requestId,
                        'txn'        => (string) $p['transaction_number'],
                    ]
                );

                if ($ok) {
                    $conn->prepare('UPDATE payments SET payment_url = ?, gateway_reference = ? WHERE id = ?')
                         ->execute([$checkoutUrl, $sessionId, $paymentId]);
                    redirect($checkoutUrl); // leave the app -> PayMongo hosted page
                }
                $errors[] = $err !== '' ? $err : 'Could not start online payment. Please try again or upload a receipt instead.';
            } catch (Throwable $e) {
                error_log('Online payment error: ' . $e->getMessage());
                $errors[] = 'Unable to start online payment. Please try again or use the manual receipt upload.';
            }
        }
    }

    // ---------- MANUAL: create local session, then show channel/upload UI --
    elseif ($action === 'start_manual_upload') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $method    = (string) ($_POST['method'] ?? 'gcash');

        if (!in_array($method, ['gcash','maya','bank_transfer'], true)) {
            $errors[] = 'Invalid payment method.';
        } else {
            try {
                $conn = db();
                $stmt = $conn->prepare('CALL sp_start_payment(?, ?, ?, @pid, @txn)');
                $stmt->execute([$requestId, $user['id'], $method]);
                $out = $conn->query('SELECT @pid AS pid, @txn AS txn')->fetch();
                if (!empty($out['pid'])) {
                    redirect('student/payment.php?request_id=' . $requestId . '&upload=1');
                }
                $errors[] = 'Could not start a payment session.';
            } catch (Throwable $e) {
                error_log('Manual payment error: ' . $e->getMessage());
                $errors[] = 'Unable to start payment session. Please try again.';
            }
        }
    }

    // ---------- MANUAL RECEIPT UPLOAD -------------------------------------
    elseif ($action === 'upload_receipt') {
        $paymentId = (int) ($_POST['payment_id'] ?? 0);
        if (empty($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please select a receipt image to upload.';
        } else {
            // Security: verify this payment belongs to the logged-in student
            $chk = db()->prepare(
                'SELECT p.id FROM payments p
                 JOIN requests r ON r.id = p.request_id
                 WHERE p.id = ? AND r.student_id = ? LIMIT 1'
            );
            $chk->execute([$paymentId, $user['id']]);
            if (!$chk->fetch()) {
                flash_set('error', 'Payment not found or does not belong to your account.');
                redirect('student/requests.php');
            }

            $proofPath = save_receipt_upload($_FILES['receipt'], $user['id']);
            if (!$proofPath) {
                $errors[] = 'Invalid image file. Please upload a JPG, PNG, or WebP image (max 4MB).';
            } else {
            $uploadMethod = (string) ($_POST['method'] ?? 'gcash');
            if (!in_array($uploadMethod, ['gcash', 'maya', 'bank_transfer'], true)) {
                $uploadMethod = 'gcash';
            }
            $stmt = db()->prepare('UPDATE payments SET proof_image = ?, method = ? WHERE id = ?');
            $stmt->execute([$proofPath, $uploadMethod, $paymentId]);
            audit_log($user['id'], 'receipt_upload', 'Uploaded receipt for payment #' . $paymentId . ' via ' . $uploadMethod);
            flash_set('success', 'Receipt uploaded. Waiting for cashier verification.');
                redirect('student/requests.php');
            }
        }
    }
}
$title = 'Pay for your request';
require __DIR__ . '/../includes/header.php';

// Decide what the request panel should display. Only offer a new payment when
// there's no active payment session; once a manual session exists, show the
// receipt-upload form (or the "waiting for cashier" notice if already uploaded).
$showPayOptions  = false;
$showUploadForm  = false;
if ($specificRequestId && $request) {
    $uploadRequested = isset($_GET['upload']);
    $activeSession = $request['payment_id'] && $request['payment_status'] === 'pending';
    if ($activeSession) {
        if (!$request['proof_image'] || $uploadRequested) {
            $showUploadForm = true;
        }
    } elseif ($request['status'] === 'approved') {
        $showPayOptions = true;
    }
}

$gcashNum = get_setting('payment_gcash_number', '0917-123-4567');
$gcashNm  = get_setting('payment_gcash_name', 'Registrar Trust Fund');
$mayaNum  = get_setting('payment_maya_number', '0918-987-6543');
$mayaNm   = get_setting('payment_maya_name', 'Registrar Collection');
$bankNm   = get_setting('payment_bank_name', 'Land Bank of the Philippines');
$bankAcc  = get_setting('payment_bank_account', '1234-5678-90');
$bankTit  = get_setting('payment_bank_account_name', 'University Trust Account');
$payInstr = get_setting('payment_instructions', '');
$method = (string) ($_GET['channel'] ?? $request['payment_method'] ?? 'gcash');
if (!in_array($method, ['gcash', 'maya', 'bank_transfer'], true)) {
    $method = 'gcash';
}
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Pay for your request</h1>
        <p class="muted">Pay online with GCash, Maya, or card — or upload a manual receipt for cashier verification.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass"><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <?php if ($specificRequestId && $request): ?>
        <div class="panel glass">
            <div class="panel-head">
                <h2><?= e($request['service_name']) ?></h2>
                <span class="muted small"><code><?= e($request['ref_no']) ?></code></span>
            </div>
            <dl class="kv">
                <dt>Fee</dt><dd class="money-lg"><?= peso($request['fee']) ?></dd>
                <dt>Status</dt><dd><?= status_pill($request['status']) ?></dd>
            </dl>

            <?php if ($request['payment_status'] === 'paid'): ?>
                <div class="alert alert-ok glass">
                    <?= icon('check') ?><span>Payment confirmed online — your request is being processed.</span>
                </div>
                <a class="btn btn-primary" href="<?= url('student/requests.php') ?>">View your requests</a>
            <?php elseif ($request['status'] !== 'approved'): ?>
                <div class="alert alert-info glass">
                    <?= icon('clock') ?><span>This request must be approved by the registrar before you can pay.</span>
                </div>
                <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">Back to requests</a>
            <?php elseif ($showUploadForm && !empty($request['proof_image'])): ?>
                <div class="alert alert-info glass">
                    <?= icon('clock') ?><span>Receipt uploaded. Waiting for cashier verification.</span>
                </div>
                <a class="btn btn-primary" href="<?= url('student/requests.php') ?>">View your requests</a>

            <?php elseif ($showUploadForm): ?>
                <!-- MANUAL: confirmed channel info + upload form -->
                <div class="panel glass neu-inset" style="padding:1.1rem 1.25rem;margin-bottom:1.5rem;border-radius:12px;background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.08);">
                    
                    <!-- Channel Selector -->
                    <div style="margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                        <span class="small" style="font-weight:600;color:var(--accent-0,#38bdf8);display:flex;align-items:center;gap:.4rem;">
                            <?= icon('wallet') ?> Pay via:
                        </span>
                        <div style="display:flex;gap:.4rem;">
                            <a href="<?= url('student/payment.php?request_id=' . $specificRequestId . '&upload=1&channel=gcash') ?>"
                               class="btn btn-sm <?= $method === 'gcash' ? 'btn-primary' : 'btn-soft' ?>">
                               GCash
                            </a>
                            <a href="<?= url('student/payment.php?request_id=' . $specificRequestId . '&upload=1&channel=maya') ?>"
                               class="btn btn-sm <?= $method === 'maya' ? 'btn-primary' : 'btn-soft' ?>">
                               Maya
                            </a>
                            <a href="<?= url('student/payment.php?request_id=' . $specificRequestId . '&upload=1&channel=bank_transfer') ?>"
                               class="btn btn-sm <?= $method === 'bank_transfer' ? 'btn-primary' : 'btn-soft' ?>">
                               Bank Transfer
                            </a>
                        </div>
                    </div>

                    <!-- Explanatory Banner -->
                    <div style="background:rgba(56,189,248,0.08);border:1px solid rgba(56,189,248,0.2);padding:.65rem .85rem;border-radius:8px;margin-bottom:.85rem;">
                        <div style="font-size:.85rem;color:var(--accent-0,#38bdf8);font-weight:600;margin-bottom:.15rem;">
                            University Registrar Receiving Account
                        </div>
                        <div class="muted small">
                            Send exactly <strong><?= peso($request['fee']) ?></strong> from your personal app to the school's account details below, then upload the receipt screenshot.
                        </div>
                    </div>

                    <?php if ($method === 'gcash'): ?>
                        <?php 
                        $gcashQr = get_setting('payment_gcash_qr_image', '');
                        if ($gcashQr): 
                        ?>
                            <div style="text-align:center;margin-bottom:1rem;padding:1rem;background:rgba(255,255,255,.05);border-radius:8px;">
                                <img src="<?= url($gcashQr) ?>" alt="GCash QR Code" 
                                     style="display:block;margin:0 auto;max-width:220px;width:100%;height:auto;border:2px solid rgba(255,255,255,.15);border-radius:12px;">
                                <p class="muted small" style="margin-top:.5rem;">Scan this QR code with your GCash app to pay</p>
                            </div>
                        <?php else: ?>
                            <p class="muted small" style="margin-bottom:1rem;padding:.75rem;background:rgba(255,255,255,.03);border-radius:8px;text-align:center;">Scan-to-pay QR code not yet configured. Please send to the GCash number below.</p>
                        <?php endif; ?>
                        <div style="font-size:1.05rem;">School GCash Number: <strong style="letter-spacing:1px;color:var(--ink-0);"><?= e($gcashNum) ?></strong></div>
                        <div class="muted small">Account Name: <?= e($gcashNm) ?></div>
                    <?php elseif ($method === 'maya'): ?>
                        <div style="font-size:1.05rem;">School Maya Number: <strong style="letter-spacing:1px;color:var(--ink-0);"><?= e($mayaNum) ?></strong></div>
                        <div class="muted small">Account Name: <?= e($mayaNm) ?></div>
                    <?php elseif ($method === 'bank_transfer'): ?>
                        <div style="font-size:1.05rem;">Bank: <strong style="color:var(--ink-0);"><?= e($bankNm) ?></strong></div>
                        <div>Account Number: <strong style="letter-spacing:1px;color:var(--ink-0);"><?= e($bankAcc) ?></strong></div>
                        <div class="muted small">Account Name: <?= e($bankTit) ?></div>
                    <?php endif; ?>
                    <div class="muted small" style="margin-top:.6rem;padding-top:.5rem;border-top:1px solid rgba(255,255,255,.06);">
                        Reference to note: <code><?= e($request['ref_no']) ?></code> &bull; Total Amount: <strong><?= peso($request['fee']) ?></strong>
                    </div>
                    <?php if ($payInstr): ?>
                        <div class="muted small" style="margin-top:.4rem;"><?= nl2br(e($payInstr)) ?></div>
                    <?php endif; ?>
                </div>

                <form method="post" enctype="multipart/form-data" class="stack">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_receipt">
                    <input type="hidden" name="payment_id" value="<?= (int) $request['payment_id'] ?>">
                    <input type="hidden" name="method" value="<?= e($method) ?>">
                    <div class="field">
                        <label>Upload receipt</label>
                        <input type="file" name="receipt" accept="image/jpeg,image/png,image/webp,image/gif" required>
                        <span class="hint">JPG, PNG, or WebP (max 4MB). Make sure the transaction details are clearly visible.</span>
                    </div>
                    <button type="submit" class="btn btn-primary"><?= icon('upload') ?> Upload receipt</button>
                    <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">Cancel</a>
                </form>

            <?php else: ?>
                <?php if ($online): ?>
                <!-- OPTION 1: ONLINE via PayMongo hosted checkout -->
                <div class="panel glass" style="border-left:3px solid var(--accent-0,#38bdf8);margin:16px 0;padding:16px;">
                    <h3 style="margin:0 0 8px 0;font-size:1.05rem;"><?= icon('wallet') ?> Option 1 &mdash; Pay online (instant)</h3>
                    <p class="muted small" style="margin:0 0 12px 0;">
                        You'll be redirected to PayMongo's secure checkout. Pay with GCash, Maya, or card,
                        then be brought back automatically. Payment is confirmed server-side &mdash; no receipt upload needed.
                    </p>
                    <form method="post" class="stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="start_online_payment">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <div class="field">
                            <label>Pay with</label>
                            <select name="channel" required>
                                <option value="gcash">GCash</option>
                                <option value="paymaya">Maya</option>
                                <option value="card">Debit / Credit Card</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary"><?= icon('login') ?> Pay <?= peso($request['fee']) ?> online</button>
                    </form>
                </div>
                <?php endif; ?>

                <!-- OPTION 2: MANUAL -->
                <div class="panel glass" style="margin:16px 0;">
                    <h3 style="margin:0 0 8px 0;font-size:1.05rem;"><?= icon('upload') ?> Option 2 &mdash; Pay manually &amp; upload receipt</h3>
                    <p class="muted small" style="margin:0 0 12px 0;">
                        Pay our official GCash / Maya / bank accounts (see
                        <a href="<?= url('student/instructions.php') ?>" style="text-decoration:underline">payment instructions</a>),
                        then upload a screenshot of your receipt. A cashier verifies it before your request moves to processing.
                    </p>
                    <form method="post" class="stack">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="start_manual_upload">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <div class="field">
                            <label>Paid via</label>
                            <select name="method" required>
                                <option value="gcash">GCash</option>
                                <option value="maya">Maya</option>
                                <option value="bank_transfer">Bank Transfer</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-soft"><?= icon('upload') ?> Continue to upload receipt</button>
                    </form>
                </div>            <?php endif; ?>
        </div>
    <?php elseif (!$requests): ?>
        <div class="panel glass empty-note">
            You have no outstanding payments.<br><br>
            <a class="btn btn-primary" href="<?= url('student/services.php') ?>"><?= icon('plus') ?> Request a document first</a>
        </div>
    <?php else: ?>
        <div class="stack">
        <?php foreach ($requests as $r): ?>
            <div class="panel glass">
                <div class="panel-head">
                    <div>
                        <h2><?= e($r['service_name']) ?></h2>
                        <span class="muted small"><code><?= e($r['ref_no']) ?></code> · <?= nice_date($r['created_at']) ?></span>
                    </div>
                    <div class="panel-actions">
                        <?= status_pill($r['status']) ?>
                        <span class="money"><?= peso($r['fee']) ?></span>
                    </div>
                </div>

                <?php if ($r['payment_status'] === 'paid'): ?>
                    <p class="muted small">Payment completed.
                        <a href="<?= url('student/receipt.php?id=' . (int) $r['id']) ?>">View official receipt →</a>
                    </p>
                <?php elseif ($r['status'] === 'approved'): ?>
                    <?php if ($r['proof_image']): ?>
                        <div class="alert alert-info glass">
                            <?= icon('clock') ?><span>Receipt uploaded. Waiting for cashier verification.</span>
                        </div>
                    <?php else: ?>
                        <a class="btn btn-primary" href="<?= url('student/payment.php?request_id=' . (int) $r['id']) ?>">
                            <?= icon('wallet') ?> Pay online or upload receipt
                        </a>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="muted small">No action needed — this request is already being processed.</p>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>