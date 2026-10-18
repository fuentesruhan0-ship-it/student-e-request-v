<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$image = $_GET['img'] ?? '';
if (empty($image)) {
    flash_set('error', 'No image specified.');
    redirect('admin/requests.php');
}

// Security: ensure the image is from the uploads directory
if (!str_starts_with($image, UPLOAD_WEB)) {
    flash_set('error', 'Invalid image path.');
    redirect('admin/requests.php');
}

$title = 'View Payment Proof';
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Payment Proof</h1>
    </div>

    <div class="panel glass">
        <img src="<?= url($image) ?>" alt="Payment receipt" style="max-width:100%;height:auto;display:block;margin:0 auto;">
    </div>

    <div class="panel-actions" style="margin-top:20px">
        <a class="btn btn-primary" href="javascript:history.back()"><?= icon('arrow-left') ?> Back</a>
        <a class="btn btn-ghost" href="<?= url('admin/requests.php') ?>">Requests list</a>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
