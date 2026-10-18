<?php
require_once __DIR__ . '/../includes/auth.php';
$user = require_student();

/** Summary counters for this student. */
$stmt = db()->prepare(
    'SELECT
        COUNT(*) AS total,
        SUM(status IN ("pending","processing")) AS open_count,
        SUM(status = "ready") AS ready_count,
        SUM(status IN ("ready","released")) AS approved_count,
        SUM(status = "rejected") AS rejected_count
     FROM requests WHERE student_id = ?'
);
$stmt->execute([$user['id']]);
$counts = $stmt->fetch() ?: [];

/** Amount paid vs. amount due across this student's requests. */
$stmt = db()->prepare(
    'SELECT COALESCE(SUM(r.fee),0) AS due
     FROM requests r WHERE r.student_id = ? AND r.status <> "rejected"'
);
$stmt->execute([$user['id']]);
$due = (float) ($stmt->fetch()['due'] ?? 0);

$stmt = db()->prepare(
    'SELECT COALESCE(SUM(p.amount),0) AS paid
     FROM payments p JOIN requests r ON r.id = p.request_id
     WHERE r.student_id = ? AND p.status = "paid"'
);
$stmt->execute([$user['id']]);
$paid = (float) ($stmt->fetch()['paid'] ?? 0);

/** Recent requests with their service info. */
$stmt = db()->prepare(
    'SELECT r.*, s.name AS service_name, s.id AS sid
     FROM requests r JOIN services s ON s.id = r.service_id
     WHERE r.student_id = ? ORDER BY r.created_at DESC LIMIT 6'
);
$stmt->execute([$user['id']]);
$recent = $stmt->fetchAll();

$activeServices = (int) db()->query('SELECT COUNT(*) FROM services WHERE is_active = 1')->fetchColumn();

$title = 'Dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Hello, <?= e(explode(' ', $user['full_name'])[0]) ?> 👋</h1>
        <p class="muted">Here's what's happening with your document requests.</p>
    </div>

    <div class="stat-cards">
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('list') ?></span>
            <div><div class="num"><?= (int) ($counts['total'] ?? 0) ?></div><div class="lbl">Total requests</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('clock') ?></span>
            <div><div class="num"><?= (int) ($counts['open_count'] ?? 0) ?></div><div class="lbl">In progress</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('check') ?></span>
            <div><div class="num"><?= (int) ($counts['ready_count'] ?? 0) ?></div><div class="lbl">Ready to claim</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('wallet') ?></span>
            <div><div class="num money"><?= peso(max(0, $due - $paid)) ?></div><div class="lbl">Outstanding balance</div></div>
        </div>
    </div>

    <?php if ((int) ($counts['ready_count'] ?? 0) > 0): ?>
        <div class="alert alert-success glass" role="status">
            <?= icon('check') ?>
            <span>Good news — <?= (int) $counts['ready_count'] ?> of your documents <?= (int) $counts['ready_count'] === 1 ? 'is' : 'are' ?> ready for claiming. Check <a href="<?= url('student/requests.php') ?>">My Requests</a>.</span>
        </div>
    <?php endif; ?>

    <div class="panel glass">
        <div class="panel-head">
            <h2>Recent requests</h2>
            <div class="panel-actions">
                <a class="btn btn-soft btn-sm" href="<?= url('student/requests.php') ?>">View all</a>
                <a class="btn btn-primary btn-sm" href="<?= url('student/services.php') ?>"><?= icon('plus') ?> New request</a>
            </div>
        </div>

        <?php if ($recent): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Reference</th><th>Document</th><th>Fee</th><th>Status</th><th>Requested</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent as $r): ?>
                            <tr>
                                <td><code data-copy="<?= e($r['ref_no']) ?>"><?= e($r['ref_no']) ?></code></td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td><?= status_pill($r['status']) ?></td>
                                <td class="muted small" data-time="<?= e($r['created_at']) ?>"><?= nice_date($r['created_at']) ?></td>
                                <td><a class="btn btn-ghost btn-sm" href="<?= url('student/requests.php') ?>#r<?= (int) $r['id'] ?>">Details</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">
                You haven't made any requests yet.<br><br>
                <a class="btn btn-primary" href="<?= url('student/services.php') ?>"><?= icon('doc') ?> Browse services</a>
            </div>
        <?php endif; ?>
    </div>

    <div class="two-col">
        <div class="panel glass">
            <div class="panel-head"><h2>Quick actions</h2></div>
            <div class="quick-grid">
                <a class="quick glass" href="<?= url('student/services.php') ?>"><?= icon('doc') ?><span>Request a document</span></a>
                <a class="quick glass" href="<?= url('student/payment.php') ?>"><?= icon('wallet') ?><span>Record a payment</span></a>
                <a class="quick glass" href="<?= url('student/requests.php') ?>"><?= icon('list') ?><span>Track my requests</span></a>
                <a class="quick glass" href="<?= url('student/profile.php') ?>"><?= icon('user') ?><span>Update profile</span></a>
            </div>
        </div>
        <div class="panel glass">
            <div class="panel-head"><h2>Your profile</h2><a class="btn btn-ghost btn-sm" href="<?= url('student/profile.php') ?>">Edit</a></div>
            <dl class="kv">
                <dt>Student No.</dt><dd><code><?= e($user['student_no']) ?></code></dd>
                <dt>Full name</dt><dd><?= e($user['full_name']) ?></dd>
                <dt>Email</dt><dd><?= e($user['email']) ?></dd>
                <dt>Course</dt><dd><?= e($user['course'] ?: '—') ?></dd>
                <dt>Year level</dt><dd><?= e($user['year_level'] ? (string) $user['year_level'] : '—') ?></dd>
                <dt>Member since</dt><dd><?= nice_date($user['created_at']) ?></dd>
            </dl>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>