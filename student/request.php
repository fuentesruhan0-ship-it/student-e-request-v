<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

$services = db()->query('SELECT * FROM services WHERE is_active = 1 ORDER BY name')->fetchAll();
if (!$services) {
    flash_set('error', 'No services are available right now.');
    redirect('student/services.php');
}

$preselect = isset($_GET['service']) ? (int) $_GET['service'] : 0;
$errors    = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $serviceId = (int) ($_POST['service_id'] ?? 0);
    $notes     = trim((string) ($_POST['notes'] ?? ''));

    $stmt = db()->prepare('SELECT * FROM services WHERE id = ? AND is_active = 1');
    $stmt->execute([$serviceId]);
    $service = $stmt->fetch();

    if (!$service) {
        $errors[] = 'Please choose a valid service.';
    }
    if (mb_strlen($notes) > 500) {
        $errors[] = 'Notes are limited to 500 characters.';
    }

    if (!$errors) {
        try {
            $conn = db();
            $stmt = $conn->prepare('CALL sp_submit_request(?, ?, ?, @rid, @ref, @fee)');
            $stmt->execute([$user['id'], $serviceId, $notes !== '' ? $notes : null]);
            $out = $conn->query('SELECT @rid AS rid, @ref AS ref, @fee AS fee')->fetch();

            if (!empty($out['ref'])) {
                flash_set('success', 'Request submitted! Reference: ' . $out['ref'] . '. Wait for registrar approval before payment.');
                redirect('student/requests.php');
            }
            $errors[] = 'Could not submit request. Please try again.';
        } catch (PDOException $e) {
            error_log('Request submission error: ' . $e->getMessage());
            if ($e->getCode() === '45000') {
                $errors[] = $e->getMessage();
            } else {
                $errors[] = 'Unable to process your request at this time. Please try again later.';
            }
        }
    }
}

$title = 'New request';
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>New document request</h1>
        <p class="muted">Pick the document you need — you can add special instructions below.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass" role="alert"><?= icon('alert') ?><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <form method="post" action="<?= url('student/request.php') ?>" class="stack">
        <?= csrf_field() ?>
        <div class="panel glass">
            <div class="field">
                <label for="service_id">Document / service</label>
                <select id="service_id" name="service_id" required>
                    <?php foreach ($services as $s): ?>
                        <option value="<?= (int) $s['id'] ?>"
                            data-fee="<?= e((string) (float) $s['fee']) ?>"
                            data-days="<?= (int) $s['processing_days'] ?>"
                            <?= $preselect === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= e($s['name']) ?> — <?= peso($s['fee']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="notes">Notes <span class="dim">(optional)</span></label>
                <textarea id="notes" name="notes" maxlength="500"
                    placeholder="e.g. For scholarship application"></textarea>
                <span class="hint">Purpose, copies, or special handling instructions.</span>
            </div>

            <dl class="kv summary-kv">
                <dt>Fee</dt><dd class="money-lg" id="fee-preview">—</dd>
                <dt>Processing time</dt><dd id="days-preview" class="muted">—</dd>
            </dl>
        </div>

        <div class="panel-actions">
            <button type="submit" class="btn btn-primary"><?= icon('check') ?> Submit request</button>
            <a class="btn btn-ghost" href="<?= url('student/services.php') ?>">Cancel</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>