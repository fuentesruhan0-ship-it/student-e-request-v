<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

$image = $_GET['img'] ?? '';
if (empty($image)) {
    flash_set('error', 'No image specified.');
    redirect('student/requests.php');
}

// Security: ensure the image is from the uploads directory
if (!str_starts_with($image, UPLOAD_WEB)) {
    flash_set('error', 'Invalid image path.');
    redirect('student/requests.php');
}

$title = 'View Uploaded Receipt';
require __DIR__ . '/../includes/header.php';
?>

<div class="page page-narrow">
    <div class="page-head">
        <h1>Uploaded Receipt</h1>
    </div>

    <div class="panel glass">
        <img src="<?= url($image) ?>" alt="Payment receipt" style="max-width:100%;height:auto;display:block;margin:0 auto;">
    </div>

    <div class="panel-actions" style="margin-top:20px">
        <a class="btn btn-primary" href="javascript:history.back()"><?= icon('arrow-left') ?> Back</a>
        <a class="btn btn-ghost" href="<?= url('student/requests.php') ?>">My requests</a>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
