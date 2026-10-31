<?php
// print_closing.php - Dedicated A4 Printable Daily Cash Closing & Shift Audit Report
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
requirePerm('closing');

$pdo = getPDO();
$closingId = (int)($_GET['id'] ?? 0);

if ($closingId > 0) {
    $st = $pdo->prepare("
        SELECT dc.*, u.name AS closed_by_name, op.name AS opened_by_name
        FROM daily_closings dc
        LEFT JOIN users u ON dc.user_id = u.id
        LEFT JOIN users op ON dc.opened_by = op.id
        WHERE dc.id = ?
    ");
    $st->execute([$closingId]);
    $closing = $st->fetch();
} else {
    $closing = $pdo->query("
        SELECT dc.*, u.name AS closed_by_name, op.name AS opened_by_name
        FROM daily_closings dc
        LEFT JOIN users u ON dc.user_id = u.id
        LEFT JOIN users op ON dc.opened_by = op.id
        ORDER BY dc.id DESC
        LIMIT 1
    ")->fetch();
}

if (!$closing) {
    die("<div style='font-family:sans-serif;padding:30px;color:#c00;'><h3>Shift closing record not found.</h3><p><a href='closing.php'>Back to Daily Closing</a></p></div>");
}

$company = getCompanySettings();
$compName    = $company['company_name'] ?? 'Zafar Brothers Iron Merchants';
$compUrdu    = (!empty($company['company_urdu']) && strpos($company['company_urdu'], '?') === false) ? $company['company_urdu'] : 'ظفر برادرز آئرن مرچنٹس';
$compPhone   = $company['company_phone'] ?? '0300-9641483 · 0300-7499499';
$compAddress = $company['company_address'] ?? 'Old Chungi, Hafizabad Road, Gujranwala';

$closingDate = $closing['closing_date'];
$openTime    = $closing['opening_time'] ?? '';
$closeTime   = $closing['closing_time'] ?? '';

// -----------------------------------------------------------------------------
// 1. GATHER SHIFT TRANSACTION DETAIL ENTRIES (Transfers, Expense, Income)
// -----------------------------------------------------------------------------
// Determine exact shift time boundary to isolate this shift's transactions
$stPriorShift = $pdo->prepare("
    SELECT closing_date, closing_time, created_at 
    FROM daily_closings 
    WHERE id < ? AND status = 'finalized' 
    ORDER BY id DESC 
    LIMIT 1
");
$stPriorShift->execute([$closing['id']]);
$priorShift = $stPriorShift->fetch();

$shiftStart = $closingDate . ' 00:00:00';
if ($priorShift && !empty($priorShift['closing_time'])) {
    $priorEndTs = $priorShift['closing_date'] . ' ' . $priorShift['closing_time'];
    if ($priorEndTs > $shiftStart) {
        $shiftStart = $priorEndTs;
    }
}
if (!empty($openTime)) {
    $openTs = $closingDate . ' ' . $openTime;
    if ($openTs > $shiftStart) {
        $shiftStart = $openTs;
    }
} elseif (!empty($closing['created_at'])) {
    if ($closing['created_at'] > $shiftStart) {
        $shiftStart = $closing['created_at'];
    }
}

if (!empty($closeTime)) {
    $shiftEnd = $closingDate . ' ' . $closeTime;
} else {
    $shiftEnd = date('Y-m-d 23:59:59');
}

// A. INCOME ENTRIES (Cash received from Sales & Customer collections)
$incomeEntries = [];

// Cash Sales in this shift
$stSales = $pdo->prepare("
    SELECT id, invoice_no, paid_amount, payment_method, sale_date, sale_time, customer_name_custom
    FROM sales
    WHERE created_at >= ? AND created_at <= ? AND status = 'posted' AND payment_method = 'Cash' AND paid_amount > 0
    ORDER BY id ASC
");
$stSales->execute([$shiftStart, $shiftEnd]);
while ($s = $stSales->fetch()) {
    $incomeEntries[] = [
        'ref'     => $s['invoice_no'],
        'amount'  => (float)$s['paid_amount'],
        'url'     => 'print_bill.php?id=' . $s['id'],
        'label'   => 'Cash Sale',
        'sub'     => $s['customer_name_custom'] ?? ''
    ];
}

// Standalone Customer Cash Recovery Vouchers (RV) in this shift
$stRV = $pdo->prepare("
    SELECT v.id, v.voucher_no, v.amount, p.name AS party_name, v.party_id
    FROM vouchers v
    JOIN financial_accounts fa ON v.account_id = fa.id
    LEFT JOIN parties p ON v.party_id = p.id
    WHERE v.created_at >= ? AND v.created_at <= ?
      AND v.voucher_type = 'RV' 
      AND fa.account_type = 'Cash' 
      AND v.status = 'posted'
      AND (v.narration NOT LIKE 'Sale Bill #%' OR v.narration IS NULL)
    ORDER BY v.id ASC
");
$stRV->execute([$shiftStart, $shiftEnd]);
while ($rv = $stRV->fetch()) {
    $incomeEntries[] = [
        'ref'     => $rv['voucher_no'],
        'amount'  => (float)$rv['amount'],
        'url'     => 'customer_ledger.php?id=' . (int)$rv['party_id'],
        'label'   => 'Customer Collection: ' . ($rv['party_name'] ?? 'Walk-in'),
        'sub'     => $rv['party_name'] ?? ''
    ];
}

// B. EXPENSE ENTRIES (Daily Expenses & Supplier Cash Payments)
$expenseEntries = [];

// Daily Cash Expenses in this shift
$stExp = $pdo->prepare("
    SELECT e.id, e.expense_no, e.amount, c.name AS category_name, e.payee
    FROM expenses e
    JOIN expense_categories c ON e.category_id = c.id
    JOIN financial_accounts fa ON e.payment_account_id = fa.id
    WHERE e.created_at >= ? AND e.created_at <= ? AND fa.account_type = 'Cash'
    ORDER BY e.id ASC
");
$stExp->execute([$shiftStart, $shiftEnd]);
while ($exp = $stExp->fetch()) {
    $expenseEntries[] = [
        'ref'     => $exp['expense_no'],
        'amount'  => (float)$exp['amount'],
        'url'     => 'expenses.php?search=' . urlencode($exp['expense_no']),
        'label'   => 'Expense: ' . ($exp['category_name'] ?? 'Expense'),
        'sub'     => $exp['payee'] ?? ''
    ];
}

// Supplier Cash Payments (PV) in this shift
$stPV = $pdo->prepare("
    SELECT v.id, v.voucher_no, v.amount, p.name AS party_name, v.party_id
    FROM vouchers v
    JOIN financial_accounts fa ON v.account_id = fa.id
    LEFT JOIN parties p ON v.party_id = p.id
    WHERE v.created_at >= ? AND v.created_at <= ? AND v.voucher_type = 'PV' AND fa.account_type = 'Cash' AND v.status = 'posted'
    ORDER BY v.id ASC
");
$stPV->execute([$shiftStart, $shiftEnd]);
while ($pv = $stPV->fetch()) {
    $expenseEntries[] = [
        'ref'     => $pv['voucher_no'],
        'amount'  => (float)$pv['amount'],
        'url'     => 'supplier_ledger.php?id=' . (int)$pv['party_id'],
        'label'   => 'Paid Supplier: ' . ($pv['party_name'] ?? 'Supplier'),
        'sub'     => $pv['party_name'] ?? ''
    ];
}

// C. TRANSFERS ENTRIES (Cash-to-Bank, Bank-to-Cash, Drawer Transfers)
$transferEntries = [];

$stTrf = $pdo->prepare("
    SELECT t.id, t.transfer_no, t.amount, t.fee_amount, faFrom.account_name AS from_acc, faTo.account_name AS to_acc
    FROM inter_account_transfers t
    JOIN financial_accounts faFrom ON t.from_account_id = faFrom.id
    JOIN financial_accounts faTo ON t.to_account_id = faTo.id
    WHERE t.created_at >= ? AND t.created_at <= ? AND (faFrom.account_type = 'Cash' OR faTo.account_type = 'Cash') AND t.status = 'posted'
    ORDER BY t.id ASC
");
$stTrf->execute([$shiftStart, $shiftEnd]);
while ($trf = $stTrf->fetch()) {
    $transferEntries[] = [
        'ref'     => $trf['transfer_no'],
        'amount'  => (float)$trf['amount'],
        'url'     => 'transfers.php?search=' . urlencode($trf['transfer_no']),
        'label'   => $trf['from_acc'] . ' -> ' . $trf['to_acc'],
        'sub'     => $trf['to_acc'] ?? ''
    ];
}

// Totals
$totIncome   = array_sum(array_column($incomeEntries, 'amount'));
$totExpense  = array_sum(array_column($expenseEntries, 'amount'));
$totTransfer = array_sum(array_column($transferEntries, 'amount'));

// Pad rows for formal grid appearance (minimum 10 rows matching Screenshot 2)
$maxRows = max(count($incomeEntries), count($expenseEntries), count($transferEntries), 10);
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
  <meta charset="UTF-8">
  <title>Shift Closing Report · <?= htmlspecialchars($closing['closing_no']) ?></title>
  <link rel="stylesheet" href="assets/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;600;700&family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
  <style>
    *, *::before, *::after {
      box-sizing: border-box;
    }
    body {
      background: #64748b;
      margin: 0;
      padding: 16px 8px;
      font-family: 'Sora', system-ui, -apple-system, sans-serif;
      color: #0f172a;
      -webkit-font-smoothing: antialiased;
    }
    .ur {
      font-family: 'Noto Nastaliq Urdu', 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', serif;
      direction: rtl;
    }
    .font-num {
      font-family: 'JetBrains Mono', 'Consolas', monospace;
    }

    /* Fixed Printable Sheet Container (A4 Size) */
    .a4-sheet {
      background: #fff;
      max-width: 860px;
      margin: 0 auto;
      box-shadow: 0 10px 30px rgba(0,0,0,0.25);
      border-radius: 4px;
      padding: 24px 28px;
      min-height: 1040px;
    }

    /* Action Toolbar (Screen Only) */
    .action-bar {
      max-width: 860px;
      margin: 0 auto 14px;
      background: #fff;
      padding: 10px 16px;
      border-radius: 6px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 14px rgba(0,0,0,0.15);
      font-size: 13px;
    }
    .btn {
      display: inline-flex;
      align-items: center;
      padding: 6px 14px;
      font-size: 12.5px;
      font-weight: 600;
      border-radius: 4px;
      text-decoration: none;
      border: 1px solid transparent;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .btn-close-win {
      background: #f1f5f9;
      color: #334155;
      border-color: #cbd5e1;
    }
    .btn-close-win:hover {
      background: #e2e8f0;
    }
    .btn-print {
      background: #14307a;
      color: #fff;
    }
    .btn-print:hover {
      background: #0f245c;
    }

    /* 3-Column Reconciliation Table (Matching Screenshot 2) */
    .reconcile-grid-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
      font-size: 11px;
      margin-top: 12px;
      margin-bottom: 16px;
      border: 1.5px solid #000;
    }
    .reconcile-grid-table th,
    .reconcile-grid-table td {
      border: 1px solid #000;
      padding: 4px 6px;
      height: 24px;
      vertical-align: middle;
    }
    .reconcile-grid-table thead th {
      background: #f8fafc;
      font-weight: 700;
      text-align: center;
      color: #000;
    }
    .reconcile-grid-table .cat-head {
      font-size: 12.5px;
      letter-spacing: 0.5px;
      font-weight: 800;
      text-transform: uppercase;
      background: #e2e8f0;
      border-bottom: 1.5px solid #000;
      padding: 6px 4px;
    }
    .reconcile-grid-table a.ref-link {
      color: #1d4ed8;
      text-decoration: none;
      font-weight: 700;
      font-family: 'JetBrains Mono', monospace;
      display: inline-block;
    }
    .reconcile-grid-table a.ref-link:hover {
      text-decoration: underline;
    }
    .reconcile-grid-table .amt-cell {
      text-align: right;
      font-family: 'JetBrains Mono', monospace;
      font-weight: 600;
    }
    .reconcile-grid-table tfoot th,
    .reconcile-grid-table tfoot td {
      background: #f1f5f9;
      font-weight: 800;
      border-top: 2px solid #000;
      font-size: 11.5px;
    }

    /* Financial Summary Detail Box */
    .summary-box {
      border: 1.5px solid #0f172a;
      border-radius: 4px;
      background: #fff;
      overflow: hidden;
      margin-top: 10px;
    }
    .summary-header {
      background: #14307a;
      color: #fff;
      padding: 6px 12px;
      font-weight: 700;
      font-size: 12px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .summary-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 11.5px;
    }
    .summary-table td, .summary-table th {
      padding: 5px 10px;
      border: 1px solid #cbd5e1;
    }
    .summary-table th {
      background: #f8fafc;
      font-weight: 600;
      text-align: left;
    }

    /* Print Setup for Clean A4 Output */
    @media print {
      body {
        background: #fff !important;
        padding: 0 !important;
      }
      .action-bar {
        display: none !important;
      }
      .a4-sheet {
        box-shadow: none !important;
        border-radius: 0 !important;
        padding: 0 !important;
        max-width: 100% !important;
        min-height: auto !important;
      }
      @page {
        size: A4 portrait;
        margin: 10mm 12mm;
      }
      .reconcile-grid-table a.ref-link {
        color: #000 !important;
        text-decoration: underline !important;
      }
      .reconcile-grid-table,
      .summary-box {
        page-break-inside: avoid;
      }
    }
  </style>
</head>
<body>

  <!-- Screen Toolbar -->
  <div class="action-bar">
    <div style="display: flex; align-items: center; gap: 8px;">
      <span style="font-weight: 700; color: #14307a;">
        <i class="fa-solid fa-file-invoice-dollar me-1"></i> Shift Closing Report: <?= htmlspecialchars($closing['closing_no']) ?>
      </span>
      <span style="background: #e2e8f0; color: #334155; padding: 2px 8px; border-radius: 4px; font-size: 11.5px; font-weight: 600;">
        <?= htmlspecialchars($closing['shift_name'] ?? 'General Shift') ?>
      </span>
    </div>
    <div style="display: flex; gap: 8px;">
      <button onclick="window.close()" class="btn btn-close-win">
        <i class="fa-solid fa-xmark me-1"></i> Close (بند کریں)
      </button>
      <button onclick="window.print()" class="btn btn-print">
        <i class="fa-solid fa-print me-1"></i> Print A4 (پرنٹ رپورٹ)
      </button>
    </div>
  </div>

  <!-- A4 Printable Sheet -->
  <div class="a4-sheet">

    <!-- 1. BUSINESS HEADER / LETTERHEAD -->
    <div style="border-bottom: 3px solid #14307a; padding-bottom: 10px; margin-bottom: 12px;">
      <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 14px;">
        <!-- Left: Logo & English Branding -->
        <div style="display: flex; align-items: flex-start; gap: 12px; flex: 1 1 auto;">
          <img src="assets/images/logo.png" alt="Logo" style="width: 64px; height: 64px; object-fit: contain; flex-shrink: 0;">
          <div>
            <h3 style="margin: 0; font-weight: 800; color: #14307a; font-size: 21px; line-height: 1.2;">
              <?= htmlspecialchars($compName) ?>
            </h3>
            <div style="font-size: 12px; font-weight: 700; color: #1e293b; margin-top: 2px;">
              Gujranwala Steel Group of Industry
            </div>
            <div style="font-size: 10.5px; color: #64748b; font-weight: 600; margin-top: 1px;">
              Prime Re-Rolled Steel Bars, G.I Wire &amp; Structural Steel
            </div>
            <div style="font-size: 10.5px; color: #475569; margin-top: 3px;">
              <i class="fa-solid fa-location-dot text-primary me-1"></i> <?= htmlspecialchars($compAddress) ?>
            </div>
          </div>
        </div>

        <!-- Right: Urdu Name, Motto & Contact -->
        <div style="flex: 0 0 auto; text-align: right; max-width: 44%;">
          <div class="ur" dir="rtl" style="color: #14307a; font-size: 24px; font-weight: 700; line-height: 1.4; margin-bottom: 1px;">
            <?= htmlspecialchars($compUrdu) ?>
          </div>
          <?php if (!empty($company['company_motto_urdu'])): ?>
            <div class="ur text-muted" dir="rtl" style="font-size: 10.5px; line-height: 1.4; margin-bottom: 2px;">
              <?= htmlspecialchars($company['company_motto_urdu']) ?>
            </div>
          <?php endif; ?>
          <div style="font-size: 11px; color: #1e293b; margin-top: 2px;">
            <i class="fa-solid fa-phone text-primary me-1"></i> <strong><?= htmlspecialchars($compPhone) ?></strong>
          </div>
          <div style="margin-top: 4px;">
            <span style="background: #14307a; color: #fff; font-size: 10.5px; font-weight: 800; padding: 3px 8px; border-radius: 3px; letter-spacing: 0.5px; text-transform: uppercase;">
              Daily Shift Cash Audit Report
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- 2. SHIFT & CLOSING METADATA BAR -->
    <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 4px; padding: 8px 12px; margin-bottom: 12px; font-size: 11.5px;">
      <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px;">
        <div>
          <span style="color: #64748b; display: block; font-size: 10px; font-weight: 700; text-transform: uppercase;">Shift Number</span>
          <strong class="font-num" style="color: #14307a; font-size: 13px;"><?= htmlspecialchars($closing['closing_no']) ?></strong>
        </div>
        <div>
          <span style="color: #64748b; display: block; font-size: 10px; font-weight: 700; text-transform: uppercase;">Closing Date</span>
          <strong><?= date('d-M-Y (l)', strtotime($closingDate)) ?></strong>
        </div>
        <div>
          <span style="color: #64748b; display: block; font-size: 10px; font-weight: 700; text-transform: uppercase;">Shift Timing</span>
          <strong><?= !empty($openTime) ? date('h:i A', strtotime($openTime)) : '-' ?> &ndash; <?= !empty($closeTime) ? date('h:i A', strtotime($closeTime)) : 'Open' ?></strong>
        </div>
        <div>
          <span style="color: #64748b; display: block; font-size: 10px; font-weight: 700; text-transform: uppercase;">Cashier / Opened By</span>
          <strong><?= htmlspecialchars($closing['opened_by_name'] ?? ($closing['closed_by_name'] ?? 'Authorized User')) ?></strong>
        </div>
      </div>
    </div>

    <!-- 3. MAIN SECTION: 3-COLUMN RECONCILIATION TABLE (Exact Screenshot 2 Structure) -->
    <div style="margin-bottom: 2px;">
      <span style="font-size: 11.5px; font-weight: 700; color: #14307a; text-transform: uppercase; letter-spacing: 0.5px;">
        <i class="fa-solid fa-table-cells me-1"></i> Shift Transactions Breakdown (Transfers · Expense · Income)
      </span>
      <span style="font-size: 10.5px; color: #64748b; margin-left: 8px;">(Click any reference number to view the bill/voucher in a new tab)</span>
    </div>

    <table class="reconcile-grid-table">
      <thead>
        <tr>
          <!-- Category 1: Transfers -->
          <th colspan="2" class="cat-head">Transfers</th>
          <!-- Category 2: Expense -->
          <th colspan="2" class="cat-head">Expense</th>
          <!-- Category 3: Income -->
          <th colspan="2" class="cat-head">Income</th>
        </tr>
        <tr>
          <th style="width: 18%;">Ref #</th>
          <th style="width: 15%;">Amount</th>
          <th style="width: 18%;">Ref #</th>
          <th style="width: 15%;">Amount</th>
          <th style="width: 18%;">Ref #</th>
          <th style="width: 16%;">Amount</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($i = 0; $i < $maxRows; $i++): 
          $trf = $transferEntries[$i] ?? null;
          $exp = $expenseEntries[$i] ?? null;
          $inc = $incomeEntries[$i] ?? null;
        ?>
          <tr>
            <!-- Transfers Ref & Amount -->
            <td style="text-align: center;">
              <?php if ($trf): ?>
                <a href="<?= htmlspecialchars($trf['url']) ?>" target="_blank" class="ref-link" title="<?= htmlspecialchars($trf['label']) ?>">
                  <?= htmlspecialchars($trf['ref']) ?>
                </a>
              <?php else: ?>
                &nbsp;
              <?php endif; ?>
            </td>
            <td class="amt-cell">
              <?= $trf ? number_format($trf['amount'], 2) : '' ?>
            </td>

            <!-- Expense Ref & Amount -->
            <td style="text-align: center;">
              <?php if ($exp): ?>
                <a href="<?= htmlspecialchars($exp['url']) ?>" target="_blank" class="ref-link" title="<?= htmlspecialchars($exp['label']) ?>">
                  <?= htmlspecialchars($exp['ref']) ?>
                </a>
              <?php else: ?>
                &nbsp;
              <?php endif; ?>
            </td>
            <td class="amt-cell" style="<?= $exp ? 'color: #991b1b;' : '' ?>">
              <?= $exp ? number_format($exp['amount'], 2) : '' ?>
            </td>

            <!-- Income Ref & Amount -->
            <td style="text-align: center;">
              <?php if ($inc): ?>
                <a href="<?= htmlspecialchars($inc['url']) ?>" target="_blank" class="ref-link" title="<?= htmlspecialchars($inc['label']) ?>">
                  <?= htmlspecialchars($inc['ref']) ?>
                </a>
              <?php else: ?>
                &nbsp;
              <?php endif; ?>
            </td>
            <td class="amt-cell" style="<?= $inc ? 'color: #166534;' : '' ?>">
              <?= $inc ? number_format($inc['amount'], 2) : '' ?>
            </td>
          </tr>
        <?php endfor; ?>
      </tbody>
      <tfoot>
        <tr>
          <th style="text-align: right; text-transform: uppercase;">Total Transfers:</th>
          <td class="amt-cell font-num">
            <?= number_format($totTransfer, 2) ?>
          </td>
          <th style="text-align: right; text-transform: uppercase;">Total Expense:</th>
          <td class="amt-cell font-num" style="color: #991b1b;">
            <?= number_format($totExpense, 2) ?>
          </td>
          <th style="text-align: right; text-transform: uppercase;">Total Income:</th>
          <td class="amt-cell font-num" style="color: #166534;">
            <?= number_format($totIncome, 2) ?>
          </td>
        </tr>
      </tfoot>
    </table>

    <!-- 4. BELOW: COMPLETE RECONCILED FINANCIAL SUMMARY DETAIL (Matching Screenshot 1) -->
    <div class="summary-box">
      <div class="summary-header">
        <span>
          <i class="fa-solid fa-scale-balanced me-1"></i> Reconciled Financial Summary &amp; Safe Reconciliation
        </span>
        <span class="ur" dir="rtl" style="font-size: 12px; font-weight: 700;">
          خلاصہ حساب و دراز کیش تصدیق
        </span>
      </div>
      <div style="display: grid; grid-template-columns: 1fr 1fr;">
        <!-- Left Sub-table: Inflows & Sales -->
        <table class="summary-table" style="border-right: 1.5px solid #0f172a;">
          <tr>
            <th style="width: 60%;">Opening Cash Float (ابتدائی کیش):</th>
            <td class="font-num fw-bold" style="text-align: right;">
              <?= formatCurrency((float)$closing['opening_cash']) ?>
            </td>
          </tr>
          <tr>
            <th>Cash Paid by Customers (نقد وصولی):</th>
            <td class="font-num text-success fw-bold" style="text-align: right; color: #166534;">
              + <?= formatCurrency((float)$closing['cash_sales']) ?>
            </td>
          </tr>
          <tr>
            <th>Customer Cash Collections (RV ریکوری):</th>
            <td class="font-num text-success fw-bold" style="text-align: right; color: #166534;">
              + <?= formatCurrency((float)$closing['customer_cash_collections']) ?>
            </td>
          </tr>
          <tr>
            <th>Total Gross Sales (کل فروخت بل):</th>
            <td class="font-num" style="text-align: right;">
              <?= formatCurrency((float)$closing['gross_sales']) ?>
            </td>
          </tr>
          <tr>
            <th>Credit Sales (ادھار مال بقایا):</th>
            <td class="font-num" style="text-align: right; color: #1d4ed8;">
              <?= formatCurrency((float)$closing['credit_sales']) ?>
            </td>
          </tr>
        </table>

        <!-- Right Sub-table: Outflows & Drawer Balancing -->
        <table class="summary-table">
          <tr>
            <th style="width: 60%;">Supplier Cash Payments (PV ادائیگیاں):</th>
            <td class="font-num fw-bold" style="text-align: right; color: #991b1b;">
              − <?= formatCurrency((float)$closing['supplier_cash_payments']) ?>
            </td>
          </tr>
          <tr>
            <th>Daily Cash Expenses (EV کل اخراجات):</th>
            <td class="font-num fw-bold" style="text-align: right; color: #991b1b;">
              − <?= formatCurrency((float)$closing['cash_expenses']) ?>
            </td>
          </tr>
          <tr style="background: #eff6ff;">
            <th style="font-weight: 700; color: #14307a;">Expected Cash in Drawer (متوقع کیش):</th>
            <td class="font-num fw-bold" style="text-align: right; font-size: 12.5px; color: #14307a;">
              <?= formatCurrency((float)$closing['expected_cash']) ?>
            </td>
          </tr>
          <tr style="background: #f8fafc;">
            <th style="font-weight: 700;">Actual Physical Cash Counted (گنی گئی رقم):</th>
            <td class="font-num fw-bold" style="text-align: right; font-size: 12.5px;">
              <?= formatCurrency((float)$closing['actual_cash_counted']) ?>
            </td>
          </tr>
          <?php 
            $diff = (float)$closing['cash_difference'];
            $diffBg = $diff < 0 ? '#fef2f2' : ($diff > 0 ? '#f0fdf4' : '#fff');
            $diffColor = $diff < 0 ? '#b91c1c' : ($diff > 0 ? '#15803d' : '#334155');
            $diffLabel = $diff < 0 ? 'Shortage (کمی)' : ($diff > 0 ? 'Surplus (بچت)' : 'Balanced (مکمل برابر)');
          ?>
          <tr style="background: <?= $diffBg ?>;">
            <th style="font-weight: 800; color: <?= $diffColor ?>;">Cash Difference (فرق):</th>
            <td class="font-num fw-bold" style="text-align: right; font-size: 13px; color: <?= $diffColor ?>;">
              <?= formatCurrency($diff) ?> <small>(<?= $diffLabel ?>)</small>
            </td>
          </tr>
        </table>
      </div>
    </div>

    <?php if (!empty($closing['notes'])): ?>
      <div style="margin-top: 8px; padding: 6px 10px; background: #fffbeb; border: 1px solid #fef3c7; border-radius: 4px; font-size: 11px;">
        <strong>Shift Remarks / Notes:</strong> <?= htmlspecialchars($closing['notes']) ?>
      </div>
    <?php endif; ?>

    <!-- 5. SIGNATURE & VERIFICATION BLOCK -->
    <div style="margin-top: 40px; display: flex; justify-content: space-between; text-align: center; font-size: 11.5px; color: #334155;">
      <div style="width: 30%; border-top: 1.5px solid #000; padding-top: 6px;">
        <strong>Cashier Signature (دستخط کیشئر)</strong><br>
        <span style="font-size: 10.5px; color: #64748b;"><?= htmlspecialchars($closing['opened_by_name'] ?? 'Cashier') ?></span>
      </div>
      <div style="width: 30%; border-top: 1.5px solid #000; padding-top: 6px;">
        <strong>Verified &amp; Locked By (تصدیق منیجر)</strong><br>
        <span style="font-size: 10.5px; color: #64748b;"><?= htmlspecialchars($closing['closed_by_name'] ?? 'Manager / Owner') ?></span>
      </div>
      <div style="width: 30%; border-top: 1.5px solid #000; padding-top: 6px;">
        <strong>Audit Approval (آڈٹ منظوری)</strong><br>
        <span style="font-size: 10.5px; color: #64748b;"><?= date('d-M-Y h:i A') ?></span>
      </div>
    </div>

  </div>

</body>
</html>
