<?php
// index.php - Dashboard (Multi-Page Architecture)
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

requireAuth();

// If user is not allowed to view the dashboard, route them to their assigned allowed page
if (!hasPermission('dash')) {
    $firstAllowed = getFirstAllowedPage();
    if ($firstAllowed && $firstAllowed !== 'index.php') {
        header('Location: ' . $firstAllowed);
        exit;
    }
    requirePerm('dash');
}

$pageTitle = 'Dashboard (کاروباری جائزہ)';
require_once __DIR__ . '/includes/header.php';

$db = getDB();
$today = date('Y-m-d');
$monthStart = date('Y-m-01');

// 1. Today's Sales & COGS
$stmt = $db->prepare("
    SELECT 
        COUNT(id) AS total_bills,
        COALESCE(SUM(grand_total), 0) AS today_sales,
        COALESCE(SUM(total_cogs), 0) AS today_cogs,
        COALESCE(SUM(paid_amount), 0) AS today_sales_collected
    FROM `sales` 
    WHERE `sale_date` = ? AND `status` = 'posted'
");
$stmt->execute([$today]);
$todaySalesData = $stmt->fetch();
$todaySales = (float)$todaySalesData['today_sales'];
$todayCogs = (float)$todaySalesData['today_cogs'];
$todayGrossProfit = $todaySales - $todayCogs;

// 2. Today's Expenses
$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM `expenses` WHERE `expense_date` = ?");
$stmt->execute([$today]);
$todayExpenses = (float)$stmt->fetchColumn();

// 3. Customer Cash Collections Today (Standalone RV recovery vouchers from customer ledger)
$stmt = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM `vouchers` 
    WHERE `voucher_type` = 'RV' 
      AND `voucher_date` = ? 
      AND `status` = 'posted'
      AND (`narration` NOT LIKE 'Sale Bill #%' OR `narration` IS NULL)
");
$stmt->execute([$today]);
$todayCustReceipts = (float)$stmt->fetchColumn();
$todayTotalCollections = (float)$todaySalesData['today_sales_collected'] + $todayCustReceipts;

// 4. Month-to-date Sales & Net Profit
$stmt = $db->prepare("
    SELECT 
        COALESCE(SUM(grand_total), 0) AS month_sales,
        COALESCE(SUM(total_cogs), 0) AS month_cogs
    FROM `sales`
    WHERE `sale_date` >= ? AND `sale_date` <= ? AND `status` = 'posted'
");
$stmt->execute([$monthStart, $today]);
$monthData = $stmt->fetch();
$monthSales = (float)$monthData['month_sales'];
$monthGross = $monthSales - (float)$monthData['month_cogs'];

$stmt = $db->prepare("SELECT COALESCE(SUM(amount), 0) FROM `expenses` WHERE `expense_date` >= ? AND `expense_date` <= ?");
$stmt->execute([$monthStart, $today]);
$monthExp = (float)$stmt->fetchColumn();
$monthNetProfit = $monthGross - $monthExp;

// 5. Receivables, Payables, Funds & Valuation
$totalRec = (float)$db->query("SELECT COALESCE(SUM(current_balance), 0) FROM `parties` WHERE `type` = 'customer' AND `current_balance` > 0")->fetchColumn();
$totalPay = (float)$db->query("SELECT COALESCE(SUM(current_balance), 0) FROM `parties` WHERE `type` = 'supplier' AND `current_balance` > 0")->fetchColumn();
$cashFunds = (float)$db->query("SELECT COALESCE(SUM(current_balance), 0) FROM `financial_accounts` WHERE `account_type` = 'Cash' AND `is_active` = 1")->fetchColumn();
$bankFunds = (float)$db->query("SELECT COALESCE(SUM(current_balance), 0) FROM `financial_accounts` WHERE `account_type` = 'Bank' AND `is_active` = 1")->fetchColumn();

$stockRow = $db->query("SELECT COALESCE(SUM(stock_qty * cost_price), 0), COUNT(CASE WHEN stock_qty < alert_qty THEN 1 END) FROM `items` WHERE `is_active` = 1")->fetch(PDO::FETCH_NUM);
$stockVal = (float)($stockRow[0] ?? 0);
$lowStockCount = (int)($stockRow[1] ?? 0);

// Recent Bills
$recentBills = $db->query("
    SELECT s.id, s.invoice_no, s.sale_date, s.sale_time, p.name AS customer_name, s.grand_total, s.paid_amount, s.due_amount, s.payment_status
    FROM sales s
    JOIN parties p ON s.customer_id = p.id
    ORDER BY s.id DESC
    LIMIT 6
")->fetchAll();

// Top Items
$topItems = $db->query("
    SELECT i.name, COALESCE(SUM(si.qty), 0) as qty, COALESCE(SUM(si.line_total), 0) as amount
    FROM sale_items si
    JOIN items i ON si.item_id = i.id
    JOIN sales s ON si.sale_id = s.id AND s.status = 'posted'
    WHERE s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY i.id
    ORDER BY amount DESC
    LIMIT 5
")->fetchAll();

// Top Receivables
$topRec = $db->query("
    SELECT id, name, phone, current_balance 
    FROM parties 
    WHERE type = 'customer' AND current_balance > 0 
    ORDER BY current_balance DESC 
    LIMIT 5
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1" style="color:var(--nv);">Dashboard · کاروباری خلاصہ</h3>
    <small class="text-secondary">Server Database: MySQL 8 · Real-time Accounting &amp; Steel Stock</small>
  </div>
  <div class="d-flex gap-2">
    <a href="pos.php" class="btn btn-zb-gold"><i class="fa-solid fa-cash-register me-1"></i> New Bill (نیا بل بنائیں)</a>
    <a href="purchases.php" class="btn btn-zb-primary"><i class="fa-solid fa-truck-ramp-box me-1"></i> Purchase Entry</a>
  </div>
</div>

<!-- KPI Cards Grid -->
<div class="row g-3 mb-4">
  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: var(--nv);">
      <div class="kpi-title">Today's Sales (آج کی فروخت)</div>
      <div class="kpi-value text-primary"><?= formatMoney($todaySales) ?></div>
      <div class="kpi-sub"><?= (int)$todaySalesData['total_bills'] ?> bills generated today</div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: var(--gn);">
      <div class="kpi-title">Today's Gross Profit (خام منافع)</div>
      <div class="kpi-value text-success"><?= formatMoney($todayGrossProfit) ?></div>
      <div class="kpi-sub">Calculated after real stock COGS</div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: var(--am);">
      <div class="kpi-title">This Month Net Profit (خالص منافع)</div>
      <div class="kpi-value" style="color: #b8900a;"><?= formatMoney($monthNetProfit) ?></div>
      <div class="kpi-sub">Sales: <?= formatMoney($monthSales) ?></div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: var(--rd);">
      <div class="kpi-title">Customer Receivables (کسٹمر ادھار)</div>
      <div class="kpi-value text-danger"><?= formatMoney($totalRec) ?></div>
      <div class="kpi-sub">Payable to Mills: <?= formatMoney($totalPay) ?></div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: #13a8b4;">
      <div class="kpi-title">Today's Cash Collected (وصولی)</div>
      <div class="kpi-value" style="color: #13a8b4;"><?= formatMoney($todayTotalCollections) ?></div>
      <div class="kpi-sub">Counter: <?= formatMoney($todaySalesData['today_sales_collected']) ?> | Recoveries: <?= formatMoney($todayCustReceipts) ?></div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: #7a4fd6;">
      <div class="kpi-title">Total Cash &amp; Bank Funds</div>
      <div class="kpi-value" style="color: #7a4fd6;"><?= formatMoney($cashFunds + $bankFunds) ?></div>
      <div class="kpi-sub">Cash: <?= formatMoney($cashFunds) ?> | Banks: <?= formatMoney($bankFunds) ?></div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: var(--rd);">
      <div class="kpi-title">Today's Operating Expenses</div>
      <div class="kpi-value text-danger"><?= formatMoney($todayExpenses) ?></div>
      <div class="kpi-sub">Rent, Labour, Electricity, etc.</div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="kpi-card" style="--kpi-color: #f08a24;">
      <div class="kpi-title">Inventory Valuation (سٹاک مالیت)</div>
      <div class="kpi-value" style="color: #f08a24;"><?= formatMoney($stockVal) ?></div>
      <div class="kpi-sub <?= $lowStockCount > 0 ? 'text-danger fw-bold' : 'text-success' ?>">
        <?= $lowStockCount ?> low-stock alerts
      </div>
    </div>
  </div>
</div>

<!-- Tables Section -->
<div class="row g-3">
  <!-- Recent Sales Bills -->
  <div class="col-12 col-lg-7">
    <div class="zb-card h-100">
      <div class="zb-card-title">
        <span><i class="fa-solid fa-file-invoice me-1 text-primary"></i> Recent Sales Bills (حالیہ بلز)</span>
        <a href="bills.php" class="btn btn-sm btn-outline-secondary">View All Bills</a>
      </div>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Invoice #</th>
              <th>Customer</th>
              <th>Total</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentBills)): ?>
              <tr><td colspan="5" class="text-center py-4 text-muted">No sales bills created yet.</td></tr>
            <?php else: ?>
              <?php foreach ($recentBills as $b): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($b['invoice_no']) ?></strong><br><small class="text-muted"><?= $b['sale_date'] ?></small></td>
                  <td><strong><?= htmlspecialchars($b['customer_name']) ?></strong></td>
                  <td><strong><?= formatMoney($b['grand_total']) ?></strong></td>
                  <td>
                    <?php if ($b['payment_status'] === 'Paid'): ?>
                      <span class="badge-paid">Paid</span>
                    <?php elseif ($b['payment_status'] === 'Partial'): ?>
                      <span class="badge-partial">Partial</span>
                    <?php else: ?>
                      <span class="badge-unpaid">Unpaid</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="print_bill.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa-solid fa-print me-1"></i> Print</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Top Customer Receivables -->
  <div class="col-12 col-lg-5">
    <div class="zb-card h-100">
      <div class="zb-card-title">
        <span><i class="fa-solid fa-users me-1 text-danger"></i> Customer Receivables (بقایا جات)</span>
        <a href="customers.php" class="btn btn-sm btn-outline-secondary">Customers List</a>
      </div>
      <div class="table-responsive">
        <table class="table align-middle">
          <thead>
            <tr>
              <th>Customer</th>
              <th>Balance Due</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($topRec)): ?>
              <tr><td colspan="3" class="text-center py-4 text-muted">All customer accounts are clear!</td></tr>
            <?php else: ?>
              <?php foreach ($topRec as $r): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars($r['name']) ?></strong><br>
                    <small class="text-muted"><?= htmlspecialchars($r['phone'] ?: 'No Phone') ?></small>
                  </td>
                  <td class="text-danger fw-bold"><?= formatMoney($r['current_balance']) ?></td>
                  <td>
                    <?php if (!empty($r['phone'])): ?>
                      <a href="https://wa.me/<?= formatWaPhone($r['phone']) ?>?text=<?= urlencode("السلام علیکم {$r['name']} صاحب!\nظفر برادرز آئرن مرچنٹس کی طرف آپ کا بقایا بیلنس " . formatMoney($r['current_balance']) . " ہے۔ براہ کرم جلد ادائیگی فرمائیں۔ شکریہ!") ?>" target="_blank" class="btn btn-sm btn-success">
                        <i class="fa-brands fa-whatsapp"></i>
                      </a>
                    <?php endif; ?>
                    <a href="customer_ledger.php?id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Ledger</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
