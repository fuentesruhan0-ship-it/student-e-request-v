<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_registrar();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $name        = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $fee         = (float) ($_POST['fee'] ?? 0);
        $days        = (int) ($_POST['processing_days'] ?? 1);
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || mb_strlen($name) > 120) {
            $errors[] = 'Service name is required (max 120 characters).';
        } elseif ($fee < 0 || $fee > 100000) {
            $errors[] = 'Fee must be between ₱0 and ₱100,000.';
        } elseif ($days < 1 || $days > 60) {
            $errors[] = 'Processing days must be between 1 and 60.';
        }

        if (!$errors) {
            if ($action === 'create') {
                $stmt = db()->prepare('INSERT INTO services (name, description, fee, processing_days, is_active) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$name, $description, $fee, $days, $isActive]);
                audit_log($admin['id'], 'service_create', 'Created service "' . $name . '" (₱' . number_format($fee, 2) . ')');
                flash_set('success', 'Service "' . $name . '" created.');
            } else {
                $id  = (int) ($_POST['id'] ?? 0);
                $stmt = db()->prepare('UPDATE services SET name = ?, description = ?, fee = ?, processing_days = ?, is_active = ? WHERE id = ?');
                $stmt->execute([$name, $description, $fee, $days, $isActive, $id]);
                audit_log($admin['id'], 'service_update', 'Updated service #' . $id . ' ("' . $name . '")');
                flash_set('success', 'Service updated.');
            }
            redirect('admin/services.php');
        }
    } elseif ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('UPDATE services SET is_active = 1 - is_active WHERE id = ?');
        $stmt->execute([$id]);
        audit_log($admin['id'], 'service_toggle', 'Toggled availability of service #' . $id);
        flash_set('success', 'Service availability updated.');
        redirect('admin/services.php');
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $stmt = db()->prepare('DELETE FROM services WHERE id = ?');
            $stmt->execute([$id]);
            audit_log($admin['id'], 'service_delete', 'Deleted service #' . $id);
            flash_set('success', 'Service deleted.');
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete: this service already has requests. Deactivate it instead.');
        }
        redirect('admin/services.php');
    } elseif ($action === 'save_settings') {
        $subtitle = trim((string) ($_POST['services_subtitle'] ?? ''));
        if (mb_strlen($subtitle) > 500) {
            $errors[] = 'Subtitle too long (max 500 characters).';
        } else {
            try {
                set_setting('services_subtitle', $subtitle);
                audit_log($admin['id'], 'setting_update', 'Updated services section subtitle');
                flash_set('success', 'Section subtitle saved.');
                redirect('admin/services.php');
            } catch (Throwable) {
                $errors[] = 'Could not save settings. Import the site_settings table from database.sql first.';
            }
        }
    }
}

$services_subtitle = get_setting('services_subtitle', 'Official registrar documents with fixed, transparent fees.');
$services = db()->query('SELECT s.*, (SELECT COUNT(*) FROM requests r WHERE r.service_id = s.id) AS request_count FROM services s ORDER BY s.is_active DESC, s.name')->fetchAll();
$edit     = null;
if (isset($_GET['edit'])) {
    $stmt = db()->prepare('SELECT * FROM services WHERE id = ?');
    $stmt->execute([(int) $_GET['edit']]);
    $edit = $stmt->fetch() ?: null;
}

