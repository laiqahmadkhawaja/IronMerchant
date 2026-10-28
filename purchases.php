<?php
// purchases.php - Purchases List
declare(strict_types=1);

$pageTitle = 'Purchases & Landed Cost (خریداری)';
require_once __DIR__ . '/includes/header.php';
requirePerm('purchases');

$db = getDB();
$search = trim((string)($_GET['search'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(pr.purchase_no LIKE ? OR p.name LIKE ? OR pr.supplier_inv_no LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($fromDate !== '') {
    $where[] = "pr.purchase_date >= ?";
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $where[] = "pr.purchase_date <= ?";
    $params[] = $toDate;
}

$whereSql = implode(' AND ', $where);

// Total counts & stats
$statsStmt = $db->prepare("
    SELECT COUNT(pr.id), COALESCE(SUM(pr.grand_total), 0), COALESCE(SUM(pr.paid_amount), 0), COALESCE(SUM(pr.due_amount), 0)
    FROM purchases pr
    JOIN parties p ON pr.supplier_id = p.id
    WHERE {$whereSql}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_NUM);
$totalCount = (int)$stats[0];
$totalGrand = (float)$stats[1];
$totalPaid = (float)$stats[2];
$totalDue = (float)$stats[3];

// Purchases list
$stmt = $db->prepare("
    SELECT 
        pr.id, pr.purchase_no, pr.purchase_date, pr.supplier_inv_no, pr.supplier_id, p.name AS supplier_name,
        pr.subtotal, pr.additional_charges, pr.discount, pr.grand_total, pr.paid_amount, pr.due_amount,
        pr.payment_status, pr.status
    FROM purchases pr
    JOIN parties p ON pr.supplier_id = p.id
    WHERE {$whereSql}
    ORDER BY pr.id DESC
    LIMIT 100
");
$stmt->execute($params);
$purchases = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1" style="color:var(--nv);"><i class="fa-solid fa-truck-ramp-box me-2 text-primary"></i> Purchases &amp; Landed Cost · مال خریداری</h3>
    <small class="text-secondary">Accurate landed cost calculation: Base price + Labour/Freight allocated to stock</small>
  </div>
  <div>
    <a href="purchase_form.php" class="btn btn-zb-gold"><i class="fa-solid fa-plus me-1"></i> New Purchase Entry (نئی خریداری)</a>
  </div>
</div>

<div class="zb-card">
  <!-- Search Form -->
  <form method="GET" action="purchases.php" class="row g-2 align-items-end mb-3">
    <div class="col-12 col-md-5">
      <label class="form-label">Search Purchase #, Mill or Supplier</label>
      <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="e.g. Mughal Steel or PUR-2026">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label">From Date</label>
      <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label">To Date</label>
      <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
    </div>
    <div class="col-12 col-md-1">
      <button type="submit" class="btn btn-zb-primary w-100"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
  </form>

  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Purchase #</th>
          <th>Date</th>
          <th>Supplier / Mill</th>
          <th>Items Base</th>
          <th>Landed Charges</th>
          <th>Total Landed Cost</th>
          <th>Paid</th>
          <th>Due Payable</th>
          <th>Status</th>
          <th class="text-end">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($purchases)): ?>
          <tr><td colspan="10" class="text-center py-4 text-muted">No purchase records found.</td></tr>
        <?php else: ?>
          <?php foreach ($purchases as $p): ?>
            <tr>
              <td><strong><?= htmlspecialchars($p['purchase_no']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($p['supplier_inv_no'] ?? '') ?></small></td>
              <td><?= $p['purchase_date'] ?></td>
              <td><strong><?= htmlspecialchars($p['supplier_name']) ?></strong></td>
              <td><?= formatMoney($p['subtotal']) ?></td>
              <td class="text-primary fw-bold">+ <?= formatMoney($p['additional_charges']) ?></td>
              <td class="fw-bold"><?= formatMoney($p['grand_total']) ?></td>
              <td><?= formatMoney($p['paid_amount']) ?></td>
              <td class="<?= (float)$p['due_amount'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= formatMoney($p['due_amount']) ?></td>
              <td>
                <?php if ($p['payment_status'] === 'Paid'): ?>
                  <span class="badge-paid">Paid</span>
                <?php elseif ($p['payment_status'] === 'Partial'): ?>
                  <span class="badge-partial">Partial</span>
                <?php else: ?>
                  <span class="badge-unpaid">Unpaid</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <a href="print_purchase.php?id=<?= $p['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-print me-1"></i> Print / رسید</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
