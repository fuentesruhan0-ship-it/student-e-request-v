<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

// Determine default tab based on user's field
$isCashier = ($admin['role'] === 'cashier');
$defaultTab = $isCashier ? 'cashier' : 'registrar';
$tab = $_GET['tab'] ?? $defaultTab;

// Cashier is strictly scoped to their own field
if ($isCashier && $tab !== 'cashier') {
    $tab = 'cashier';
}

$validTabs = ['registrar', 'cashier', 'student', 'all'];
if (!in_array($tab, $validTabs, true)) {
    $tab = $defaultTab;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$per  = 25;
$q    = trim((string) ($_GET['q'] ?? ''));

// Build SQL where clause based on selected field
$excludedActions = "'login_success', 'login_failed', 'logout', 'account_linked', 'clerk_school_id_linked', 'clerk_account_created', 'manual_payment_started', 'payment_started', 'notification_sent'";
$baseWhere = "a.action NOT IN ($excludedActions)";

if ($tab === 'registrar') {
    $where = "$baseWhere AND (a.action IN ('request_status', 'service_create', 'service_update', 'service_toggle', 'service_delete', 'instructions_update', 'setting_update'))";
} elseif ($tab === 'cashier') {
    $where = "$baseWhere AND (a.action IN ('receipt_verify', 'receipt_decline', 'payment_verify', 'payment_delete', 'payment_paid', 'payment_failed') OR a.actor_type = 'cashier')";
} elseif ($tab === 'student') {
    $where = "$baseWhere AND (a.action IN ('request_submitted', 'receipt_upload', 'document_received') OR a.actor_type = 'student')";
} else {
    $where = $baseWhere;
}

$params = [];

if ($q !== '') {
    $where .= ' AND (a.detail LIKE ? OR a.action LIKE ? OR st.full_name LIKE ? OR c.full_name LIKE ? OR r.full_name LIKE ? OR st.student_no LIKE ? OR c.employee_no LIKE ? OR r.employee_no LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
}

// Counts for each field (strictly meaningful domain operations)
$regCond = "action NOT IN ($excludedActions) AND (action IN ('request_status', 'service_create', 'service_update', 'service_toggle', 'service_delete', 'instructions_update', 'setting_update'))";
$cshCond = "action NOT IN ($excludedActions) AND (action IN ('receipt_verify', 'receipt_decline', 'payment_verify', 'payment_delete', 'payment_paid', 'payment_failed') OR actor_type = 'cashier')";
$stuCond = "action NOT IN ($excludedActions) AND (action IN ('request_submitted', 'receipt_upload', 'document_received') OR actor_type = 'student')";
$allCond = "action NOT IN ($excludedActions)";

$counts = [
    'registrar' => (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE $regCond")->fetchColumn(),
    'cashier'   => (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE $cshCond")->fetchColumn(),
    'student'   => (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE $stuCond")->fetchColumn(),
    'all'       => (int) db()->query("SELECT COUNT(*) FROM audit_logs WHERE $allCond")->fetchColumn(),
];

// Count total matching current filter
$countSql = "SELECT COUNT(*) FROM audit_logs a
    LEFT JOIN students   st ON a.student_id = st.id
    LEFT JOIN cashiers   c  ON a.cashier_id = c.id
    LEFT JOIN registrars r  ON a.registrar_id = r.id
    WHERE $where";
$countStmt = db()->prepare($countSql);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();

$pages  = max(1, (int) ceil($total / $per));
$page   = min($page, $pages);
$offset = ($page - 1) * $per;

// Fetch logs
$listSql = "SELECT a.*,
            COALESCE(st.full_name, c.full_name, r.full_name, 'System') AS actor_name,
            COALESCE(st.student_no, c.employee_no, r.employee_no, '')   AS actor_id_code,
            a.actor_type AS actor_role
     FROM audit_logs a
     LEFT JOIN students   st ON a.student_id = st.id
     LEFT JOIN cashiers   c  ON a.cashier_id = c.id
     LEFT JOIN registrars r  ON a.registrar_id = r.id
     WHERE $where
     ORDER BY a.created_at DESC
     LIMIT $per OFFSET $offset";
$stmt = db()->prepare($listSql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Clean action titles
$actionLabels = [
    'receipt_verify'        => ['Verified Receipt', 'pill-ok'],
    'receipt_decline'       => ['Declined Receipt', 'pill-bad'],
    'payment_verify'        => ['Manual Payment Verified', 'pill-ok'],
    'payment_paid'          => ['Payment Confirmed', 'pill-ok'],
    'payment_failed'        => ['Payment Failed', 'pill-bad'],
    'payment_delete'        => ['Deleted Payment', 'pill-bad'],
    'request_status'        => ['Updated Status', 'pill-info'],
    'request_submitted'     => ['Submitted Request', 'pill-info'],
    'receipt_upload'        => ['Uploaded Payment Proof', 'pill-warn'],
    'document_received'     => ['Document Claimed', 'pill-ok'],
    'service_create'        => ['Created Service', 'pill-ok'],
    'service_update'        => ['Updated Service', 'pill-warn'],
    'service_toggle'        => ['Toggled Service', 'pill-info'],
    'service_delete'        => ['Deleted Service', 'pill-bad'],
    'instructions_update'   => ['Updated Instructions', 'pill-info'],
    'setting_update'        => ['Updated Setting', 'pill-info'],
    'account_linked'        => ['Institutional ID Linked', 'pill-info'],
    'login_success'         => ['Signed In', 'pill-soft'],
    'logout'                => ['Signed Out', 'pill-soft'],
];

$title = 'Audit Log';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head">
        <h1>Audit Log</h1>
        <p class="muted">Role-specific activity and operational records for accountability.</p>
    </div>

    <!-- Role / Field Tabs -->
    <div class="filter-bar glass" style="margin-bottom:1.25rem;">
        <div class="filter-tabs">
            <?php if (!$isCashier): ?>
                <a class="ftab <?= $tab === 'registrar' ? 'active' : '' ?>" href="<?= url('admin/audit.php?tab=registrar' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                    <?= icon('settings') ?> Registrar Field <span class="ftab-count"><?= $counts['registrar'] ?></span>
                </a>
            <?php endif; ?>
            
            <a class="ftab <?= $tab === 'cashier' ? 'active' : '' ?>" href="<?= url('admin/audit.php?tab=cashier' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                <?= icon('wallet') ?> Cashier Field <span class="ftab-count"><?= $counts['cashier'] ?></span>
            </a>

            <?php if (!$isCashier): ?>
                <a class="ftab <?= $tab === 'student' ? 'active' : '' ?>" href="<?= url('admin/audit.php?tab=student' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                    <?= icon('users') ?> Student Requests <span class="ftab-count"><?= $counts['student'] ?></span>
                </a>
                <a class="ftab <?= $tab === 'all' ? 'active' : '' ?>" href="<?= url('admin/audit.php?tab=all' . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">
                    All Activity <span class="ftab-count"><?= $counts['all'] ?></span>
                </a>
            <?php endif; ?>
        </div>

        <form method="get" action="<?= url('admin/audit.php') ?>" class="filter-search">
            <input type="hidden" name="tab" value="<?= e($tab) ?>">
            <div class="input-icon">
                <?= icon('search') ?>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search actor, reference, detail…">
            </div>
            <button class="btn btn-soft btn-sm" type="submit">Filter</button>
            <?php if ($q !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/audit.php?tab=' . $tab) ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel glass">
        <?php if ($logs): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th style="width:170px;">Date &amp; Time</th>
                            <th>Actor / Staff</th>
                            <th>Field</th>
                            <th>Action</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): 
                            $act = $log['action'];
                            $labelInfo = $actionLabels[$act] ?? [ucwords(str_replace('_', ' ', $act)), 'pill-info'];
                        ?>
                            <tr>
                                <td class="muted small" style="white-space:nowrap;font-size:.85rem;">
                                    <?= nice_date($log['created_at']) ?>
                                </td>
                                <td>
                                    <strong><?= e($log['actor_name']) ?></strong>
                                    <?php if (!empty($log['actor_id_code'])): ?>
                                        <span class="small dim" style="display:block;font-size:.78rem;"><code><?= e($log['actor_id_code']) ?></code></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="pill pill-soft" style="text-transform:capitalize;">
                                        <?= e($log['actor_role']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="pill <?= e($labelInfo[1]) ?>">
                                        <?= e($labelInfo[0]) ?>
                                    </span>
                                </td>
                                <td class="small" style="max-width:400px;line-height:1.45;">
                                    <?= e($log['detail']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($pages > 1): ?>
                <div class="pager" style="margin-top:1.25rem;">
                    <?php if ($page > 1): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= url('admin/audit.php?tab=' . $tab . '&page=' . ($page - 1) . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">← Newer</a>
                    <?php endif; ?>
                    <span class="muted small">Page <?= $page ?> of <?= $pages ?> (<?= $total ?> entries)</span>
                    <?php if ($page < $pages): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= url('admin/audit.php?tab=' . $tab . '&page=' . ($page + 1) . ($q !== '' ? '&q=' . urlencode($q) : '')) ?>">Older →</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-note">
                No activity logs found for this field<?= $q !== '' ? ' matching your search' : '' ?>.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
