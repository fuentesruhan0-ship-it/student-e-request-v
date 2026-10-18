<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_registrar();

$errors = [];

$settingKeys = [
    'services_subtitle'          => 'Official registrar documents with fixed, transparent fees.',
    'instructions_overview'      => 'Welcome to the Student E-Request Portal. Follow the official guidelines below to request, pay for, and claim your university records.',
    'instructions_requirements'  => "1. Valid School ID or Government-issued ID upon claiming.\n2. Settled university clearances if applicable.\n3. Exact Reference Number for fast tracking.",
    'instructions_claiming'      => "Office Hours: Monday - Friday, 8:00 AM - 5:00 PM (Excluding Holidays).\nLocation: Window 3, University Registrar's Office, Main Administration Building.",
    'payment_gcash_number'       => '0917-123-4567',
    'payment_gcash_name'         => 'Registrar Trust Fund',
    'payment_gcash_qr_image'     => '',
    'payment_maya_number'        => '0918-987-6543',
    'payment_maya_name'          => 'Registrar Collection',
    'payment_bank_name'          => 'Land Bank of the Philippines',
    'payment_bank_account'       => '1234-5678-90',
    'payment_bank_account_name'  => 'University Trust Account',
    'payment_instructions'       => "Please include your Request Reference Number in the transfer notes or message field.\nEnsure all receipts clearly display the date, transaction amount, and confirmation reference number.",
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_instructions') {
        try {
            // Handle GCash QR code image upload
            if (!empty($_FILES['payment_gcash_qr_image']) && $_FILES['payment_gcash_qr_image']['error'] === UPLOAD_ERR_OK) {
                $qrPath = save_receipt_upload($_FILES['payment_gcash_qr_image'], $admin['id']);
                if ($qrPath) {
                    set_setting('payment_gcash_qr_image', $qrPath);
                } else {
                    $errors[] = 'Invalid QR code image. Please upload a JPG, PNG, or WebP image (max 4MB).';
                }
            }
            
            foreach ($settingKeys as $key => $defaultVal) {
                if (isset($_POST[$key])) {
                    $val = trim((string) $_POST[$key]);
                    set_setting($key, $val);
                }
            }
            audit_log($admin['id'], 'instructions_update', 'Updated portal instructions and payment details');
            flash_set('success', 'Portal instructions & payment details updated successfully.');
            redirect('admin/instructions.php');
        } catch (Throwable $e) {
            $errors[] = 'Error saving settings: ' . $e->getMessage();
        }
    }
}

// Fetch current values
$currentSettings = [];
foreach ($settingKeys as $key => $defaultVal) {
    $currentSettings[$key] = get_setting($key, $defaultVal);
}

