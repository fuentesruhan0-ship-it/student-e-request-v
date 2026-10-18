<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

$overview      = get_setting('instructions_overview', 'Welcome to the Student E-Request Portal. Follow the official guidelines below to request, pay for, and claim your university records.');
$requirements  = get_setting('instructions_requirements', "1. Valid School ID or Government-issued ID upon claiming.\n2. Settled university clearances if applicable.\n3. Exact Reference Number for fast tracking.");
$claiming      = get_setting('instructions_claiming', "Office Hours: Monday - Friday, 8:00 AM - 5:00 PM (Excluding Holidays).\nLocation: Window 3, University Registrar's Office, Main Administration Building.");
$gcashNumber   = get_setting('payment_gcash_number', '0917-123-4567');
$gcashName     = get_setting('payment_gcash_name', 'Registrar Trust Fund');
$mayaNumber    = get_setting('payment_maya_number', '0918-987-6543');
$mayaName      = get_setting('payment_maya_name', 'Registrar Collection');
$bankName      = get_setting('payment_bank_name', 'Land Bank of the Philippines');
$bankAccount   = get_setting('payment_bank_account', '1234-5678-90');
$bankAcctName  = get_setting('payment_bank_account_name', 'University Trust Account');
$paymentNotes  = get_setting('payment_instructions', "Please include your Request Reference Number in the transfer notes or message field.\nEnsure all receipts clearly display the date, transaction amount, and confirmation reference number.");

