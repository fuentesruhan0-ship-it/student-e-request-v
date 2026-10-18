<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$filter = $_GET['status'] ?? 'all';
$valid = ['all', 'pending', 'approved', 'processing', 'ready', 'released', 'rejected'];
if ($admin['role'] === 'cashier') {
    $valid = ['all', 'pending', 'approved', 'processing', 'ready', 'released'];
}
if (!in_array($filter, $valid, true)) {
    $filter = 'all';
}
$q = trim((string) ($_GET['q'] ?? ''));

$sql = 'SELECT r.*, s.name AS service_name, u.full_name, u.student_no,
            p.status AS pay_status
        FROM requests r
        JOIN services s ON s.id = r.service_id
        JOIN students u ON u.id = r.student_id
        LEFT JOIN payments p ON p.request_id = r.id
            AND p.id = (SELECT MAX(p2.id) FROM payments p2 WHERE p2.request_id = r.id)
        WHERE 1 = 1';
$params = [];

// Cashier can see pending and approved requests (not rejected)
if ($admin['role'] === 'cashier') {
    $sql .= ' AND r.status IN ("pending", "approved", "processing", "ready", "released")';
}

if ($filter !== 'all') {
    $sql .= ' AND r.status = ?';
    $params[] = $filter;
}
if ($q !== '') {
    $sql .= ' AND (r.ref_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ? OR s.name LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
$sql .= ' ORDER BY FIELD(r.status, "pending", "approved", "processing", "ready", "released", "rejected"), r.created_at DESC LIMIT 200';

$stmt = db()->prepare($sql);
$stmt->execute($params);
$requests = $stmt->fetchAll();

$countBy = [];
foreach (db()->query('SELECT status, COUNT(*) AS c FROM requests GROUP BY status') as $row) {
    $countBy[$row['status']] = (int) $row['c'];
}

$title = 'Requests';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Student requests</h1>
        <p class="muted">Review, verify and move requests through the pipeline.</p>
    </div>

    <div class="filter-bar glass">
        <div class="filter-tabs">
            <a class="ftab <?= $filter === 'all' ? 'active' : '' ?>" href="<?= url('admin/requests.php') ?>">
                All <span class="ftab-count"><?= array_sum($countBy) ?></span>
            </a>
            <?php
            $statusTabs = ($admin['role'] === 'cashier')
                ? ['pending', 'approved', 'processing', 'ready', 'released']
                : ['pending', 'approved', 'processing', 'ready', 'released', 'rejected'];

            $tabLabels = [
                'pending'    => ($admin['role'] === 'cashier') ? 'Awaiting verification' : 'Pending review',
                'approved'   => 'Approved (Payable)',
                'processing' => 'Processing',
                'ready'      => 'Ready to claim',
                'released'   => 'Released',
                'rejected'   => 'Declined',
            ];

            foreach ($statusTabs as $st):
                $tabLabel = $tabLabels[$st] ?? ucfirst($st);
            ?>
                <a class="ftab <?= $filter === $st ? 'active' : '' ?>"
                   href="<?= url('admin/requests.php?status=' . $st . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                    <?= e($tabLabel) ?> <span class="ftab-count"><?= $countBy[$st] ?? 0 ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <form method="get" action="<?= url('admin/requests.php') ?>" class="filter-search">
            <input type="hidden" name="status" value="<?= e($filter) ?>">
            <div class="input-icon">
                <?= icon('search') ?>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search ref no, student, document…">
            </div>
            <button class="btn btn-soft btn-sm" type="submit">Search</button>
        </form>
    </div>

    <div class="panel glass">
        <?php if ($requests): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr><th>Reference</th><th>Student</th><th>Document</th><th>Fee</th><th>Status</th><th>Requested</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $r): ?>
                            <tr>
                                <td><code><?= e($r['ref_no']) ?></code></td>
                                <td>
                                    <?= e($r['full_name']) ?>
                                    <span class="small dim"><?= e($r['student_no']) ?></span>
                                </td>
                                <td><?= e($r['service_name']) ?></td>
                                <td class="money"><?= peso($r['fee']) ?></td>
                                <td>
                                    <?php if ($admin['role'] === 'cashier'): ?>
                                        <?php if ($r['status'] === 'pending'): ?>
                                            <?= status_pill('pending', 'Awaiting approval') ?>
                                        <?php elseif ($r['status'] === 'approved'): ?>
                                            <?php if ($r['pay_status'] === null): ?>
                                                <?= status_pill('approved', 'Awaiting payment') ?>
                                            <?php elseif ($r['pay_status'] === 'pending'): ?>
                                                <?= status_pill('pending', 'Receipt pending') ?>
                                            <?php elseif ($r['pay_status'] === 'paid'): ?>
                                                <?= status_pill('approved', 'Verified') ?>
                                            <?php elseif ($r['pay_status'] === 'failed'): ?>
                                                <?= status_pill('rejected', 'Declined') ?>
                                            <?php else: ?>
                                                <?= status_pill('approved', 'Awaiting payment') ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?= status_pill($r['status']) ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($r['status'] === 'pending'): ?>
                                            <?= status_pill('pending', 'Pending') ?>
                                        <?php elseif ($r['status'] === 'approved'): ?>
                                            <?php if ($r['pay_status'] === null): ?>
                                                <?= status_pill('approved', 'Awaiting payment') ?>
                                            <?php elseif ($r['pay_status'] === 'paid'): ?>
                                                <?= status_pill('approved', 'Paid') ?>
                                            <?php elseif ($r['pay_status'] === 'failed'): ?>
                                                <?= status_pill('rejected', 'Declined') ?>
                                            <?php else: ?>
                                                <?= status_pill('approved', 'Awaiting payment') ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?= status_pill($r['status']) ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="muted small"><?= nice_date($r['created_at']) ?></td>
                                <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int) $r['id']) ?>"><?= icon('eye') ?> Review</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No requests match this view.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
