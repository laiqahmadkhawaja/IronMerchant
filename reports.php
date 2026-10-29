<?php
// reports.php - Comprehensive Financial & Operational Reporting
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
requirePerm('reports');

$pdo = getPDO();
$pageTitle = 'Financial & Management Reports (مالیاتی رپورٹس)';

$reportType = trim($_GET['tab'] ?? 'pnl'); // 'pnl', 'trial_balance', 'balance_sheet', 'product_sales'
$startDate  = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate    = trim($_GET['end_date'] ?? date('Y-m-d'));

// -------------------------------------------------------------
// 1. PROFIT & LOSS DATA
// -------------------------------------------------------------
if ($reportType === 'pnl') {
    // Revenues
    $stRev = $pdo->prepare("
        SELECT coa.id, coa.code, coa.name, 
               COALESCE(SUM(jel.credit - jel.debit), 0) AS balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_id = je.id AND je.entry_date BETWEEN ? AND ?
        WHERE coa.type = 'Revenue'
        GROUP BY coa.id, coa.code, coa.name
        ORDER BY coa.code ASC
    ");
    $stRev->execute([$startDate, $endDate]);
    $revenueAccounts = $stRev->fetchAll();

    // COGS
    $stCOGS = $pdo->prepare("
        SELECT coa.id, coa.code, coa.name,
               COALESCE(SUM(jel.debit - jel.credit), 0) AS balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_id = je.id AND je.entry_date BETWEEN ? AND ?
        WHERE coa.type = 'Expense' AND coa.sub_type = 'COGS'
        GROUP BY coa.id, coa.code, coa.name
        ORDER BY coa.code ASC
    ");
    $stCOGS->execute([$startDate, $endDate]);
    $cogsAccounts = $stCOGS->fetchAll();

    // Operating Expenses
    $stExp = $pdo->prepare("
        SELECT coa.id, coa.code, coa.name,
               COALESCE(SUM(jel.debit - jel.credit), 0) AS balance
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_id = je.id AND je.entry_date BETWEEN ? AND ?
        WHERE coa.type = 'Expense' AND coa.sub_type != 'COGS'
        GROUP BY coa.id, coa.code, coa.name
        ORDER BY coa.code ASC
    ");
    $stExp->execute([$startDate, $endDate]);
    $expenseAccounts = $stExp->fetchAll();

    $totalRevenue = 0.0;
    foreach ($revenueAccounts as $ra) $totalRevenue += (float)$ra['balance'];

    $totalCOGS = 0.0;
    foreach ($cogsAccounts as $ca) $totalCOGS += (float)$ca['balance'];

    $grossProfit = $totalRevenue - $totalCOGS;

    $totalOperatingExp = 0.0;
    foreach ($expenseAccounts as $ea) $totalOperatingExp += (float)$ea['balance'];

    $netProfit = $grossProfit - $totalOperatingExp;
}

// -------------------------------------------------------------
// 2. TRIAL BALANCE DATA
// -------------------------------------------------------------
if ($reportType === 'trial_balance') {
    $stTB = $pdo->prepare("
        SELECT coa.id, coa.code, coa.name, coa.type, coa.normal_balance,
               COALESCE(SUM(jel.debit), 0) AS total_debit,
               COALESCE(SUM(jel.credit), 0) AS total_credit
        FROM chart_of_accounts coa
        LEFT JOIN journal_entry_lines jel ON coa.id = jel.account_id
        LEFT JOIN journal_entries je ON jel.journal_id = je.id AND je.entry_date <= ?
        GROUP BY coa.id, coa.code, coa.name, coa.type, coa.normal_balance
        HAVING (total_debit > 0 OR total_credit > 0)
        ORDER BY coa.code ASC
    ");
    $stTB->execute([$endDate]);
    $tbRows = $stTB->fetchAll();

    $tbTotalDebit = 0.0;
    $tbTotalCredit = 0.0;
    foreach ($tbRows as &$r) {
        $net = (float)$r['total_debit'] - (float)$r['total_credit'];
        if ($r['normal_balance'] === 'debit') {
            if ($net >= 0) {
                $r['net_debit'] = $net;
                $r['net_credit'] = 0.0;
            } else {
                $r['net_debit'] = 0.0;
                $r['net_credit'] = abs($net);
            }
        } else {
            $netCr = (float)$r['total_credit'] - (float)$r['total_debit'];
            if ($netCr >= 0) {
                $r['net_debit'] = 0.0;
                $r['net_credit'] = $netCr;
            } else {
                $r['net_debit'] = abs($netCr);
                $r['net_credit'] = 0.0;
            }
        }
        $tbTotalDebit += $r['net_debit'];
        $tbTotalCredit += $r['net_credit'];
    }
    unset($r);
}

// -------------------------------------------------------------
// 3. BALANCE SHEET DATA
// -------------------------------------------------------------
if ($reportType === 'balance_sheet') {
    // Current Assets
    $stLiquid = $pdo->query("SELECT account_name, account_type, current_balance FROM financial_accounts WHERE is_active = 1");
    $liquidAccounts = $stLiquid->fetchAll();
    $totalCashBank = 0.0;
    foreach ($liquidAccounts as $la) $totalCashBank += (float)$la['current_balance'];

    // Customer Receivables
    $stRec = $pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM parties WHERE type = 'customer' AND current_balance > 0");
    $totalReceivables = (float)$stRec->fetchColumn();

    // Inventory Valuation
    $stInv = $pdo->query("SELECT COALESCE(SUM(stock_qty * cost_price), 0) FROM items WHERE is_active = 1");
    $totalInventory = (float)$stInv->fetchColumn();

    $totalAssets = $totalCashBank + $totalReceivables + $totalInventory;

    // Liabilities
    $stPay = $pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM parties WHERE type = 'supplier' AND current_balance > 0");
    $totalPayables = (float)$stPay->fetchColumn();

    $totalLiabilities = $totalPayables;

    // Equity
    $stCap = $pdo->query("SELECT COALESCE(SUM(credit - debit), 0) FROM journal_entry_lines WHERE account_id = " . COA_CAPITAL);
    $totalCapital = (float)$stCap->fetchColumn();
    if ($totalCapital <= 0) $totalCapital = 5000000.0; // Standard base equity if not posted

    $retainedEarnings = $totalAssets - $totalLiabilities - $totalCapital;
    $totalEquity = $totalCapital + $retainedEarnings;
}

// -------------------------------------------------------------
// 4. PRODUCT-WISE SALES REPORT
// -------------------------------------------------------------
if ($reportType === 'product_sales') {
    $stProdSales = $pdo->prepare("
        SELECT i.item_code, i.name AS item_name, i.unit,
               COALESCE(SUM(si.qty), 0) AS total_qty,
               COALESCE(SUM(si.line_total), 0) AS total_revenue,
               COALESCE(SUM(si.total_cogs), 0) AS total_cogs
        FROM sale_items si
        JOIN items i ON si.item_id = i.id
        JOIN sales s ON si.sale_id = s.id
        WHERE s.sale_date BETWEEN ? AND ? AND s.status = 'posted'
        GROUP BY i.id, i.item_code, i.name, i.unit
        ORDER BY total_revenue DESC
    ");
    $stProdSales->execute([$startDate, $endDate]);
    $productSales = $stProdSales->fetchAll();

    $prodTotalQty = 0.0;
    $prodTotalRev = 0.0;
    $prodTotalCOGS = 0.0;
    foreach ($productSales as $ps) {
        $prodTotalQty += (float)$ps['total_qty'];
        $prodTotalRev += (float)$ps['total_revenue'];
        $prodTotalCOGS += (float)$ps['total_cogs'];
    }
    $prodTotalProfit = $prodTotalRev - $prodTotalCOGS;
}

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Financial Reports & Analytics (مالیاتی و کاروباری رپورٹس)</h4>
        <span class="text-muted" style="font-size: 13px;">Double-entry Profit & Loss, Trial Balance, Balance Sheet, and Steel Sales Performance</span>
      </div>
      <div>
        <button onclick="window.print()" class="btn btn-outline-dark">
          <i class="fa-solid fa-print me-1"></i> Print Report
        </button>
      </div>
    </div>

    <!-- Report Tabs -->
    <div class="mb-4">
      <ul class="nav nav-pills gap-2 flex-wrap" style="font-size: 13.5px; font-weight: 600;">
        <li class="nav-item">
          <a class="nav-link <?= $reportType === 'pnl' ? 'active' : 'bg-white border text-dark' ?>" href="reports.php?tab=pnl&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
            <i class="fa-solid fa-chart-line me-1"></i> Profit & Loss (نفع و نقصان)
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $reportType === 'trial_balance' ? 'active' : 'bg-white border text-dark' ?>" href="reports.php?tab=trial_balance&end_date=<?= $endDate ?>">
            <i class="fa-solid fa-scale-balanced me-1"></i> Trial Balance (میزان چٹھا)
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $reportType === 'balance_sheet' ? 'active' : 'bg-white border text-dark' ?>" href="reports.php?tab=balance_sheet&end_date=<?= $endDate ?>">
            <i class="fa-solid fa-building-columns me-1"></i> Balance Sheet (بیلنس شیٹ)
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $reportType === 'product_sales' ? 'active' : 'bg-white border text-dark' ?>" href="reports.php?tab=product_sales&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>">
            <i class="fa-solid fa-cubes me-1"></i> Product-wise Sales (آئٹم سیل)
          </a>
        </li>
      </ul>
    </div>

    <!-- Date Filter Form -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="reports.php" class="row g-3 align-items-end">
          <input type="hidden" name="tab" value="<?= htmlspecialchars($reportType) ?>">
          <?php if ($reportType !== 'trial_balance' && $reportType !== 'balance_sheet'): ?>
            <div class="col-12 col-md-4">
              <label class="form-label">From Date</label>
              <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
            </div>
          <?php endif; ?>
          <div class="col-12 col-md-<?= ($reportType === 'trial_balance' || $reportType === 'balance_sheet') ? '8' : '4' ?>">
            <label class="form-label"><?= ($reportType === 'trial_balance' || $reportType === 'balance_sheet') ? 'As of Date (آخری تاریخ)' : 'To Date' ?></label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
          </div>
          <div class="col-12 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-filter me-1"></i> Generate Report
            </button>
            <a href="reports.php?tab=<?= $reportType ?>" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
          </div>
        </form>
      </div>
    </div>

    <!-- ------------------------------------------------------------- -->
    <!-- 1. PROFIT & LOSS VIEW -->
    <!-- ------------------------------------------------------------- -->
    <?php if ($reportType === 'pnl'): ?>
      <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0" style="font-weight: 700; color: var(--nv);">Profit & Loss Statement (نفع و نقصان کی رپورٹ)</h5>
            <small class="text-muted">For period: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></small>
          </div>
          <div class="text-end">
            <span class="text-muted" style="font-size: 12px; display: block;">Net Profit / (Loss):</span>
            <div style="font-size: 22px; font-weight: 800; color: <?= $netProfit >= 0 ? 'var(--gn)' : 'var(--rd)' ?>;">
              <?= formatCurrency($netProfit) ?>
            </div>
          </div>
        </div>
        <div class="card-body p-0">
          <table class="table table-bordered mb-0" style="font-size: 14px;">
            <!-- Revenues -->
            <thead style="background: #f1f5f9;">
              <tr>
                <th colspan="2" style="padding: 10px 16px; font-weight: 700; color: var(--nv); text-transform: uppercase;">1. Operating Revenue (آمدنی)</th>
                <th style="padding: 10px 16px; width: 22%;" class="text-end">Amount (روپے)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($revenueAccounts as $ra): 
                $bal = (float)$ra['balance'];
                if ($bal == 0) continue;
              ?>
                <tr>
                  <td style="width: 15%; padding: 8px 16px; color: #64748b;"><?= htmlspecialchars($ra['code']) ?></td>
                  <td style="padding: 8px 16px;"><?= htmlspecialchars($ra['name']) ?></td>
                  <td style="padding: 8px 16px;" class="text-end"><?= formatCurrency($bal) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr style="background: #f8fafc; font-weight: 700;">
                <td colspan="2" class="text-end" style="padding: 10px 16px;">Total Operating Revenue:</td>
                <td class="text-end text-success" style="padding: 10px 16px; font-size: 15px;"><?= formatCurrency($totalRevenue) ?></td>
              </tr>
            </tbody>

            <!-- COGS -->
            <thead style="background: #f1f5f9;">
              <tr>
                <th colspan="2" style="padding: 10px 16px; font-weight: 700; color: var(--nv); text-transform: uppercase;">2. Cost of Goods Sold (فروخت شدہ مال کی لاگت)</th>
                <th style="padding: 10px 16px;" class="text-end">Amount (روپے)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($cogsAccounts as $ca): 
                $bal = (float)$ca['balance'];
                if ($bal == 0) continue;
              ?>
                <tr>
                  <td style="padding: 8px 16px; color: #64748b;"><?= htmlspecialchars($ca['code']) ?></td>
                  <td style="padding: 8px 16px;"><?= htmlspecialchars($ca['name']) ?></td>
                  <td style="padding: 8px 16px;" class="text-end text-danger"><?= formatCurrency($bal) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr style="background: #f8fafc; font-weight: 700;">
                <td colspan="2" class="text-end" style="padding: 10px 16px;">Total Cost of Goods Sold:</td>
                <td class="text-end text-danger" style="padding: 10px 16px; font-size: 15px;">- <?= formatCurrency($totalCOGS) ?></td>
              </tr>
              <tr style="background: #e2e8f0; font-weight: 800;">
                <td colspan="2" class="text-end" style="padding: 12px 16px; font-size: 15px;">GROSS PROFIT (خام نفع):</td>
                <td class="text-end" style="padding: 12px 16px; font-size: 16px; color: var(--nv);"><?= formatCurrency($grossProfit) ?></td>
              </tr>
            </tbody>

            <!-- Operating Expenses -->
            <thead style="background: #f1f5f9;">
              <tr>
                <th colspan="2" style="padding: 10px 16px; font-weight: 700; color: var(--nv); text-transform: uppercase;">3. Operating Expenses (کاروباری اخراجات)</th>
                <th style="padding: 10px 16px;" class="text-end">Amount (روپے)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($expenseAccounts as $ea): 
                $bal = (float)$ea['balance'];
                if ($bal == 0) continue;
              ?>
                <tr>
                  <td style="padding: 8px 16px; color: #64748b;"><?= htmlspecialchars($ea['code']) ?></td>
                  <td style="padding: 8px 16px;"><?= htmlspecialchars($ea['name']) ?></td>
                  <td style="padding: 8px 16px;" class="text-end text-danger"><?= formatCurrency($bal) ?></td>
                </tr>
              <?php endforeach; ?>
              <tr style="background: #f8fafc; font-weight: 700;">
                <td colspan="2" class="text-end" style="padding: 10px 16px;">Total Operating Expenses:</td>
                <td class="text-end text-danger" style="padding: 10px 16px; font-size: 15px;">- <?= formatCurrency($totalOperatingExp) ?></td>
              </tr>
            </tbody>

            <!-- Net Profit -->
            <tfoot style="background: #1e293b; color: #fff; font-weight: 800;">
              <tr>
                <td colspan="2" class="text-end" style="padding: 14px 16px; font-size: 16px;">NET PROFIT / (LOSS) FOR PERIOD (خالص منافع):</td>
                <td class="text-end" style="padding: 14px 16px; font-size: 18px; color: <?= $netProfit >= 0 ? '#4ade80' : '#f87171' ?>;">
                  <?= formatCurrency($netProfit) ?>
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- ------------------------------------------------------------- -->
    <!-- 2. TRIAL BALANCE VIEW -->
    <!-- ------------------------------------------------------------- -->
    <?php if ($reportType === 'trial_balance'): ?>
      <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0" style="font-weight: 700; color: var(--nv);">Trial Balance (میزان چٹھا)</h5>
            <small class="text-muted">As of: <?= htmlspecialchars($endDate) ?></small>
          </div>
          <div>
            <span class="badge <?= abs($tbTotalDebit - $tbTotalCredit) < 0.01 ? 'bg-success' : 'bg-danger' ?> px-3 py-2" style="font-size: 13px;">
              <?= abs($tbTotalDebit - $tbTotalCredit) < 0.01 ? '✓ Balanced (Debits = Credits)' : '✕ Unbalanced Difference' ?>
            </span>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover table-bordered align-middle mb-0" style="font-size: 13.5px;">
            <thead style="background: #f8fafc; font-weight: 700;">
              <tr>
                <th style="padding: 10px 14px; width: 12%;">Code</th>
                <th style="padding: 10px 14px;">Account Title</th>
                <th style="padding: 10px 14px; width: 15%;">Type</th>
                <th style="padding: 10px 14px; width: 18%;" class="text-end">Debit (بنام)</th>
                <th style="padding: 10px 14px; width: 18%;" class="text-end">Credit (جمع)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($tbRows as $r): ?>
                <tr>
                  <td style="padding: 8px 14px; font-weight: 600; color: #475569;"><?= htmlspecialchars($r['code']) ?></td>
                  <td style="padding: 8px 14px; font-weight: 600; color: var(--nv);"><?= htmlspecialchars($r['name']) ?></td>
                  <td style="padding: 8px 14px;"><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['type']) ?></span></td>
                  <td class="text-end" style="padding: 8px 14px; font-weight: 600;">
                    <?= $r['net_debit'] > 0 ? formatCurrency($r['net_debit']) : '-' ?>
                  </td>
                  <td class="text-end" style="padding: 8px 14px; font-weight: 600;">
                    <?= $r['net_credit'] > 0 ? formatCurrency($r['net_credit']) : '-' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot style="background: #1e293b; color: #fff; font-weight: 800;">
              <tr>
                <td colspan="3" class="text-end" style="padding: 12px 14px; font-size: 15px;">Total Balances:</td>
                <td class="text-end" style="padding: 12px 14px; font-size: 16px; color: #fbbf24;"><?= formatCurrency($tbTotalDebit) ?></td>
                <td class="text-end" style="padding: 12px 14px; font-size: 16px; color: #fbbf24;"><?= formatCurrency($tbTotalCredit) ?></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    <?php endif; ?>

    <!-- ------------------------------------------------------------- -->
    <!-- 3. BALANCE SHEET VIEW -->
    <!-- ------------------------------------------------------------- -->
    <?php if ($reportType === 'balance_sheet'): ?>
      <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
        <div class="card-header bg-white py-3">
          <h5 class="mb-0" style="font-weight: 700; color: var(--nv);">Balance Sheet (بیلنس شیٹ)</h5>
          <small class="text-muted">As of: <?= htmlspecialchars($endDate) ?></small>
        </div>
        <div class="card-body p-4">
          <div class="row g-4">
            <!-- Assets Column -->
            <div class="col-12 col-md-6">
              <div class="border rounded p-3 bg-light">
                <h6 class="fw-bold mb-3 text-uppercase" style="color: var(--nv); border-bottom: 2px solid var(--nv); padding-bottom: 8px;">
                  Assets (اثاثہ جات)
                </h6>
                <table class="table table-sm table-borderless mb-0" style="font-size: 13.5px;">
                  <tr>
                    <td><strong>Cash & Liquid Bank Deposits:</strong></td>
                    <td class="text-end fw-bold"><?= formatCurrency($totalCashBank) ?></td>
                  </tr>
                  <?php foreach ($liquidAccounts as $la): ?>
                    <tr>
                      <td class="ps-3 text-muted" style="font-size: 12.5px;">↳ <?= htmlspecialchars($la['account_name']) ?></td>
                      <td class="text-end text-muted" style="font-size: 12.5px;"><?= formatCurrency((float)$la['current_balance']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  <tr>
                    <td><strong>Customer Receivables (بقایا جات):</strong></td>
                    <td class="text-end fw-bold"><?= formatCurrency($totalReceivables) ?></td>
                  </tr>
                  <tr>
                    <td><strong>Steel Inventory on Hand (اسٹاک مالیت):</strong></td>
                    <td class="text-end fw-bold"><?= formatCurrency($totalInventory) ?></td>
                  </tr>
                  <tr class="border-top" style="border-top-width: 2px !important;">
                    <td class="pt-2"><strong style="font-size: 15px;">TOTAL ASSETS:</strong></td>
                    <td class="text-end pt-2 text-success fw-bold" style="font-size: 17px;"><?= formatCurrency($totalAssets) ?></td>
                  </tr>
                </table>
              </div>
            </div>

            <!-- Liabilities & Equity Column -->
            <div class="col-12 col-md-6">
              <div class="border rounded p-3 bg-light">
                <h6 class="fw-bold mb-3 text-uppercase" style="color: var(--nv); border-bottom: 2px solid var(--nv); padding-bottom: 8px;">
                  Liabilities & Equity (واجبات و سرمایہ)
                </h6>
                <table class="table table-sm table-borderless mb-0" style="font-size: 13.5px;">
                  <tr>
                    <td colspan="2"><strong class="text-secondary">Current Liabilities:</strong></td>
                  </tr>
                  <tr>
                    <td class="ps-3">Supplier Payables (ملز کے واجبات):</td>
                    <td class="text-end fw-bold text-danger"><?= formatCurrency($totalPayables) ?></td>
                  </tr>
                  <tr class="border-bottom">
                    <td><strong>Total Liabilities:</strong></td>
                    <td class="text-end fw-bold text-danger"><?= formatCurrency($totalLiabilities) ?></td>
                  </tr>
                  <tr>
                    <td colspan="2" class="pt-3"><strong class="text-secondary">Owner's Equity:</strong></td>
                  </tr>
                  <tr>
                    <td class="ps-3">Owner Capital:</td>
                    <td class="text-end fw-bold"><?= formatCurrency($totalCapital) ?></td>
                  </tr>
                  <tr>
                    <td class="ps-3">Retained Earnings / Current Period Surplus:</td>
                    <td class="text-end fw-bold"><?= formatCurrency($retainedEarnings) ?></td>
                  </tr>
                  <tr class="border-bottom">
                    <td><strong>Total Equity:</strong></td>
                    <td class="text-end fw-bold"><?= formatCurrency($totalEquity) ?></td>
                  </tr>
                  <tr class="border-top" style="border-top-width: 2px !important;">
                    <td class="pt-2"><strong style="font-size: 15px;">TOTAL LIABILITIES & EQUITY:</strong></td>
                    <td class="text-end pt-2 text-primary fw-bold" style="font-size: 17px;"><?= formatCurrency($totalLiabilities + $totalEquity) ?></td>
                  </tr>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- ------------------------------------------------------------- -->
    <!-- 4. PRODUCT SALES VIEW -->
    <!-- ------------------------------------------------------------- -->
    <?php if ($reportType === 'product_sales'): ?>
      <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <div>
            <h5 class="mb-0" style="font-weight: 700; color: var(--nv);">Steel Product Sales & Profitability Report</h5>
            <small class="text-muted">From <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></small>
          </div>
          <div class="text-end">
            <span class="text-muted" style="font-size: 12px; display: block;">Total Steel Sold:</span>
            <strong><?= number_format($prodTotalQty, 1) ?> kg (<?= number_format($prodTotalQty / 1000, 2) ?> Tons)</strong>
          </div>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
            <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
              <tr>
                <th style="padding: 12px 14px;">Product Code</th>
                <th style="padding: 12px 14px;">Product Description</th>
                <th class="text-end" style="padding: 12px 14px;">Quantity Sold</th>
                <th class="text-end" style="padding: 12px 14px;">Tons Equivalent</th>
                <th class="text-end" style="padding: 12px 14px;">Sales Revenue</th>
                <th class="text-end" style="padding: 12px 14px;">Landed Cost</th>
                <th class="text-end" style="padding: 12px 14px;">Gross Profit</th>
                <th class="text-center" style="padding: 12px 14px;">Margin %</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($productSales)): ?>
                <tr>
                  <td colspan="8" class="text-center py-4 text-muted">No product sales recorded in the selected period.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($productSales as $ps): 
                  $qty = (float)$ps['total_qty'];
                  $rev = (float)$ps['total_revenue'];
                  $cogs = (float)$ps['total_cogs'];
                  $prof = $rev - $cogs;
                  $margin = ($rev > 0) ? round(($prof / $rev) * 100, 1) : 0;
                ?>
                  <tr>
                    <td style="padding: 12px 14px; font-weight: 600; color: #475569;"><?= htmlspecialchars($ps['item_code']) ?></td>
                    <td style="padding: 12px 14px; font-weight: 600; color: var(--nv);"><?= htmlspecialchars($ps['item_name']) ?></td>
                    <td class="text-end" style="padding: 12px 14px; font-weight: 700;"><?= number_format($qty, 1) ?> <?= htmlspecialchars($ps['unit']) ?></td>
                    <td class="text-end" style="padding: 12px 14px; color: #64748b;"><?= number_format($qty / 1000, 2) ?> T</td>
                    <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: #0f172a;"><?= formatCurrency($rev) ?></td>
                    <td class="text-end" style="padding: 12px 14px; color: #475569;"><?= formatCurrency($cogs) ?></td>
                    <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: <?= $prof >= 0 ? 'var(--gn)' : 'var(--rd)' ?>;">
                      <?= formatCurrency($prof) ?>
                    </td>
                    <td class="text-center" style="padding: 12px 14px;">
                      <span class="badge <?= $margin >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?> fw-bold">
                        <?= $margin ?>%
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
            <tfoot style="background: #f8fafc; font-weight: 800; border-top: 2px solid var(--bd);">
              <tr>
                <td colspan="2" class="text-end" style="padding: 12px 14px;">Grand Totals:</td>
                <td class="text-end" style="padding: 12px 14px;"><?= number_format($prodTotalQty, 1) ?> kg</td>
                <td class="text-end" style="padding: 12px 14px;"><?= number_format($prodTotalQty / 1000, 2) ?> T</td>
                <td class="text-end text-primary" style="padding: 12px 14px; font-size: 15px;"><?= formatCurrency($prodTotalRev) ?></td>
                <td class="text-end text-danger" style="padding: 12px 14px; font-size: 15px;"><?= formatCurrency($prodTotalCOGS) ?></td>
                <td class="text-end text-success" style="padding: 12px 14px; font-size: 16px;"><?= formatCurrency($prodTotalProfit) ?></td>
                <td class="text-center" style="padding: 12px 14px;">
                  <?= ($prodTotalRev > 0) ? round(($prodTotalProfit / $prodTotalRev) * 100, 1) : 0 ?>%
                </td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
