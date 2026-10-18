<?php
require_once __DIR__ . '/../includes/auth.php';
$admin = require_admin();

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));

$sql = 'SELECT p.*, p.transaction_number AS ref_no, r.ref_no AS req_ref, r.status AS req_status,
            u.full_name, u.student_no, s.name AS service_name,
            rc.receipt_number, rc.issued_at AS receipt_issued_at
     FROM payments p
     JOIN requests r ON r.id = p.request_id
     JOIN students u ON u.id = r.student_id
     JOIN services s ON s.id = r.service_id
     LEFT JOIN receipts rc ON rc.payment_id = p.id
     WHERE 1 = 1';
$params = [];

if ($from !== '') {
    $sql .= ' AND DATE(p.created_at) >= ?';
    $params[] = $from;
}
if ($to !== '') {
    $sql .= ' AND DATE(p.created_at) <= ?';
    $params[] = $to;
}

$sql .= ' ORDER BY p.created_at DESC LIMIT 300';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$totals = [
    'count'  => count($payments),
    'amount' => array_sum(array_map(fn ($p) => (float) $p['amount'], $payments)),
    'paid_count' => count(array_filter($payments, fn ($p) => $p['status'] === 'paid')),
    'paid_amount' => array_sum(array_map(fn ($p) => $p['status'] === 'paid' ? (float) $p['amount'] : 0, $payments)),
];
$byMethod = ['gcash' => 0.0, 'maya' => 0.0, 'card' => 0.0, 'bank_transfer' => 0.0, 'mock' => 0.0];
foreach ($payments as $p) {
    if ($p['status'] === 'paid' && isset($byMethod[$p['method']])) {
        $byMethod[$p['method']] += (float) $p['amount'];
    }
}
$payMethodNames = [
    'gcash'         => 'GCash',
    'maya'          => 'Maya',
    'card'          => 'Card',
    'bank_transfer' => 'Bank Transfer',
    'mock'          => 'Legacy (mock)'
];

$title = 'Payments & Receipt Reports';
require __DIR__ . '/../includes/header.php';
?>

<div class="page">
    <div class="page-head" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;">
        <div>
            <h1>Payments &amp; Receipt Reports</h1>
            <p class="muted">Ledger of all student transactions and generated official receipts.</p>
        </div>
        <div class="head-actions">
            <button class="btn btn-primary btn-sm" onclick="window.print()"><?= icon('receipt') ?> Print / Export Report</button>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="panel glass" style="margin-bottom:1.5rem;padding:1rem 1.25rem;">
        <form method="get" action="<?= url('admin/payments.php') ?>" style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
            <div style="display:flex;align-items:center;gap:.5rem;">
                <label for="from" class="small muted">From:</label>
                <input type="date" id="from" name="from" value="<?= e($from) ?>" style="padding:4px 8px;border-radius:6px;border:1px solid rgba(255,255,255,0.15);background:rgba(0,0,0,0.3);color:inherit;">
            </div>
            <div style="display:flex;align-items:center;gap:.5rem;">
                <label for="to" class="small muted">To:</label>
                <input type="date" id="to" name="to" value="<?= e($to) ?>" style="padding:4px 8px;border-radius:6px;border:1px solid rgba(255,255,255,0.15);background:rgba(0,0,0,0.3);color:inherit;">
            </div>
            <button class="btn btn-soft btn-sm" type="submit"><?= icon('search') ?> Filter</button>
            <?php if ($from !== '' || $to !== ''): ?>
                <a class="btn btn-ghost btn-sm" href="<?= url('admin/payments.php') ?>">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="stat-cards">
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('receipt') ?></span>
            <div><div class="num"><?= $totals['paid_count'] ?> / <?= $totals['count'] ?></div><div class="lbl">Paid / Total records</div></div>
        </div>
        <div class="glass stat-card">
            <span class="stat-icon"><?= icon('money') ?></span>
            <div><div class="num money"><?= peso($totals['paid_amount']) ?></div><div class="lbl">Total verified collection</div></div>
        </div>
        <?php foreach ($byMethod as $method => $sum): ?>
            <div class="glass stat-card">
                <span class="stat-icon"><?= icon('wallet') ?></span>
                <div><div class="num money" style="font-size:1.2rem"><?= peso($sum) ?></div><div class="lbl"><?= e($payMethodNames[$method] ?? ucfirst($method)) ?></div></div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="panel glass">
        <?php if ($payments): ?>
            <div class="table-wrap">
                <table class="data">
                    <thead>
                        <tr>
                            <th>Official Receipt</th>
                            <th>Txn Reference</th>
                            <th>Student</th>
                            <th>Request</th>
                            <th>Document</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Proof</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($p['receipt_number'])): ?>
                                        <strong style="color:var(--color-ok,#10b981)"><code><?= e($p['receipt_number']) ?></code></strong>
                                    <?php else: ?>
                                        <span class="dim small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><code><?= e($p['ref_no']) ?></code></td>
                                <td><?= e($p['full_name']) ?><span class="small dim"><?= e($p['student_no']) ?></span></td>
                                <td><a class="btn btn-ghost btn-sm" href="<?= url('admin/process.php?id=' . (int) $p['request_id']) ?>"><?= e($p['req_ref']) ?></a></td>
                                <td class="small"><?= e($p['service_name']) ?></td>
                                <td><span class="pill pill-info"><?= e($payMethodNames[$p['method']] ?? ucfirst($p['method'])) ?></span></td>
                                <td class="money"><?= peso($p['amount']) ?></td>
                                <td><span class="pill <?= $p['status'] === 'paid' ? 'pill-ok' : ($p['status'] === 'failed' ? 'pill-bad' : 'pill-warn') ?>"><?= ucfirst((string) $p['status']) ?></span></td>
                                <td>
                                    <?php if (!empty($p['proof_image'])): ?>
                                        <a class="btn btn-ghost btn-sm" href="<?= url(e($p['proof_image'])) ?>" target="_blank" rel="noopener"><?= icon('eye') ?> View</a>
                                    <?php else: ?>
                                        <span class="dim small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="muted small"><?= nice_date($p['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-note">No payment records found.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