$title = 'Manage Portal Instructions';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <div>
            <h1><?= icon('book') ?> Manage Instructions & Guidelines</h1>
            <p class="muted">Configure request steps, claiming guidelines, and official payment account details shown to students.</p>
        </div>
        <div class="page-actions">
            <a href="<?= url('student/instructions.php') ?>" target="_blank" class="btn btn-ghost btn-sm">
                <?= icon('eye') ?> Preview Student View
            </a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-error glass">
            <?= icon('x') ?><span><?= e($errors[0]) ?></span>
        </div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" class="stack" style="gap:2rem;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_instructions">

        <!-- Section 1: General Request & Claiming Guidelines -->
        <div class="panel glass neu-raised">
            <div class="panel-head">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span class="brand-mark"><?= icon('doc') ?></span>
                    <div>
                        <h2>Document Request & Claiming Guidelines</h2>
                        <p class="muted small">These guidelines are displayed on the public landing page and the student instruction portal.</p>
                    </div>
                </div>
            </div>

            <div class="form-grid">
                <div class="field span2">
                    <label for="services_subtitle">Services Subtitle / Header Note</label>
                    <input type="text" id="services_subtitle" name="services_subtitle"
                           value="<?= e($currentSettings['services_subtitle']) ?>"
                           placeholder="Short summary under Services heading">
                    <span class="hint">Appears under the Services header across public and student portals.</span>
                </div>

                <div class="field span2">
                    <label for="instructions_overview">Overview & Welcome Notice</label>
                    <textarea id="instructions_overview" name="instructions_overview" rows="3"
                              placeholder="General welcome instructions..."><?= e($currentSettings['instructions_overview']) ?></textarea>
                </div>

                <div class="field">
                    <label for="instructions_requirements">Standard Requirements (one per line)</label>
                    <textarea id="instructions_requirements" name="instructions_requirements" rows="4"
                              placeholder="Required documents or clearance..."><?= e($currentSettings['instructions_requirements']) ?></textarea>
                    <span class="hint">Students will see these before claiming their documents.</span>
                </div>

                <div class="field">
                    <label for="instructions_claiming">Claiming Schedule & Location</label>
                    <textarea id="instructions_claiming" name="instructions_claiming" rows="4"
                              placeholder="Office hours, building, room..."><?= e($currentSettings['instructions_claiming']) ?></textarea>
                    <span class="hint">Specify registrar windows, operating hours, and authorized claiming policies.</span>
                </div>
            </div>
        </div>

        <!-- Section 2: Official Payment Channels & Accounts -->
        <div class="panel glass neu-raised">
            <div class="panel-head">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span class="brand-mark"><?= icon('wallet') ?></span>
                    <div>
                        <h2>Official Payment Channels & Account Details</h2>
                        <p class="muted small">Students will use these numbers and bank details when completing their payments.</p>
                    </div>
                </div>
            </div>

            <div class="form-grid">
                <!-- GCash -->
                <div class="field">
                    <label for="payment_gcash_number">GCash Registered Number</label>
                    <div class="input-icon">
                        <?= icon('phone') ?>
                        <input type="text" id="payment_gcash_number" name="payment_gcash_number"
                               value="<?= e($currentSettings['payment_gcash_number']) ?>" placeholder="e.g. 0917-XXX-XXXX">
                    </div>
                </div>
                <div class="field">
                    <label for="payment_gcash_name">GCash Account Name</label>
                    <div class="input-icon">
                        <?= icon('user') ?>
                        <input type="text" id="payment_gcash_name" name="payment_gcash_name"
                               value="<?= e($currentSettings['payment_gcash_name']) ?>" placeholder="e.g. University Registrar">
                    </div>
                </div>

                <div class="field span2">
                    <label for="payment_gcash_qr_image">GCash QR Code Image (optional)</label>
                    <input type="file" id="payment_gcash_qr_image" name="payment_gcash_qr_image" 
                           accept="image/jpeg,image/png,image/webp,image/gif">
                    <span class="hint">Upload a QR code image for students to scan. JPG, PNG, or WebP (max 4MB).</span>
                    <?php 
                    $existingQr = get_setting('payment_gcash_qr_image', '');
                    if ($existingQr): 
                    ?>
                        <div style="margin-top:8px;">
                            <img src="<?= url($existingQr) ?>" alt="Current GCash QR" 
                                 style="max-width:200px;border:1px solid rgba(255,255,255,.1);border-radius:8px;">
                            <p class="muted small">Current QR code (will be replaced if you upload a new one)</p>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Maya -->
                <div class="field">
                    <label for="payment_maya_number">Maya Registered Number</label>
                    <div class="input-icon">
                        <?= icon('phone') ?>
                        <input type="text" id="payment_maya_number" name="payment_maya_number"
                               value="<?= e($currentSettings['payment_maya_number']) ?>" placeholder="e.g. 0918-XXX-XXXX">
                    </div>
                </div>
                <div class="field">
                    <label for="payment_maya_name">Maya Account Name</label>
                    <div class="input-icon">
                        <?= icon('user') ?>
                        <input type="text" id="payment_maya_name" name="payment_maya_name"
                               value="<?= e($currentSettings['payment_maya_name']) ?>" placeholder="e.g. University Collection">
                    </div>
                </div>

                <!-- Bank Transfer -->
                <div class="field">
                    <label for="payment_bank_name">Depository Bank Name</label>
                    <div class="input-icon">
                        <?= icon('shield') ?>
                        <input type="text" id="payment_bank_name" name="payment_bank_name"
                               value="<?= e($currentSettings['payment_bank_name']) ?>" placeholder="e.g. Land Bank of the Philippines">
                    </div>
                </div>
                <div class="field">
                    <label for="payment_bank_account">Bank Account Number</label>
                    <div class="input-icon">
                        <?= icon('doc') ?>
                        <input type="text" id="payment_bank_account" name="payment_bank_account"
                               value="<?= e($currentSettings['payment_bank_account']) ?>" placeholder="e.g. 1234-5678-90">
                    </div>
                </div>
                <div class="field span2">
                    <label for="payment_bank_account_name">Bank Account Name / Beneficiary Title</label>
                    <div class="input-icon">
                        <?= icon('user') ?>
                        <input type="text" id="payment_bank_account_name" name="payment_bank_account_name"
                               value="<?= e($currentSettings['payment_bank_account_name']) ?>" placeholder="e.g. University Trust Fund">
                    </div>
                </div>

                <!-- Payment Reminders -->
                <div class="field span2">
                    <label for="payment_instructions">Payment Reminders & Receipt Instructions</label>
                    <textarea id="payment_instructions" name="payment_instructions" rows="3"
                              placeholder="Important reminders for receipt uploads..."><?= e($currentSettings['payment_instructions']) ?></textarea>
                    <span class="hint">Displayed at checkout and on receipt upload pages.</span>
                </div>
            </div>
        </div>

        <div style="display:flex;justify-content:flex-end;gap:1rem;margin-top:1rem;">
            <a href="<?= url('admin/dashboard.php') ?>" class="btn btn-ghost">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg neu-raised">
                <?= icon('check') ?> Save All Instructions & Details
            </button>
        </div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
