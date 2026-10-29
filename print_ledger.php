<?php
// print_ledger.php - Dedicated Printable Statement & Khata Report for Customers, Suppliers & Accounts
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();

if ($type === 'customer' && !hasPermission('customers')) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;padding:30px;color:#c00;'><h3>403 · Access Denied (رسائی ممنوع ہے)</h3><p>You do not have permission to view customer khata ledgers.</p></div>");
} elseif ($type === 'supplier' && !hasPermission('suppliers')) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;padding:30px;color:#c00;'><h3>403 · Access Denied (رسائی ممنوع ہے)</h3><p>You do not have permission to view supplier khata ledgers.</p></div>");
} elseif ($type === 'account' && !hasPermission('accounts')) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;padding:30px;color:#c00;'><h3>403 · Access Denied (رسائی ممنوع ہے)</h3><p>You do not have permission to view financial account ledgers.</p></div>");
}

$pdo = getPDO();
$type = trim($_GET['type'] ?? 'customer'); // 'customer', 'supplier', or 'account'
$id   = (int)($_GET['id'] ?? 0);
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));

$company = getCompanySettings();
$compName = $company['company_name'] ?? 'Zafar Brothers Iron Merchants';
$compUrdu = (!empty($company['company_urdu']) && strpos($company['company_urdu'], '?') === false) ? $company['company_urdu'] : 'ظفر برادرز آئرن مرچنٹس';
$compPhone = $company['company_phone'] ?? '0300-9641483 · 0300-7499499';
$compAddress = $company['company_address'] ?? 'Old Chungi, Hafizabad Road, Gujranwala';

$entity = null;
$reportTitle = '';
$reportUrduTitle = '';
$periodOpeningBalance = 0.0;
$ledgerEntries = [];
$totalDebit = 0.0;
$totalCredit = 0.0;
$closingBalance = 0.0;