$title = 'Manage services';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Registrar services</h1>
        <p class="muted">Add, price and toggle the documents students can request.</p>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass" role="alert"><?= icon('alert') ?><span><?= e($errors[0]) ?></span></div>
    <?php endif; ?>

    <div class="panel glass">
        <div class="panel-head">
            <h2><?= $edit ? 'Edit service' : 'Add a service' ?></h2>
            <?php if ($edit): ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/services.php') ?>">Cancel edit</a>
            <?php endif; ?>
        </div>
        <form method="post" action="<?= url('admin/services.php') ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $edit ? 'update' : 'create' ?>">
            <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

            <div class="form-grid">
                <div class="field span2">
                    <label for="name">Service name</label>
                    <div class="input-icon"><?= icon('doc') ?>
                        <input id="name" name="name" maxlength="120" required
                               value="<?= e($edit['name'] ?? '') ?>" placeholder="e.g. Certificate of Enrollment">
                    </div>
                </div>
                <div class="field span2">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" maxlength="300"
                              placeholder="Short description shown to students"><?= e($edit['description'] ?? '') ?></textarea>
                </div>
                <div class="field">
                    <label for="fee">Fee (₱)</label>
                    <input type="number" id="fee" name="fee" step="0.01" min="0" max="100000" required
                           value="<?= e((string) ($edit['fee'] ?? '50.00')) ?>">
                </div>
                <div class="field">
                    <label for="processing_days">Processing days</label>
                    <input type="number" id="processing_days" name="processing_days" min="1" max="60" required
                           value="<?= e((string) ($edit['processing_days'] ?? '3')) ?>">
                </div>
            </div>
            <label class="check-row">
                <input type="checkbox" name="is_active" <?= (!isset($edit) || $edit['is_active']) ? 'checked' : '' ?>>
                <span>Available to students</span>
            </label>
            <div class="panel-actions">
                <button type="submit" class="btn btn-primary"><?= icon('plus') ?> <?= $edit ? 'Save changes' : 'Create service' ?></button>
            </div>
        </form>
    </div>

    <div class="panel glass">
        <div class="panel-head">
            <h2>Landing page section</h2>
            <span class="muted small">Customize the text shown on the home page.</span>
        </div>
        <form method="post" action="<?= url('admin/services.php') ?>" class="stack">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_settings">
            <div class="field">
                <label for="services_subtitle">"What you can request" subtitle</label>
                <textarea id="services_subtitle" name="services_subtitle" maxlength="500" rows="2"
                          placeholder="Subtitle text shown below the 'What you can request' heading on the home page"><?= e($services_subtitle) ?></textarea>
                <span class="muted small">Currently shown on the home page as: <em><?= e($services_subtitle) ?></em></span>
            </div>
            <div class="panel-actions">
                <button type="submit" class="btn btn-primary"><?= icon('save') ?> Save subtitle</button>
            </div>
        </form>
    </div>

    <div class="panel glass">
        <div class="panel-head"><h2>All services (<?= count($services) ?>)</h2></div>
        <?php if ($services): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Name</th><th>Fee</th><th>Days</th><th>Requests</th><th>Status</th><th style="width:230px">Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($services as $s): ?>
                            <tr>
                                <td>
                                    <strong><?= e($s['name']) ?></strong>
                                    <?php if ($s['description']): ?><span class="small dim"><?= e(mb_strimwidth($s['description'], 0, 60, '…')) ?></span><?php endif; ?>
                                </td>
                                <td class="money"><?= peso($s['fee']) ?></td>
                                <td><?= (int) $s['processing_days'] ?></td>
                                <td><?= (int) $s['request_count'] ?></td>
                                <td><?= $s['is_active']
                                    ? '<span class="pill pill-ok">Active</span>'
                                    : '<span class="pill pill-bad">Hidden</span>' ?></td>
                                <td>
                                    <div class="row-actions">
                                        <a class="btn btn-ghost btn-sm" href="<?= url('admin/services.php?edit=' . (int) $s['id']) ?>"><?= icon('edit') ?> Edit</a>
                                        <form method="post" action="<?= url('admin/services.php') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="toggle">
                                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                            <button class="btn btn-soft btn-sm" type="submit"><?= $s['is_active'] ? 'Hide' : 'Show' ?></button>
                                        </form>
                                        <form method="post" action="<?= url('admin/services.php') ?>">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                                            <button class="btn btn-danger btn-sm" type="submit" data-confirm="Delete '<?= htmlspecialchars($s['name'], ENT_QUOTES) ?>'? This cannot be undone if it has no requests."><?= icon('trash') ?></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No services yet — create your first one above.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
