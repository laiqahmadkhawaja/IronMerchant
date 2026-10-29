<?php
// bills.php - Sales Bills List (Multi-Page)
declare(strict_types=1);

$pageTitle = 'Sales Bills (سیل بلز)';
require_once __DIR__ . '/includes/header.php';
requirePerm('bills');

$db = getDB();
$search = trim((string)($_GET['search'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(s.invoice_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($fromDate !== '') {
    $where[] = "s.sale_date >= ?";
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $where[] = "s.sale_date <= ?";
    $params[] = $toDate;
}

$whereSql = implode(' AND ', $where);

// Total counts & stats
$statsStmt = $db->prepare("
    SELECT COUNT(s.id), COALESCE(SUM(s.grand_total), 0), COALESCE(SUM(s.paid_amount), 0), COALESCE(SUM(s.due_amount), 0)
    FROM sales s
    JOIN parties p ON s.customer_id = p.id
    WHERE {$whereSql}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_NUM);
$totalCount = (int)$stats[0];
$totalGrand = (float)$stats[1];
$totalPaid = (float)$stats[2];
$totalDue = (float)$stats[3];

// Bills list
$stmt = $db->prepare("
    SELECT 
        s.id, s.invoice_no, s.sale_date, s.sale_time, s.customer_id, s.customer_name_custom, s.customer_phone_custom,
        p.name AS customer_name, p.phone AS customer_phone,
        s.subtotal, s.labour_charge, s.delivery_charge, s.additional_charges, s.discount, s.grand_total, s.paid_amount, s.due_amount,
        s.payment_status, s.payment_method, (s.grand_total - s.total_cogs) AS profit, s.status
    FROM sales s
    JOIN parties p ON s.customer_id = p.id
    WHERE {$whereSql}
    ORDER BY s.id DESC
    LIMIT 100
");
$stmt->execute($params);
$bills = $stmt->fetchAll();
$isOwner = ($role === 'Owner');
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1" style="color:var(--nv);"><i class="fa-solid fa-file-invoice me-2 text-primary"></i> Sales Bills List · فروخت شدہ بلز</h3>
    <small class="text-secondary">Total <?= $totalCount ?> bills found · Total: <?= formatMoney($totalGrand) ?> · Due: <span class="text-danger fw-bold"><?= formatMoney($totalDue) ?></span></small>
  </div>
  <div>
    <a href="pos.php" class="btn btn-zb-gold"><i class="fa-solid fa-plus me-1"></i> New Bill (نیا بل بنائیں)</a>
  </div>
</div>

<div class="zb-card">
  <!-- Search & Filter Form -->
  <form method="GET" action="bills.php" class="row g-2 align-items-end mb-3">
    <div class="col-12 col-md-5">
      <label class="form-label">Search Invoice #, Customer or Phone</label>
      <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="e.g. BILL-2026 or Haji Munir">
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

  <!-- Table -->
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Date &amp; Time</th>
          <th>Customer</th>
          <th>Grand Total</th>
          <th>Paid</th>
          <th>Due</th>
          <th>Status</th>
          <?php if ($isOwner): ?><th>Profit</th><?php endif; ?>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($bills)): ?>
          <tr><td colspan="<?= $isOwner ? 9 : 8 ?>" class="text-center py-4 text-muted">No sales bills found.</td></tr>
        <?php else: ?>
          <?php foreach ($bills as $b): ?>
            <tr>
              <td><strong><?= htmlspecialchars($b['invoice_no']) ?></strong></td>
              <td><?= $b['sale_date'] ?> <small class="text-muted"><?= $b['sale_time'] ?></small></td>
              <td>
                <?php if (!empty($b['customer_name_custom'])): ?>
                  <strong><?= htmlspecialchars($b['customer_name_custom']) ?></strong>
                  <br><small class="text-muted"><?= htmlspecialchars($b['customer_name']) ?></small>
                <?php else: ?>
                  <strong><?= htmlspecialchars($b['customer_name']) ?></strong>
                <?php endif; ?>
                <?php 
                  $dispP = !empty($b['customer_phone_custom']) ? $b['customer_phone_custom'] : ($b['customer_phone'] ?? '');
                  if (!empty($dispP)): 
                ?>
                  <br><small class="text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($dispP) ?></small>
                <?php endif; ?>
              </td>
              <td class="fw-bold"><?= formatMoney($b['grand_total']) ?></td>
              <td><?= formatMoney($b['paid_amount']) ?></td>
              <td class="<?= (float)$b['due_amount'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= formatMoney($b['due_amount']) ?></td>
              <td>
                <?php if ($b['status'] === 'cancelled'): ?>
                  <span class="badge bg-secondary">Cancelled</span>
                <?php elseif ($b['payment_status'] === 'Paid'): ?>
                  <span class="badge-paid">Paid</span>
                <?php elseif ($b['payment_status'] === 'Partial'): ?>
                  <span class="badge-partial">Partial</span>
                <?php else: ?>
                  <span class="badge-unpaid">Unpaid</span>
                <?php endif; ?>
              </td>
              <?php if ($isOwner): ?>
                <td class="text-success fw-bold"><?= formatMoney($b['profit']) ?></td>
              <?php endif; ?>
              <td class="text-end">
                <a href="print_bill.php?id=<?= $b['id'] ?>&size=80" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-print me-1"></i> Print</a>
                <?php if ($isOwner && $b['status'] === 'posted'): ?>
                  <button type="button" class="btn btn-sm btn-outline-danger" onclick="cancelSaleBill(<?= $b['id'] ?>, '<?= $b['invoice_no'] ?>')">
                    Cancel
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
  async function cancelSaleBill(id, no) {
    const reason = prompt(`بل نمبر ${no} منسوخ (Cancel) کرنے کی وجہ لکھیں:`);
    if (!reason) return;

    const fd = new FormData();
    fd.append('id', id);
    fd.append('reason', reason);

    const res = await fetch('ajax/sales.php?action=cancel', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.success) {
      alert(json.message);
      window.location.reload();
    } else {
      alert(json.message || 'بل منسوخ کرنے میں خرابی ہوئی۔');
    }
  }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