if ($type === 'customer') {
    requirePerm('customers');
    $st = $pdo->prepare("SELECT * FROM parties WHERE id = ? AND type = 'customer'");
    $st->execute([$id]);
    $entity = $st->fetch();
    if (!$entity) {
        die("<h3>Customer record not found.</h3><p><a href='customers.php'>Back to Customers</a></p>");
    }

    $reportTitle = "Customer Khata Statement";
    $reportUrduTitle = "گاہک کھاتہ اسٹیٹمنٹ";

    // 1. Opening Balance before startDate
    $initOpening = (float)$entity['opening_balance'];
    $stSalesPre = $pdo->prepare("SELECT COALESCE(SUM(grand_total - paid_amount), 0) FROM sales WHERE customer_id = ? AND sale_date < ? AND status = 'posted'");
    $stSalesPre->execute([$id, $startDate]);
    $preSales = (float)$stSalesPre->fetchColumn();

    $stVPre = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM vouchers WHERE party_id = ? AND voucher_date < ? AND voucher_type = 'RV' AND status = 'posted'");
    $stVPre->execute([$id, $startDate]);
    $preVouchers = (float)$stVPre->fetchColumn();

    $periodOpeningBalance = $initOpening + $preSales - $preVouchers;

    // 2. Fetch Period Sales (Debit to customer)
    $stSales = $pdo->prepare("
        SELECT id, invoice_no AS doc_no, sale_date AS trans_date, 'Sales Bill' AS trans_type,
               grand_total AS debit, paid_amount AS immediate_credit, payment_method, notes
        FROM sales
        WHERE customer_id = ? AND sale_date BETWEEN ? AND ? AND status = 'posted'
    ");
    $stSales->execute([$id, $startDate, $endDate]);
    $periodSales = $stSales->fetchAll();

    // 3. Fetch Period Vouchers (RV) (Credit to customer)
    $stV = $pdo->prepare("
        SELECT v.id, v.voucher_no AS doc_no, v.voucher_date AS trans_date, 'Receipt Voucher' AS trans_type,
               0.0 AS debit, v.amount AS credit, v.payment_mode, v.cheque_no, v.narration, a.account_name
        FROM vouchers v
        LEFT JOIN financial_accounts a ON v.account_id = a.id
        WHERE v.party_id = ? AND v.voucher_date BETWEEN ? AND ? AND v.voucher_type = 'RV' AND v.status = 'posted'
    ");
    $stV->execute([$id, $startDate, $endDate]);
    $periodVouchers = $stV->fetchAll();

    foreach ($periodSales as $ps) {
        $ledgerEntries[] = [
            'date'   => $ps['trans_date'],
            'doc_no' => $ps['doc_no'],
            'type'   => 'Sale Bill',
            'desc'   => 'Steel Bill #' . $ps['doc_no'] . (!empty($ps['notes']) ? ' - ' . $ps['notes'] : '') . ((float)$ps['immediate_credit'] > 0 ? ' [Paid at counter: ' . formatMoney($ps['immediate_credit']) . ']' : ''),
            'debit'  => (float)$ps['debit'],
            'credit' => (float)$ps['immediate_credit']
        ];
    }
    foreach ($periodVouchers as $pv) {
        $ledgerEntries[] = [
            'date'   => $pv['trans_date'],
            'doc_no' => $pv['doc_no'],
            'type'   => 'Payment Receipt',
            'desc'   => 'Received via ' . ($pv['account_name'] ?? 'Cash/Bank') . ' (' . $pv['payment_mode'] . ($pv['cheque_no'] ? ' Ref: ' . $pv['cheque_no'] : '') . ') ' . ($pv['narration'] ?? ''),
            'debit'  => 0.0,
            'credit' => (float)$pv['credit']
        ];
    }

    usort($ledgerEntries, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['doc_no'], $b['doc_no']));

    $runningBal = $periodOpeningBalance;
    foreach ($ledgerEntries as &$row) {
        $totalDebit += $row['debit'];
        $totalCredit += $row['credit'];
        $runningBal += ($row['debit'] - $row['credit']);
        $row['balance'] = $runningBal;
    }
    unset($row);
    $closingBalance = $runningBal;

} elseif ($type === 'supplier') {
    requirePerm('suppliers');
    $st = $pdo->prepare("SELECT * FROM parties WHERE id = ? AND type = 'supplier'");
    $st->execute([$id]);
    $entity = $st->fetch();
    if (!$entity) {
        die("<h3>Supplier record not found.</h3><p><a href='suppliers.php'>Back to Suppliers</a></p>");
    }

    $reportTitle = "Supplier Ledger Statement";
    $reportUrduTitle = "سپلائر و مل کھاتہ اسٹیٹمنٹ";

    // 1. Opening balance before startDate
    $initOpening = (float)$entity['opening_balance'];
    $stPurPre = $pdo->prepare("SELECT COALESCE(SUM(grand_total - paid_amount), 0) FROM purchases WHERE supplier_id = ? AND purchase_date < ?");
    $stPurPre->execute([$id, $startDate]);
    $prePurchases = (float)$stPurPre->fetchColumn();

    $stVPre = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM vouchers WHERE party_id = ? AND voucher_date < ? AND voucher_type = 'PV' AND status = 'posted'");
    $stVPre->execute([$id, $startDate]);
    $preVouchers = (float)$stVPre->fetchColumn();

    $periodOpeningBalance = $initOpening + $prePurchases - $preVouchers;

    // 2. Fetch Period Purchases (Credit to supplier)
    $stPur = $pdo->prepare("
        SELECT id, purchase_no AS doc_no, purchase_date AS trans_date, 'Purchase Bill' AS trans_type,
               grand_total AS credit, paid_amount AS immediate_debit, supplier_inv_no
        FROM purchases
        WHERE supplier_id = ? AND purchase_date BETWEEN ? AND ?
    ");
    $stPur->execute([$id, $startDate, $endDate]);
    $periodPurchases = $stPur->fetchAll();

    // 3. Fetch Period Vouchers (PV) (Debit to supplier)
    $stV = $pdo->prepare("
        SELECT v.id, v.voucher_no AS doc_no, v.voucher_date AS trans_date, 'Payment Voucher' AS trans_type,
               v.amount AS debit, 0.0 AS credit, v.payment_mode, v.cheque_no, v.narration, a.account_name
        FROM vouchers v
        LEFT JOIN financial_accounts a ON v.account_id = a.id
        WHERE v.party_id = ? AND v.voucher_date BETWEEN ? AND ? AND v.voucher_type = 'PV' AND v.status = 'posted'
    ");
    $stV->execute([$id, $startDate, $endDate]);
    $periodVouchers = $stV->fetchAll();

    foreach ($periodPurchases as $pp) {
        $ledgerEntries[] = [
            'date'   => $pp['trans_date'],
            'doc_no' => $pp['doc_no'],
            'type'   => 'Purchase Bill',
            'desc'   => 'Steel Purchase Invoice' . (!empty($pp['supplier_inv_no']) ? ' (Mill Inv: ' . $pp['supplier_inv_no'] . ')' : '') . ((float)$pp['immediate_debit'] > 0 ? ' [Paid at loading: ' . formatMoney($pp['immediate_debit']) . ']' : ''),
            'debit'  => (float)$pp['immediate_debit'],
            'credit' => (float)$pp['credit']
        ];
    }
    foreach ($periodVouchers as $pv) {
        $ledgerEntries[] = [
            'date'   => $pv['trans_date'],
            'doc_no' => $pv['doc_no'],
            'type'   => 'Disbursement',
            'desc'   => 'Paid via ' . ($pv['account_name'] ?? 'Bank') . ' (' . $pv['payment_mode'] . ($pv['cheque_no'] ? ' Cheque: ' . $pv['cheque_no'] : '') . ') ' . ($pv['narration'] ?? ''),
            'debit'  => (float)$pv['debit'],
            'credit' => 0.0
        ];
    }

    usort($ledgerEntries, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['doc_no'], $b['doc_no']));

    $runningBal = $periodOpeningBalance;
    foreach ($ledgerEntries as &$row) {
        $totalDebit += $row['debit'];
        $totalCredit += $row['credit'];
        $runningBal += ($row['credit'] - $row['debit']);
        $row['balance'] = $runningBal;
    }
    unset($row);
    $closingBalance = $runningBal;

} elseif ($type === 'account') {
    requirePerm('accounts');
    $st = $pdo->prepare("SELECT fa.*, coa.code AS coa_code, coa.name AS coa_name FROM financial_accounts fa JOIN chart_of_accounts coa ON fa.coa_id = coa.id WHERE fa.id = ?");
    $st->execute([$id]);
    $entity = $st->fetch();
    if (!$entity) {
        die("<h3>Financial account not found.</h3><p><a href='accounts.php'>Back to Accounts</a></p>");
    }

    $reportTitle = "Bank & Cash Account Statement";
    $reportUrduTitle = "کیش و بینک اسٹیٹمنٹ";

    // Opening balance calculation
    $initBal = (float)($entity['opening_balance'] ?? 0);
    $stPreRV = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM vouchers WHERE account_id = ? AND voucher_type = 'RV' AND status = 'posted' AND voucher_date < ?");
    $stPreRV->execute([$id, $startDate]);
    $preRV = (float)$stPreRV->fetchColumn();

    $stPrePV = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM vouchers WHERE account_id = ? AND voucher_type = 'PV' AND status = 'posted' AND voucher_date < ?");
    $stPrePV->execute([$id, $startDate]);
    $prePV = (float)$stPrePV->fetchColumn();

    $stPreEV = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE payment_account_id = ? AND expense_date < ?");
    $stPreEV->execute([$id, $startDate]);
    $preEV = (float)$stPreEV->fetchColumn();

    $stPreTrfIn = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM inter_account_transfers WHERE to_account_id = ? AND status = 'posted' AND transfer_date < ?");
    $stPreTrfIn->execute([$id, $startDate]);
    $preTrfIn = (float)$stPreTrfIn->fetchColumn();

    $stPreTrfOut = $pdo->prepare("SELECT COALESCE(SUM(amount + fee_amount), 0) FROM inter_account_transfers WHERE from_account_id = ? AND status = 'posted' AND transfer_date < ?");
    $stPreTrfOut->execute([$id, $startDate]);
    $preTrfOut = (float)$stPreTrfOut->fetchColumn();

    $periodOpeningBalance = $initBal + $preRV - $prePV - $preEV + $preTrfIn - $preTrfOut;

    // Fetch Receipts
    $stRV = $pdo->prepare("
        SELECT v.voucher_date AS trans_date, v.voucher_no AS doc_no, 'Receipt' AS trans_type,
               CONCAT('Received from ', COALESCE(p.name, 'Customer'), ' (', v.payment_mode, IF(v.cheque_no != '', CONCAT(' Ref: ', v.cheque_no), ''), ') ', COALESCE(v.narration, '')) AS description,
               v.amount AS inflow, 0.0 AS outflow
        FROM vouchers v
        LEFT JOIN parties p ON v.party_id = p.id
        WHERE v.account_id = ? AND v.voucher_type = 'RV' AND v.status = 'posted' AND v.voucher_date BETWEEN ? AND ?
    ");
    $stRV->execute([$id, $startDate, $endDate]);
    $rEntries = $stRV->fetchAll();

    // Fetch Supplier Payments
    $stPV = $pdo->prepare("
        SELECT v.voucher_date AS trans_date, v.voucher_no AS doc_no, 'Payment' AS trans_type,
               CONCAT('Paid to ', COALESCE(p.name, 'Supplier'), ' (', v.payment_mode, IF(v.cheque_no != '', CONCAT(' Cheque: ', v.cheque_no), ''), ') ', COALESCE(v.narration, '')) AS description,
               0.0 AS inflow, v.amount AS outflow
        FROM vouchers v
        LEFT JOIN parties p ON v.party_id = p.id
        WHERE v.account_id = ? AND v.voucher_type = 'PV' AND v.status = 'posted' AND v.voucher_date BETWEEN ? AND ?
    ");
    $stPV->execute([$id, $startDate, $endDate]);
    $pEntries = $stPV->fetchAll();

    // Fetch Expenses
    $stEV = $pdo->prepare("
        SELECT e.expense_date AS trans_date, e.expense_no AS doc_no, 'Expense' AS trans_type,
               CONCAT('Expense [', c.name, '] to ', e.payee, ' - ', e.notes) AS description,
               0.0 AS inflow, e.amount AS outflow
        FROM expenses e
        JOIN expense_categories c ON e.category_id = c.id
        WHERE e.payment_account_id = ? AND e.expense_date BETWEEN ? AND ?
    ");
    $stEV->execute([$id, $startDate, $endDate]);
    $eEntries = $stEV->fetchAll();

    // Fetch Transfers In & Out
    $stTrfIn = $pdo->prepare("
        SELECT t.transfer_date AS trans_date, t.transfer_no AS doc_no, 'Transfer IN' AS trans_type,
               CONCAT('Transferred from ', fa.account_name, ' (Ref: ', t.reference_no, ') ', t.narration) AS description,
               t.amount AS inflow, 0.0 AS outflow
        FROM inter_account_transfers t
        JOIN financial_accounts fa ON t.from_account_id = fa.id
        WHERE t.to_account_id = ? AND t.status = 'posted' AND t.transfer_date BETWEEN ? AND ?
    ");
    $stTrfIn->execute([$id, $startDate, $endDate]);
    $trfInEntries = $stTrfIn->fetchAll();

    $stTrfOut = $pdo->prepare("
        SELECT t.transfer_date AS trans_date, t.transfer_no AS doc_no, 'Transfer OUT' AS trans_type,
               CONCAT('Transferred to ', fa.account_name, ' (Ref: ', t.reference_no, IF(t.fee_amount > 0, CONCAT(' Fee: Rs ', t.fee_amount), ''), ') ', t.narration) AS description,
               0.0 AS inflow, (t.amount + t.fee_amount) AS outflow
        FROM inter_account_transfers t
        JOIN financial_accounts fa ON t.to_account_id = fa.id
        WHERE t.from_account_id = ? AND t.status = 'posted' AND t.transfer_date BETWEEN ? AND ?
    ");
    $stTrfOut->execute([$id, $startDate, $endDate]);
    $trfOutEntries = $stTrfOut->fetchAll();

    $allRaw = array_merge($rEntries, $pEntries, $eEntries, $trfInEntries, $trfOutEntries);
    foreach ($allRaw as $item) {
        $ledgerEntries[] = [
            'date'   => $item['trans_date'],
            'doc_no' => $item['doc_no'],
            'type'   => $item['trans_type'],
            'desc'   => $item['description'],
            'debit'  => (float)$item['inflow'],
            'credit' => (float)$item['outflow']
        ];
    }

    usort($ledgerEntries, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['doc_no'], $b['doc_no']));

    $runningBal = $periodOpeningBalance;
    foreach ($ledgerEntries as &$row) {
        $totalDebit += $row['debit'];
        $totalCredit += $row['credit'];
        $runningBal += ($row['debit'] - $row['credit']);
        $row['balance'] = $runningBal;
    }
    unset($row);
    $closingBalance = $runningBal;
}
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($reportTitle) ?> · <?= htmlspecialchars($entity['name'] ?? $entity['account_name'] ?? '') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;600;700&family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <style>
    @font-face {
      font-family: 'Alvi Nastaleeq';
      src: local('Alvi Nastaleeq'),
           local('Alvi-Nastaleeq'),
           url('assets/fonts/Alvi-Nastaleeq.ttf') format('truetype');
      font-weight: normal;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Agency FB';
      src: url('assets/fonts/Agency-FB-Regular.ttf') format('truetype'),
           url('assets/fonts/Agency%20FB%20Regular.ttf') format('truetype');
      font-weight: normal;
      font-style: normal;
      font-display: swap;
    }
    body {
      background: #718096;
      color: #0f172a;
      font-family: 'Sora', 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', system-ui, -apple-system, sans-serif;
      margin: 0;
      padding: 16px 8px;
    }
    .ur {
      font-family: 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif;
      line-height: 2.0;
      direction: rtl;
    }
    .font-num {
      font-family: 'Consolas', 'JetBrains Mono', 'Courier New', monospace;
      white-space: nowrap;
    }
    .action-bar {
      max-width: 960px;
      width: 100%;
      margin: 0 auto 16px;
      background: #fff;
      padding: 10px 18px;
      border-radius: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 14px rgba(0,0,0,0.18);
      flex-wrap: wrap;
      gap: 12px;
    }
    .ledger-sheet {
      background: #fff;
      max-width: 960px;
      margin: 0 auto;
      box-shadow: 0 10px 30px rgba(0,0,0,0.25);
      border-radius: 6px;
      padding: 26px 30px;
      min-height: 1000px;
    }

    /* Stacked Two-Tier Table Headers */
    .table-ledger {
      width: 100%;
      border-collapse: collapse;
      margin-top: 14px;
      font-size: 12px;
    }
    .table-ledger th {
      background: #14307a;
      color: #fff;
      padding: 6px 8px;
      border: 1px solid #0f245c;
      font-weight: 700;
      vertical-align: middle;
      font-size: 11.5px;
    }
    .table-ledger th .th-title {
      font-size: 11.5px;
      letter-spacing: 0.3px;
    }
    .table-ledger th .th-sub {
      font-family: 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif;
      line-height: 1.8;
      font-size: 11px;
      opacity: 0.9;
      font-weight: normal;
    }
    .table-ledger td {
      padding: 6px 8px;
      border: 1px solid #cbd5e1;
      vertical-align: middle;
      font-size: 11.5px;
    }
    .table-ledger tr:nth-child(even) td {
      background: #f8fafc;
    }
    .dr-amount { color: #0369a1; font-weight: 600; }
    .cr-amount { color: #15803d; font-weight: 600; }
    .bal-amount { color: #0f172a; font-weight: 700; }

    @media print {
      body {
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #000 !important;
      }
      .no-print {
        display: none !important;
      }
      .ledger-sheet {
        box-shadow: none !important;
        padding: 0 !important;
        margin: 0 auto !important;
        width: 100% !important;
        max-width: 100% !important;
      }
      .table-ledger th {
        background: #e2e8f0 !important;
        color: #000 !important;
        border: 1px solid #000 !important;
      }
      .table-ledger td {
        border: 1px solid #333 !important;
      }
      @page {
        size: A4 portrait;
        margin: 10mm 8mm 12mm 8mm;
      }
    }
  </style>
</head>
<body>

  <!-- Top Action Toolbar (Hidden during Print) -->
  <div class="action-bar no-print">
    <div class="d-flex align-items-center gap-2">
      <button onclick="window.close()" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-xmark me-1"></i> Close
      </button>
      <span class="fw-bold ms-1 text-primary">
        <?= htmlspecialchars($entity['name'] ?? $entity['account_name'] ?? '') ?>
      </span>
      <span class="badge bg-secondary"><?= ucfirst($type) ?> Statement</span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <form method="GET" class="d-flex align-items-center gap-2">
        <input type="hidden" name="type" value="<?= htmlspecialchars($type) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <label class="small text-muted mb-0">From:</label>
        <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control form-control-sm" style="width: auto;">
        <label class="small text-muted mb-0">To:</label>
        <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control form-control-sm" style="width: auto;">
        <button type="submit" class="btn btn-sm btn-outline-primary">Filter</button>
      </form>
      <button onclick="window.print()" class="btn btn-sm btn-primary px-3">
        <i class="fa-solid fa-print me-1"></i> Print (پرنٹ کریں)
      </button>
    </div>
  </div>

  <!-- Printable Ledger Sheet -->
  <div class="ledger-sheet">

    <!-- Company Header Banner with safe vertical spacing -->
    <div style="border-bottom: 3px solid #14307a; padding-bottom: 12px; margin-bottom: 14px;">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
          <img src="assets/images/logo.png" alt="Logo" style="width: 65px; height: 65px; object-fit: contain;">
          <div>
            <!-- Dedicated Urdu Title with 2.1 line-height -->
            <div class="ur" dir="rtl" style="color: #14307a; font-size: 24px; font-weight: 700; line-height: 1.9; margin-bottom: 2px;">
              <?= htmlspecialchars($compUrdu) ?>
            </div>
            <!-- English Brand Name below Urdu with clearance -->
            <div style="font-weight: 800; font-size: 18px; color: #14307a; letter-spacing: 0.5px;">
              <?= htmlspecialchars($compName) ?>
            </div>
            <div style="font-size: 11px; color: #64748b; font-weight: 600; margin-top: 2px;">
              Gujranwala Steel Group of Industry · Prime Re-Rolled Steel Bars &amp; Structural Materials
            </div>
          </div>
        </div>
        <div class="text-end" style="font-size: 11.5px; line-height: 1.6;">
          <div><i class="fa-solid fa-phone me-1 text-primary"></i> <strong><?= htmlspecialchars($compPhone) ?></strong></div>
          <div><i class="fa-solid fa-location-dot me-1 text-primary"></i> <?= htmlspecialchars($compAddress) ?></div>
          <div class="text-muted" style="font-size: 10.5px;">Generated: <?= date('d-M-Y h:i A') ?></div>
        </div>
      </div>
    </div>

    <!-- Title Bar with clean breathing room -->
    <div style="background: #f1f5f9; border-radius: 6px; padding: 10px 16px; margin-bottom: 14px; border-left: 5px solid #14307a;">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <span style="font-size: 16px; font-weight: 800; color: #14307a;">
            <?= htmlspecialchars($reportTitle) ?>
          </span>
          <span class="badge bg-primary" style="font-size: 11px;"><?= date('d-M-Y', strtotime($startDate)) ?> to <?= date('d-M-Y', strtotime($endDate)) ?></span>
        </div>
        <div class="ur" dir="rtl" style="color: #0f172a; font-weight: 700; font-size: 16px; line-height: 1.7;">
          <?= htmlspecialchars($reportUrduTitle) ?>
        </div>
      </div>
    </div>

    <!-- Party / Account Details Box (Isolated LTR/RTL Flex Spans) -->
    <div style="background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px;">
      <div class="row g-2 align-items-center">
        <div class="col-8">
          <div class="d-flex justify-content-between align-items-center" style="font-size: 10.5px; color: #64748b; font-weight: 700;">
            <span>ACCOUNT HOLDER / PARTY DETAILS</span>
            <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.3;">نام کھاتہ دار و تفصیل</span>
          </div>
          <div style="font-size: 17px; font-weight: 800; color: #0f172a; margin-top: 1px;">
            <?= htmlspecialchars($entity['name'] ?? $entity['account_name'] ?? '') ?>
            <?php if (!empty($entity['company_name'])): ?>
              <span class="text-muted" style="font-size: 13.5px; font-weight: normal;">(<?= htmlspecialchars($entity['company_name']) ?>)</span>
            <?php endif; ?>
          </div>
          <div style="font-size: 11.5px; color: #475569; margin-top: 2px;">
            <?php if (!empty($entity['phone'])): ?>
              <span><i class="fa-solid fa-phone me-1"></i> Phone: <strong><?= htmlspecialchars($entity['phone']) ?></strong></span>
            <?php endif; ?>
            <?php if (!empty($entity['address'])): ?>
              <span class="ms-3"><i class="fa-solid fa-map-marker-alt me-1"></i> <?= htmlspecialchars($entity['address']) ?></span>
            <?php endif; ?>
            <?php if (!empty($entity['account_number'])): ?>
              <span><i class="fa-solid fa-credit-card me-1"></i> A/C: <strong><?= htmlspecialchars($entity['account_number']) ?></strong> (<?= htmlspecialchars($entity['bank_name'] ?? '') ?>)</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="col-4 text-end">
          <div class="d-flex justify-content-between align-items-center" style="font-size: 10.5px; color: #64748b; font-weight: 700;">
            <span>NET CURRENT BALANCE</span>
            <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.3;">موجودہ بقایا کھاتہ</span>
          </div>
          <div style="font-size: 19px; font-weight: 800; color: #b91c1c; margin-top: 1px;" class="font-num">
            <?= formatCurrency((float)$entity['current_balance']) ?>
          </div>
          <div style="font-size: 10.5px; color: #64748b;">
            <?php if ($type === 'customer'): ?>
              <?= (float)$entity['current_balance'] >= 0 ? 'Receivable (واجب الوصول)' : 'Advance (پیشگی رقم)' ?>
            <?php elseif ($type === 'supplier'): ?>
              <?= (float)$entity['current_balance'] >= 0 ? 'Payable (واجب الادا)' : 'Advance (پیشگی دی گئی)' ?>
            <?php else: ?>
              Live Available Balance
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Ledger Table with Stacked Two-Tier Headers -->
    <table class="table-ledger">
      <thead>
        <tr>
          <th style="width: 10%;">
            <div class="th-title">Date</div>
            <div class="th-sub" dir="rtl">تاریخ</div>
          </th>
          <th style="width: 14%;">
            <div class="th-title">Doc #</div>
            <div class="th-sub" dir="rtl">دستاویز نمبر</div>
          </th>
          <th style="width: 12%;">
            <div class="th-title">Type</div>
            <div class="th-sub" dir="rtl">قسم</div>
          </th>
          <th style="width: 30%;">
            <div class="th-title">Particulars</div>
            <div class="th-sub" dir="rtl">تفصیلات و تفصیل مال</div>
          </th>
          <th style="width: 11%; text-align: right;">
            <div class="th-title"><?= $type === 'supplier' ? 'Debit' : ($type === 'account' ? 'Inflow' : 'Debit') ?></div>
            <div class="th-sub" dir="rtl"><?= $type === 'supplier' ? 'ادائیگی (بنام)' : ($type === 'account' ? 'آمد' : 'بل رقم (بنام)') ?></div>
          </th>
          <th style="width: 11%; text-align: right;">
            <div class="th-title"><?= $type === 'supplier' ? 'Credit' : ($type === 'account' ? 'Outflow' : 'Credit') ?></div>
            <div class="th-sub" dir="rtl"><?= $type === 'supplier' ? 'خریداری (جمع)' : ($type === 'account' ? 'اخراجات' : 'وصولی (جمع)') ?></div>
          </th>
          <th style="width: 12%; text-align: right;">
            <div class="th-title">Balance</div>
            <div class="th-sub" dir="rtl">بقایا بیلنس</div>
          </th>
        </tr>
      </thead>
      <tbody>
        <!-- Opening Balance Row -->
        <tr style="background: #f8fafc; font-weight: 600;">
          <td class="font-num"><?= date('d-M-Y', strtotime($startDate)) ?></td>
          <td><span class="badge bg-secondary" style="font-size: 10px;">OPENING</span></td>
          <td>ابتدائی بقایا</td>
          <td>
            <strong>Opening Balance B/F</strong>
            <span class="ur text-muted ms-2" dir="rtl" style="font-size: 11px;">(سابقہ بقایا رقم)</span>
          </td>
          <td class="text-end">—</td>
          <td class="text-end">—</td>
          <td class="text-end bal-amount font-num"><?= formatCurrency($periodOpeningBalance, false) ?></td>
        </tr>

        <!-- Transaction Rows -->
        <?php if (empty($ledgerEntries)): ?>
          <tr>
            <td colspan="7" class="text-center py-4 text-muted">
              <em>No transactions recorded in this period (اس تاریخ میں کوئی اندراج موجود نہیں ہے).</em>
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($ledgerEntries as $entry): ?>
            <tr>
              <td class="font-num"><?= date('d-M-Y', strtotime($entry['date'])) ?></td>
              <td><strong><?= htmlspecialchars($entry['doc_no']) ?></strong></td>
              <td><small class="text-muted"><?= htmlspecialchars($entry['type']) ?></small></td>
              <td><?= htmlspecialchars($entry['desc']) ?></td>
              <td class="text-end dr-amount font-num">
                <?= $entry['debit'] > 0 ? number_format($entry['debit'], 2) : '—' ?>
              </td>
              <td class="text-end cr-amount font-num">
                <?= $entry['credit'] > 0 ? number_format($entry['credit'], 2) : '—' ?>
              </td>
              <td class="text-end bal-amount font-num">
                <?= number_format($entry['balance'], 2) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <!-- Totals Row -->
        <tr style="background: #e2e8f0; font-weight: 800; border-top: 2px solid #0f172a;">
          <td colspan="4" class="text-end">
            <span>Period Totals</span>
            <span class="ur text-muted ms-1" dir="rtl" style="font-size: 11px;">(کل میزان برائے مدت)</span>:
          </td>
          <td class="text-end text-primary font-num" style="font-size: 13px;">
            <?= number_format($totalDebit, 2) ?>
          </td>
          <td class="text-end text-success font-num" style="font-size: 13px;">
            <?= number_format($totalCredit, 2) ?>
          </td>
          <td class="text-end bal-amount font-num" style="font-size: 13.5px; color: #b91c1c;">
            <?= formatCurrency($closingBalance, false) ?>
          </td>
        </tr>
      </tfoot>
    </table>

    <!-- Statement Summary Cards (Clean Isolated Blocks) -->
    <div style="margin-top: 18px; display: flex; justify-content: space-between; gap: 14px; flex-wrap: wrap;">
      <div style="flex: 1; min-width: 280px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; background: #fafafa;">
        <div class="d-flex justify-content-between align-items-center" style="font-size: 10.5px; color: #64748b; font-weight: 700;">
          <span>STATEMENT SUMMARY</span>
          <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.3;">خلاصہ کھاتہ</span>
        </div>
        <div style="font-size: 12px; line-height: 1.8; margin-top: 6px;">
          <div class="d-flex justify-content-between align-items-center py-1">
            <span>Opening Balance:</span>
            <strong class="font-num"><?= formatCurrency($periodOpeningBalance) ?></strong>
          </div>
          <div class="d-flex justify-content-between align-items-center py-1">
            <div class="d-flex align-items-center gap-1">
              <span>Total Debits</span>
              <span class="ur text-muted" dir="rtl" style="font-size: 10.5px; line-height: 1.3;">بنام</span>
            </div>
            <span class="text-primary font-num">+ <?= formatCurrency($totalDebit) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center py-1">
            <div class="d-flex align-items-center gap-1">
              <span>Total Credits</span>
              <span class="ur text-muted" dir="rtl" style="font-size: 10.5px; line-height: 1.3;">جمع</span>
            </div>
            <span class="text-success font-num">− <?= formatCurrency($totalCredit) ?></span>
          </div>
          <div class="d-flex justify-content-between align-items-center pt-1 border-top border-2 border-dark fw-bold" style="color: #14307a; font-size: 13.5px;">
            <div class="d-flex align-items-center gap-1">
              <span>Closing Balance</span>
              <span class="ur" dir="rtl" style="font-size: 11.5px; line-height: 1.3;">حتمی بقایا</span>
            </div>
            <span class="font-num" style="color: #b91c1c;"><?= formatCurrency($closingBalance) ?></span>
          </div>
        </div>
      </div>

      <div style="flex: 1.2; min-width: 300px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px; background: #f8fafc;">
        <div class="ur" dir="rtl" style="color: #14307a; font-size: 13px; font-weight: 700; line-height: 1.5; margin-bottom: 2px;">
          نوٹ برائے کھاتہ دار / پارٹی:
        </div>
        <div class="ur" dir="rtl" style="font-size: 11.5px; color: #334155; line-height: 1.8;">
          کسی بھی قسم کے فرق یا تضاد کی صورت میں 7 یوم کے اندر اندر دفتر سے رابطہ فرما کر تصدیق کر لیں۔ بصورت دیگر یہ گوشوارہ حساب قطعی اور درست تسلیم کیا جائے گا۔
        </div>
        <div style="font-size: 10.5px; color: #64748b; margin-top: 6px;">
          For invoice verification, please contact accounts department at <strong><?= htmlspecialchars($compPhone) ?></strong>.
        </div>
      </div>
    </div>

    <!-- Signatures Block -->
    <div style="margin-top: 45px; display: flex; justify-content: space-between; text-align: center; font-size: 11.5px; color: #475569;">
      <div style="width: 28%; border-top: 1px solid #94a3b8; padding-top: 6px;">
        <strong>Prepared By (تیار کنندہ)</strong><br>
        <small class="text-muted">Accountant / Cashier</small>
      </div>
      <div style="width: 28%; border-top: 1px solid #94a3b8; padding-top: 6px;">
        <strong>Checked &amp; Approved (تصدیق)</strong><br>
        <small class="text-muted">Manager / Partner</small>
      </div>
      <div style="width: 28%; border-top: 1px solid #94a3b8; padding-top: 6px;">
        <strong>Party Signature (دستخط کھاتہ دار)</strong><br>
        <small class="text-muted"><?= htmlspecialchars($entity['name'] ?? $entity['account_name'] ?? '') ?></small>
      </div>
    </div>

  </div>

</body>
</html>
