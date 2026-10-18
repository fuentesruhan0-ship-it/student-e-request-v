<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$stats = [
    'students'   => (int) db()->query('SELECT COUNT(*) FROM students')->fetchColumn(),
    'pending'    => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "pending"')->fetchColumn(),
    'processing' => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "processing"')->fetchColumn(),
    'ready'      => (int) db()->query('SELECT COUNT(*) FROM requests WHERE status = "ready"')->fetchColumn(),
    'revenue'    => (float) db()->query('SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = "paid"')->fetchColumn(),
];

/** Request pipeline: newest first, joined with student + service. */
$pipeline = db()->query(
    'SELECT r.*, s.name AS service_name, u.full_name, u.student_no
     FROM requests r
     JOIN services s ON s.id = r.service_id
     JOIN students u ON u.id = r.student_id
     ORDER BY FIELD(r.status, "pending", "processing", "ready", "released", "rejected"), r.created_at DESC
     LIMIT 12'
)->fetchAll();

$recentPayments = db()->query(
    'SELECT p.*, p.transaction_number AS ref_no, r.ref_no AS req_ref, u.full_name
     FROM payments p
     JOIN requests r ON r.id = p.request_id
     JOIN students u ON u.id = r.student_id
     ORDER BY p.created_at DESC LIMIT 6'
)->fetchAll();

$title = 'Admin dashboard';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Admin overview</h1>
        <p class="muted">Pipeline health, revenue and the latest student activity.</p>
    </div>

    <div class="stat-cards">
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('users') ?></span>
            <div><div class="num"><?= $stats['students'] ?></div><div class="lbl">Registered students</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('clock') ?></span>
            <div><div class="num"><?= $stats['pending'] ?></div><div class="lbl">Awaiting payment</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('settings') ?></span>
            <div><div class="num"><?= $stats['processing'] ?></div><div class="lbl">Processing</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('check') ?></span>
            <div><div class="num"><?= $stats['ready'] ?></div><div class="lbl">Ready to release</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('money') ?></span>
            <div><div class="num money"><?= peso($stats['revenue']) ?></div><div class="lbl">Collected payments</div></div>
        </div>
    </div>

    <div class="panel glass">
        <div class="panel-head">
            <h2>Request pipeline</h2>
            <a class="btn btn-soft btn-sm" href="<?= url('admin/requests.php') ?>">Open full list</a>
        </div>
        <?php if ($pipeline): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Reference</th><th>Student</th><th>Document</th><th>Fee</th><th>Status</th><th>Updated</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pipeline as $r): ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td><?= e($r['full_name']) ?><span class="small dim"><?= e($r['student_no']) ?></span></td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td><?= status_pill($r['status']) ?></td>
                                <td class="muted small"><?= nice_date($r['updated_at']) ?></td>
                                <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int) $r['id']) ?>">Open</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No requests yet. They will appear here as students submit them.</div>
        <?php endif; ?>
    </div>

    <div class="panel glass">
        <div class="panel-head">
            <h2>Latest payments</h2>
            <a class="btn btn-soft btn-sm" href="<?= url('admin/payments.php') ?>">All payments</a>
        </div>
        <?php if ($recentPayments): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead><tr><th>Reference</th><th>Student</th><th>For request</th><th>Method</th><th>Amount</th><th>When</th></tr></thead>
                    <tbody>
                        <?php foreach ($recentPayments as $p): ?>
                            <tr>
                                <td><code><?= e($p['ref_no']) ?></code></td>
                                <td><?= e($p['full_name']) ?></td>
                                <td class="small"><?= e($p['req_ref']) ?></td>
                                <td class="muted"><?= e(ucfirst($p['method'])) ?></td>
                                <td class="money"><?= peso($p['amount']) ?></td>
                                <td class="muted small"><?= nice_date($p['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No payments recorded yet.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