$title = 'Request & Payment Instructions';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <div>
            <h1><?= icon('book') ?> Request & Payment Instructions</h1>
            <p class="muted">Official registrar guidelines, payment methods, and document claiming procedures.</p>
        </div>
        <div class="page-actions">
            <a href="<?= url('student/services.php') ?>" class="btn btn-primary btn-sm neu-raised">
                <?= icon('plus') ?> Request a Document
            </a>
            <a href="<?= url('student/requests.php') ?>" class="btn btn-ghost btn-sm">
                <?= icon('list') ?> My Requests
            </a>
        </div>
    </div>

    <!-- Overview Banner -->
    <div class="panel glass neu-raised" style="border-left: 4px solid var(--accent-0, #38bdf8);margin-bottom:2rem;">
        <div style="display:flex;align-items:flex-start;gap:1.25rem;">
            <span class="brand-mark brand-mark-lg" style="background:linear-gradient(135deg, rgba(56,189,248,.25), rgba(129,140,248,.25));">
                <?= icon('graduation', 'ic ic-lg') ?>
            </span>
            <div>
                <h2 style="margin:0 0 .5rem 0;">Student Portal Notice</h2>
                <p style="font-size:1.05rem;line-height:1.6;color:var(--ink-1);margin:0;">
                    <?= nl2br(e($overview)) ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Step by Step Workflow Guide -->
    <h2 style="margin:2.5rem 0 1rem 0;display:flex;align-items:center;gap:.6rem;">
        <?= icon('grid') ?> 5-Step Request Process
    </h2>
    <div class="stat-grid" style="grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));gap:1.25rem;margin-bottom:2.5rem;">
        <div class="stat-card glass neu-raised" style="position:relative;overflow:hidden;">
            <div style="font-size:2rem;font-weight:800;color:var(--accent-0,#38bdf8);opacity:.4;position:absolute;top:.5rem;right:1rem;">01</div>
            <div class="stat-label"><?= icon('doc') ?> Step 1</div>
            <div class="stat-value" style="font-size:1.2rem;margin:.5rem 0;">Submit Request</div>
            <p class="muted small" style="margin:0;">Choose your required credential from the catalog and provide any requested details.</p>
        </div>
        <div class="stat-card glass neu-raised" style="position:relative;overflow:hidden;">
            <div style="font-size:2rem;font-weight:800;color:var(--accent-0,#38bdf8);opacity:.4;position:absolute;top:.5rem;right:1rem;">02</div>
            <div class="stat-label"><?= icon('shield') ?> Step 2</div>
            <div class="stat-value" style="font-size:1.2rem;margin:.5rem 0;">Registrar Review</div>
            <p class="muted small" style="margin:0;">The registrar evaluates your student standing and approves the document for payment.</p>
        </div>
        <div class="stat-card glass neu-raised" style="position:relative;overflow:hidden;">
            <div style="font-size:2rem;font-weight:800;color:var(--accent-0,#38bdf8);opacity:.4;position:absolute;top:.5rem;right:1rem;">03</div>
            <div class="stat-label"><?= icon('wallet') ?> Step 3</div>
            <div class="stat-value" style="font-size:1.2rem;margin:.5rem 0;">Pay & Upload</div>
            <p class="muted small" style="margin:0;">Pay via GCash, Maya, Card, or Bank and upload your receipt screenshot.</p>
        </div>
        <div class="stat-card glass neu-raised" style="position:relative;overflow:hidden;">
            <div style="font-size:2rem;font-weight:800;color:var(--accent-0,#38bdf8);opacity:.4;position:absolute;top:.5rem;right:1rem;">04</div>
            <div class="stat-label"><?= icon('clock') ?> Step 4</div>
            <div class="stat-value" style="font-size:1.2rem;margin:.5rem 0;">Verification</div>
            <p class="muted small" style="margin:0;">Cashier confirms your payment and printing/sealing of credentials commences.</p>
        </div>
        <div class="stat-card glass neu-raised" style="position:relative;overflow:hidden;">
            <div style="font-size:2rem;font-weight:800;color:var(--accent-0,#38bdf8);opacity:.4;position:absolute;top:.5rem;right:1rem;">05</div>
            <div class="stat-label"><?= icon('check') ?> Step 5</div>
            <div class="stat-value" style="font-size:1.2rem;margin:.5rem 0;">Claim Document</div>
            <p class="muted small" style="margin:0;">Receive email notification and present your ID at the Registrar's Office.</p>
        </div>
    </div>

    <!-- Official Payment Channels -->
    <h2 style="margin:2.5rem 0 1rem 0;display:flex;align-items:center;gap:.6rem;">
        <?= icon('wallet') ?> Official Payment Channels
    </h2>
    <div class="stat-grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));gap:1.5rem;margin-bottom:2.5rem;">
        <!-- GCash Card -->
        <div class="panel glass neu-raised" style="background:linear-gradient(145deg, rgba(30,58,138,.15), rgba(15,23,42,.6));border-color:rgba(59,130,246,.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <span style="font-size:1.2rem;font-weight:700;color:#60a5fa;display:flex;align-items:center;gap:.5rem;">
                    <?= icon('phone') ?> GCash
                </span>
                <span class="status-pill status-approved" style="font-size:.75rem;">Verified Channel</span>
            </div>
            <dl class="kv" style="margin:0 0 1rem 0;">
                <dt>Account Number</dt>
                <dd><strong style="font-size:1.15rem;letter-spacing:1px;color:var(--ink-0);"><?= e($gcashNumber) ?></strong></dd>
                <dt>Account Name</dt>
                <dd><?= e($gcashName) ?></dd>
            </dl>
            <p class="muted small" style="margin:0;">Always include your Reference Number (e.g. <code>REQ-XXXX</code>) in the message/notes field.</p>
        </div>

        <!-- Maya Card -->
        <div class="panel glass neu-raised" style="background:linear-gradient(145deg, rgba(6,78,59,.15), rgba(15,23,42,.6));border-color:rgba(16,185,129,.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <span style="font-size:1.2rem;font-weight:700;color:#34d399;display:flex;align-items:center;gap:.5rem;">
                    <?= icon('phone') ?> Maya
                </span>
                <span class="status-pill status-approved" style="font-size:.75rem;">Verified Channel</span>
            </div>
            <dl class="kv" style="margin:0 0 1rem 0;">
                <dt>Account Number</dt>
                <dd><strong style="font-size:1.15rem;letter-spacing:1px;color:var(--ink-0);"><?= e($mayaNumber) ?></strong></dd>
                <dt>Account Name</dt>
                <dd><?= e($mayaName) ?></dd>
            </dl>
            <p class="muted small" style="margin:0;">Save your transfer confirmation screenshot and upload via the Payments tab.</p>
        </div>

        <!-- Bank Transfer Card -->
        <div class="panel glass neu-raised" style="background:linear-gradient(145deg, rgba(88,28,135,.15), rgba(15,23,42,.6));border-color:rgba(168,85,247,.25);">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem;">
                <span style="font-size:1.2rem;font-weight:700;color:#c084fc;display:flex;align-items:center;gap:.5rem;">
                    <?= icon('shield') ?> Bank Transfer
                </span>
                <span class="status-pill status-approved" style="font-size:.75rem;">Direct Deposit</span>
            </div>
            <dl class="kv" style="margin:0 0 1rem 0;">
                <dt>Bank Name</dt>
                <dd><?= e($bankName) ?></dd>
                <dt>Account Number</dt>
                <dd><strong style="font-size:1.15rem;letter-spacing:1px;color:var(--ink-0);"><?= e($bankAccount) ?></strong></dd>
                <dt>Account Name</dt>
                <dd><?= e($bankAcctName) ?></dd>
            </dl>
            <p class="muted small" style="margin:0;">Accepted via online banking or over-the-counter deposit slip.</p>
        </div>
    </div>

    <!-- Requirements and Claiming Split -->
    <div class="form-grid" style="margin-bottom:2.5rem;">
        <!-- Requirements -->
        <div class="panel glass neu-raised">
            <div class="panel-head">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span class="brand-mark"><?= icon('check') ?></span>
                    <h2 style="margin:0;">Claiming Requirements</h2>
                </div>
            </div>
            <div style="line-height:1.7;color:var(--ink-1);">
                <?= nl2br(e($requirements)) ?>
            </div>
            <div class="alert alert-info glass" style="margin-top:1.5rem;font-size:.9rem;">
                <?= icon('alert') ?>
                <span>If an authorized representative is claiming on your behalf, provide a signed authorization letter and a photocopy of both IDs.</span>
            </div>
        </div>

        <!-- Schedule & Claiming Location -->
        <div class="panel glass neu-raised">
            <div class="panel-head">
                <div style="display:flex;align-items:center;gap:.75rem;">
                    <span class="brand-mark"><?= icon('clock') ?></span>
                    <h2 style="margin:0;">Office Hours & Location</h2>
                </div>
            </div>
            <div style="line-height:1.7;color:var(--ink-1);margin-bottom:1.5rem;">
                <?= nl2br(e($claiming)) ?>
            </div>
            <div class="panel neu-inset" style="padding:1rem;background:rgba(0,0,0,.25);border-radius:12px;">
                <strong style="display:block;margin-bottom:.5rem;color:var(--accent-0,#38bdf8);font-size:.95rem;">
                    <?= icon('mail') ?> Email Notifications
                </strong>
                <p class="muted small" style="margin:0;">
                    You will receive automated email alerts as soon as your request transitions to Approved, Payment Verified, Ready for Claiming, or Released.
                </p>
            </div>
        </div>
    </div>

    <!-- Bottom Actions -->
    <div class="panel glass neu-raised" style="text-align:center;padding:2.5rem 1.5rem;">
        <h3 style="margin:0 0 .75rem 0;font-size:1.4rem;">Ready to request your documents?</h3>
        <p class="muted" style="max-width:540px;margin:0 auto 1.5rem auto;">
            Browse our catalog of official registrar credentials, certificates, and transcripts with transparent processing times and fees.
        </p>
        <div style="display:flex;justify-content:center;gap:1rem;flex-wrap:wrap;">
            <a href="<?= url('student/services.php') ?>" class="btn btn-primary btn-lg neu-raised">
                <?= icon('plus') ?> View Available Services
            </a>
            <a href="<?= url('student/requests.php') ?>" class="btn btn-ghost btn-lg">
                <?= icon('list') ?> Track Existing Requests
            </a>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
