<?php
// closing.php - Daily Management, Shift Opening & Cash Closing Reconciliation
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/includes/accounting.php';

requireAuth();
requirePerm('closing');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Shift & Daily Cash Management (شفٹ اوپننگ و روزانہ کیش کلوزنگ)';
$errors = [];

$targetDate = trim($_GET['date'] ?? date('Y-m-d'));
$viewId = (int)($_GET['view_id'] ?? 0);

// Check if an active open shift exists in the system
$stActive = $pdo->query("
    SELECT dc.*, u.name AS user_name, op.name AS opener_name 
    FROM daily_closings dc 
    LEFT JOIN users u ON dc.user_id = u.id 
    LEFT JOIN users op ON dc.opened_by = op.id 
    WHERE dc.status = 'open' 
    ORDER BY dc.id DESC LIMIT 1
");
$activeShift = $stActive->fetch();

// Check if viewing a specific finalized closing
$viewClosing = null;
if ($viewId > 0) {
    $stV = $pdo->prepare("SELECT dc.*, u.name AS user_name, op.name AS opener_name FROM daily_closings dc LEFT JOIN users u ON dc.user_id = u.id LEFT JOIN users op ON dc.opened_by = op.id WHERE dc.id = ?");
    $stV->execute([$viewId]);
    $viewClosing = $stV->fetch();
}

// ---------------------------------------------------------
// ACTION 1: OPEN SHIFT (شفٹ اوپن کریں)
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'open_shift') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } elseif ($activeShift) {
        $errors[] = "An active shift ({$activeShift['shift_name']} #{$activeShift['closing_no']}) is already open. Please finalize it before opening a new shift.";
    } else {
        $openingCash = (float)($_POST['opening_cash'] ?? 0);
        $shiftName = trim($_POST['shift_name'] ?? 'General Day Shift');
        if ($shiftName === '') {
            $shiftName = 'General Day Shift';
        }
        $notes = trim($_POST['notes'] ?? '');

        // Bank balance
        $stB = $pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM financial_accounts WHERE account_type = 'Bank'");
        $openingBank = (float)$stB->fetchColumn();

        try {
            $closingNo = generateDocumentNumber('SFT', 'daily_closings', 'closing_no');
            $ins = $pdo->prepare("
                INSERT INTO daily_closings (
                    closing_no, shift_name, closing_date, opening_time, user_id, opened_by,
                    opening_cash, opening_bank, status, notes
                ) VALUES (?, ?, CURDATE(), CURTIME(), ?, ?, ?, ?, 'open', ?)
            ");
            $ins->execute([
                $closingNo, $shiftName, $currentUser['id'], $currentUser['id'],
                $openingCash, $openingBank, $notes
            ]);
            $newShiftId = (int)$pdo->lastInsertId();

            logAudit('create', 'daily_closings', $newShiftId, "Opened shift $closingNo ($shiftName) with opening cash Rs $openingCash");
            setFlash('success', "Shift $closingNo ($shiftName) has been OPENED with opening drawer cash of Rs " . number_format($openingCash, 2));
            header("Location: closing.php");
            exit;
        } catch (Exception $e) {
            $errors[] = 'Failed to open shift: ' . $e->getMessage();
        }
    }
}

// ---------------------------------------------------------
// ACTION 2: FINALIZE & CLOSE SHIFT (شفٹ کلوز کریں)
// ---------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'finalize_closing') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $shiftId = (int)($_POST['shift_id'] ?? ($activeShift['id'] ?? 0));
        $openingCash  = (float)($_POST['opening_cash'] ?? ($activeShift['opening_cash'] ?? 0));
        $openingBank  = (float)($_POST['opening_bank'] ?? ($activeShift['opening_bank'] ?? 0));
        $grossSales   = (float)($_POST['gross_sales'] ?? 0);
        $salesDisc    = (float)($_POST['sales_discount'] ?? 0);
        $netSales     = (float)($_POST['net_sales'] ?? 0);
        $cashSales    = (float)($_POST['cash_sales'] ?? 0);
        $creditSales  = (float)($_POST['credit_sales'] ?? 0);
        $bankSales    = (float)($_POST['bank_sales'] ?? 0);
        $custCollect  = (float)($_POST['customer_cash_collections'] ?? 0);
        $suppPay      = (float)($_POST['supplier_cash_payments'] ?? 0);
        $cashExp      = (float)($_POST['cash_expenses'] ?? 0);
        $expectedCash = (float)($_POST['expected_cash'] ?? 0);
        $actualCash   = (float)($_POST['actual_cash_counted'] ?? 0);
        $cashDiff     = round($actualCash - $expectedCash, 2);
        $notes        = trim($_POST['notes'] ?? '');

        try {
            $pdo->beginTransaction();

            $closingNo = $activeShift['closing_no'] ?? generateDocumentNumber('SFT', 'daily_closings', 'closing_no');

            // If there is cash shortage, record Cash Shortage Expense journal entry if significant
            if ($cashDiff < -0.01) {
                $shortageAmt = abs($cashDiff);
                $jLines = [
                    [
                        'account_id'  => 38, // 5190 Cash Shortage Expense
                        'debit'       => $shortageAmt,
                        'credit'      => 0.0,
                        'description' => "Cash shortage during shift closing ($closingNo)"
                    ],
                    [
                        'account_id'  => COA_CASH_MAIN,
                        'debit'       => 0.0,
                        'credit'      => $shortageAmt,
                        'description' => "Cash drawer reconciliation adjustment ($closingNo)"
                    ]
                ];
                postJournalEntry($pdo, [
                    'entry_no'    => getNextDocNo('JV', 'journal_entries', 'entry_no'),
                    'entry_date'  => $targetDate,
                    'source_type' => 'closing',
                    'narration'   => "Shift closing cash shortage: Rs $shortageAmt ($closingNo)",
                    'created_by'  => $currentUser['id'] ?? null
                ], $jLines);
            }

            if ($shiftId > 0) {
                // Update existing open shift to finalized
                $upd = $pdo->prepare("
                    UPDATE daily_closings SET
                        closing_time = CURTIME(),
                        user_id = ?,
                        gross_sales = ?,
                        sales_discount = ?,
                        net_sales = ?,
                        cash_sales = ?,
                        credit_sales = ?,
                        bank_sales = ?,
                        customer_cash_collections = ?,
                        supplier_cash_payments = ?,
                        cash_expenses = ?,
                        expected_cash = ?,
                        actual_cash_counted = ?,
                        cash_difference = ?,
                        status = 'finalized',
                        notes = ?
                    WHERE id = ?
                ");
                $upd->execute([
                    $currentUser['id'],
                    $grossSales, $salesDisc, $netSales, $cashSales, $creditSales, $bankSales,
                    $custCollect, $suppPay, $cashExp,
                    $expectedCash, $actualCash, $cashDiff, $notes,
                    $shiftId
                ]);
                $cId = $shiftId;
            } else {
                // Insert fresh finalized daily closing
                $ins = $pdo->prepare("
                    INSERT INTO daily_closings (
                        closing_no, shift_name, closing_date, opening_time, closing_time, user_id, opened_by,
                        opening_cash, opening_bank, gross_sales, sales_discount, net_sales, cash_sales,
                        credit_sales, bank_sales, customer_cash_collections, supplier_cash_payments,
                        cash_expenses, expected_cash, actual_cash_counted, cash_difference, status, notes
                    ) VALUES (?, 'General Day Shift', ?, CURTIME(), CURTIME(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'finalized', ?)
                ");
                $ins->execute([
                    $closingNo, $targetDate, $currentUser['id'], $currentUser['id'],
                    $openingCash, $openingBank, $grossSales, $salesDisc, $netSales, $cashSales,
                    $creditSales, $bankSales, $custCollect, $suppPay, $cashExp,
                    $expectedCash, $actualCash, $cashDiff, $notes
                ]);
                $cId = (int)$pdo->lastInsertId();
            }

            $pdo->commit();
            logAudit('update', 'daily_closings', $cId, "Finalized shift closing $closingNo for $targetDate. Difference: $cashDiff");
            setFlash('success', "Shift closing $closingNo has been FINALIZED and LOCKED.");
            header("Location: closing.php?view_id=$cId");
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = $e->getMessage();
        }
    }
}

// ---------------------------------------------------------
// COMPUTE LIVE METRICS (For Active Shift or New Closing)
// ---------------------------------------------------------
// 1. Sales Metrics for $targetDate
$stSales = $pdo->prepare("
    SELECT COALESCE(SUM(subtotal), 0) AS gross_sales,
           COALESCE(SUM(discount), 0) AS discount,
           COALESCE(SUM(grand_total), 0) AS net_sales,
           COALESCE(SUM(CASE WHEN payment_method = 'Cash' THEN paid_amount ELSE 0 END), 0) AS cash_paid_at_counter,
           COALESCE(SUM(CASE WHEN payment_method != 'Cash' THEN paid_amount ELSE 0 END), 0) AS bank_paid_at_counter,
           COALESCE(SUM(due_amount), 0) AS credit_sales
    FROM sales
    WHERE sale_date = ? AND status = 'posted'
");
$stSales->execute([$targetDate]);
$salesMetrics = $stSales->fetch();

$liveGrossSales   = (float)$salesMetrics['gross_sales'];
$liveDiscount     = (float)$salesMetrics['discount'];
$liveNetSales     = (float)$salesMetrics['net_sales'];
$liveCashSales    = (float)$salesMetrics['cash_paid_at_counter'];
$liveCreditSales  = (float)$salesMetrics['credit_sales'];
$liveBankSales    = (float)$salesMetrics['bank_paid_at_counter'];

// 2. Customer Cash Collections (RV) - standalone customer recovery vouchers only
$stRV = $pdo->prepare("
    SELECT COALESCE(SUM(v.amount), 0)
    FROM vouchers v
    JOIN financial_accounts fa ON v.account_id = fa.id
    WHERE v.voucher_date = ? 
      AND v.voucher_type = 'RV' 
      AND fa.account_type = 'Cash' 
      AND v.status = 'posted'
      AND (v.narration NOT LIKE 'Sale Bill #%' OR v.narration IS NULL)
");
$stRV->execute([$targetDate]);
$liveCustCollections = (float)$stRV->fetchColumn();

// 3. Supplier Cash Payments (PV)
$stPV = $pdo->prepare("
    SELECT COALESCE(SUM(v.amount), 0)
    FROM vouchers v
    JOIN financial_accounts fa ON v.account_id = fa.id
    WHERE v.voucher_date = ? AND v.voucher_type = 'PV' AND fa.account_type = 'Cash' AND v.status = 'posted'
");
$stPV->execute([$targetDate]);
$liveSuppPayments = (float)$stPV->fetchColumn();

// 4. Cash Expenses (EV)
$stEV = $pdo->prepare("
    SELECT COALESCE(SUM(e.amount), 0)
    FROM expenses e
    JOIN financial_accounts fa ON e.payment_account_id = fa.id
    WHERE e.expense_date = ? AND fa.account_type = 'Cash'
");
$stEV->execute([$targetDate]);
$liveCashExpenses = (float)$stEV->fetchColumn();

// 5. Opening Cash
if ($activeShift) {
    $liveOpeningCash = (float)$activeShift['opening_cash'];
    $liveOpeningBank = (float)$activeShift['opening_bank'];
} else {
    // Check if the most recent daily closing exists:
    $stPrior = $pdo->query("SELECT actual_cash_counted FROM daily_closings WHERE status = 'finalized' ORDER BY id DESC LIMIT 1");
    $priorCount = $stPrior->fetchColumn();

    if ($priorCount !== false && $priorCount !== null) {
        $liveOpeningCash = (float)$priorCount;
    } else {
        $stC = $pdo->query("SELECT current_balance FROM financial_accounts WHERE account_code = 'CASH-01'");
        $currentCash = (float)($stC->fetchColumn() ?: 0.0);
        $todayNetInflows = ($liveCashSales + $liveCustCollections) - ($liveSuppPayments + $liveCashExpenses);
        $liveOpeningCash = max(0.0, round($currentCash - $todayNetInflows, 2));
    }

    $stB = $pdo->query("SELECT COALESCE(SUM(current_balance), 0) FROM financial_accounts WHERE account_type = 'Bank'");
    $liveOpeningBank = (float)$stB->fetchColumn();
}

// 6. Expected Cash Calculation
$liveExpectedCash = round($liveOpeningCash + $liveCashSales + $liveCustCollections - $liveSuppPayments - $liveCashExpenses, 2);

// Recent shifts / closings history
$pastClosings = $pdo->query("
    SELECT dc.*, u.name AS user_name, op.name AS opener_name
    FROM daily_closings dc
    LEFT JOIN users u ON dc.user_id = u.id
    LEFT JOIN users op ON dc.opened_by = op.id
    ORDER BY dc.id DESC LIMIT 30
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">
      Shift &amp; Daily Cash Management (شفٹ اوپننگ و کیش کلوزنگ)
    </h4>
    <span class="text-muted" style="font-size: 13px;">
      Manage shift opening floats, track real-time drawer collections, count physical cash, and lock daily register
    </span>
  </div>
  <div class="d-flex gap-2 align-items-center">
    <?php if ($activeShift): ?>
      <span class="badge bg-success px-3 py-2" style="font-size: 12.5px;">
        <i class="fa-solid fa-circle-dot me-1 fa-beat"></i> Active Shift: <?= htmlspecialchars($activeShift['shift_name'] ?? 'Day Shift') ?>
      </span>
    <?php else: ?>
      <span class="badge bg-secondary px-3 py-2" style="font-size: 12.5px;">
        <i class="fa-solid fa-door-closed me-1"></i> Shift Closed / Not Started
      </span>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- ========================================================================= -->
<!-- VIEW 1: VIEWING A FINALIZED SHIFT / CLOSING REPORT                        -->
<!-- ========================================================================= -->
<?php if ($viewClosing): ?>
  <div class="alert alert-success d-flex align-items-center justify-content-between p-3 mb-4 flex-wrap gap-2">
    <div>
      <i class="fa-solid fa-lock me-2 fs-5"></i>
      <strong>Shift <?= htmlspecialchars($viewClosing['closing_no']) ?> (<?= htmlspecialchars($viewClosing['shift_name'] ?? 'Day Shift') ?>) for <?= htmlspecialchars($viewClosing['closing_date']) ?> is FINALIZED and LOCKED.</strong>
      <div style="font-size: 12.5px;">Closed at <?= htmlspecialchars($viewClosing['closing_time'] ?? '') ?> by <?= htmlspecialchars($viewClosing['user_name'] ?? 'Authorized User') ?></div>
    </div>
    <div class="d-flex gap-2">
      <a href="closing.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Current Shift View
      </a>
      <button onclick="window.print()" class="btn btn-outline-success btn-sm">
        <i class="fa-solid fa-print me-1"></i> Print Report
      </button>
    </div>
  </div>

  <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
      <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Reconciled Financial Summary · <?= htmlspecialchars($viewClosing['closing_no']) ?></h6>
      <span class="badge bg-light text-dark border"><?= htmlspecialchars($viewClosing['shift_name'] ?? 'Shift') ?></span>
    </div>
    <div class="card-body p-4">
      <div class="row g-4">
        <div class="col-12 col-md-6">
          <table class="table table-bordered mb-0" style="font-size: 13.5px;">
            <tr>
              <th style="background: #f8fafc; width: 55%;">Opening Cash Float (ابتدائی کیش):</th>
              <td class="text-end fw-bold"><?= formatCurrency((float)$viewClosing['opening_cash']) ?></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Total Gross Sales:</th>
              <td class="text-end"><?= formatCurrency((float)$viewClosing['gross_sales']) ?></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Cash Paid by Customers:</th>
              <td class="text-end text-success fw-bold">+ <?= formatCurrency((float)$viewClosing['cash_sales']) ?></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Credit Sales (Receivable):</th>
              <td class="text-end text-primary"><?= formatCurrency((float)$viewClosing['credit_sales']) ?></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Customer Cash Collections (RV):</th>
              <td class="text-end text-success fw-bold">+ <?= formatCurrency((float)$viewClosing['customer_cash_collections']) ?></td>
            </tr>
          </table>
        </div>

        <div class="col-12 col-md-6">
          <table class="table table-bordered mb-0" style="font-size: 13.5px;">
            <tr>
              <th style="background: #f8fafc; width: 55%;">Supplier Cash Payments (PV):</th>
              <td class="text-end text-danger fw-bold">- <?= formatCurrency((float)$viewClosing['supplier_cash_payments']) ?></td>
            </tr>
            <tr>
              <th style="background: #f8fafc;">Daily Cash Expenses (EV):</th>
              <td class="text-end text-danger fw-bold">- <?= formatCurrency((float)$viewClosing['cash_expenses']) ?></td>
            </tr>
            <tr style="background: #f1f5f9;">
              <th>Expected Cash in Drawer:</th>
              <td class="text-end fw-bold fs-6"><?= formatCurrency((float)$viewClosing['expected_cash']) ?></td>
            </tr>
            <tr style="background: #eff6ff;">
              <th>Actual Cash Counted:</th>
              <td class="text-end fw-bold fs-6 text-primary"><?= formatCurrency((float)$viewClosing['actual_cash_counted']) ?></td>
            </tr>
            <tr>
              <th>Cash Difference (فرق):</th>
              <td class="text-end fw-bold fs-6 <?= (float)$viewClosing['cash_difference'] < 0 ? 'text-danger' : 'text-success' ?>">
                <?= formatCurrency((float)$viewClosing['cash_difference']) ?>
                <?= (float)$viewClosing['cash_difference'] == 0 ? '<span class="badge bg-success ms-1">Balanced</span>' : ((float)$viewClosing['cash_difference'] < 0 ? '<span class="badge bg-danger ms-1">Shortage</span>' : '<span class="badge bg-success ms-1">Excess</span>') ?>
              </td>
            </tr>
          </table>
        </div>

        <?php if (!empty($viewClosing['notes'])): ?>
          <div class="col-12">
            <div class="p-3 bg-light rounded border">
              <strong>Notes &amp; Closing Remarks:</strong> <?= htmlspecialchars($viewClosing['notes']) ?>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

<!-- ========================================================================= -->
<!-- VIEW 2: ACTIVE SHIFT IN PROGRESS -> RECONCILIATION & CLOSING               -->
<!-- ========================================================================= -->
<?php elseif ($activeShift): ?>
  <div class="card mb-4" style="border: 1px solid #10b981; border-radius: 10px; overflow: hidden;">
    <div class="card-header text-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2" style="background: linear-gradient(135deg, #059669, #10b981);">
      <div>
        <h6 class="mb-0 fw-bold fs-6">
          <i class="fa-solid fa-clock-rotate-left me-2"></i> Active Shift: <?= htmlspecialchars($activeShift['shift_name'] ?? 'General Shift') ?> · #<?= htmlspecialchars($activeShift['closing_no']) ?>
        </h6>
        <small style="opacity: 0.9;">Opened at <?= htmlspecialchars($activeShift['opening_time'] ?? '') ?> on <?= htmlspecialchars($activeShift['closing_date']) ?> by <?= htmlspecialchars($activeShift['opener_name'] ?? 'Cashier') ?></small>
      </div>
      <div>
        <span class="badge bg-white text-dark px-3 py-2 fw-bold">
          Initial Drawer Float: <?= formatCurrency((float)$activeShift['opening_cash']) ?>
        </span>
      </div>
    </div>

    <div class="card-body p-4">
      <form method="POST" action="closing.php" id="closingForm">
        <?= getCSRFInput() ?>
        <input type="hidden" name="action" value="finalize_closing">
        <input type="hidden" name="shift_id" value="<?= $activeShift['id'] ?>">
        <input type="hidden" name="opening_cash" value="<?= $liveOpeningCash ?>">
        <input type="hidden" name="opening_bank" value="<?= $liveOpeningBank ?>">
        <input type="hidden" name="gross_sales" value="<?= $liveGrossSales ?>">
        <input type="hidden" name="sales_discount" value="<?= $liveDiscount ?>">
        <input type="hidden" name="net_sales" value="<?= $liveNetSales ?>">
        <input type="hidden" name="cash_sales" value="<?= $liveCashSales ?>">
        <input type="hidden" name="credit_sales" value="<?= $liveCreditSales ?>">
        <input type="hidden" name="bank_sales" value="<?= $liveBankSales ?>">
        <input type="hidden" name="customer_cash_collections" value="<?= $liveCustCollections ?>">
        <input type="hidden" name="supplier_cash_payments" value="<?= $liveSuppPayments ?>">
        <input type="hidden" name="cash_expenses" value="<?= $liveCashExpenses ?>">
        <input type="hidden" name="expected_cash" id="inpExpectedCash" value="<?= $liveExpectedCash ?>">

        <!-- Live Shift Metric Cards -->
        <div class="row g-3 mb-4">
          <div class="col-12 col-md-3">
            <div class="p-3 border rounded bg-light">
              <span class="text-muted" style="font-size: 12px; display: block;">1. Shift Opening Cash Float:</span>
              <div style="font-size: 18px; font-weight: 700; color: #334155;"><?= formatCurrency($liveOpeningCash) ?></div>
              <small class="text-muted" style="font-size: 11px;">Fixed at shift start</small>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="p-3 border rounded bg-light">
              <span class="text-muted" style="font-size: 12px; display: block;">2. Cash Inflows (آمدن):</span>
              <div style="font-size: 18px; font-weight: 700; color: var(--gn);">+ <?= formatCurrency($liveCashSales + $liveCustCollections) ?></div>
              <small class="text-muted" style="font-size: 11px;">Counter: <?= formatCurrency($liveCashSales) ?> | RV: <?= formatCurrency($liveCustCollections) ?></small>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="p-3 border rounded bg-light">
              <span class="text-muted" style="font-size: 12px; display: block;">3. Cash Outflows (ادائیگیاں):</span>
              <div style="font-size: 18px; font-weight: 700; color: var(--rd);">- <?= formatCurrency($liveSuppPayments + $liveCashExpenses) ?></div>
              <small class="text-muted" style="font-size: 11px;">Expenses: <?= formatCurrency($liveCashExpenses) ?> | Suppliers: <?= formatCurrency($liveSuppPayments) ?></small>
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="p-3 border rounded" style="background: #eff6ff; border-color: #bfdbfe !important;">
              <span style="font-size: 12px; font-weight: 600; color: var(--nv); display: block;">4. Expected Safe Cash:</span>
              <div style="font-size: 20px; font-weight: 800; color: var(--nv);"><?= formatCurrency($liveExpectedCash) ?></div>
              <small class="text-muted" style="font-size: 11px;">Opening + Inflows − Outflows</small>
            </div>
          </div>
        </div>

        <!-- Physical Cash Denomination Counter -->
        <h6 class="mb-3" style="font-weight: 700; color: var(--nv);">
          <i class="fa-solid fa-money-bill-1-wave me-1 text-success"></i> Physical Cash Denomination Count (دراز میں نوٹوں کی فزیکل گنتی)
        </h6>
        <div class="row g-2 mb-4 p-3 border rounded bg-white">
          <?php 
            $notes = [5000, 1000, 500, 100, 50, 20, 10];
            foreach ($notes as $denom):
          ?>
            <div class="col-6 col-sm-4 col-md-3 col-lg">
              <label class="form-label" style="font-size: 11.5px;">Rs <?= $denom ?> Notes</label>
              <input type="number" min="0" class="form-control text-center denom-inp" data-val="<?= $denom ?>" placeholder="0" oninput="recalcCountedCash()">
            </div>
          <?php endforeach; ?>
          <div class="col-6 col-sm-4 col-md-3 col-lg">
            <label class="form-label" style="font-size: 11.5px;">Coins / Loose</label>
            <input type="number" min="0" step="1" class="form-control text-center denom-inp" data-val="1" placeholder="0" oninput="recalcCountedCash()">
          </div>
        </div>

        <!-- Reconciliation Results & Lock Button -->
        <div class="row g-3 align-items-center mb-4">
          <div class="col-12 col-md-4">
            <label class="form-label fw-bold">Total Actual Cash Counted (کل گنی گئی رقم) <span class="text-danger">*</span></label>
            <input type="number" step="0.01" name="actual_cash_counted" id="inpActualCash" class="form-control fs-5 fw-bold" required placeholder="0.00" oninput="manualCountChange(this.value)">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label fw-bold">Difference (فرق)</label>
            <div id="diffBadge" class="p-2 border rounded fw-bold text-center fs-5" style="background:#f8fafc; color:#64748b; min-height: 44px; display: flex; align-items: center; justify-content: center;">
              Rs 0.00
            </div>
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label fw-bold">Closing Notes &amp; Shift Remarks</label>
            <input type="text" name="notes" class="form-control" placeholder="Any cash discrepancy reasons...">
          </div>
        </div>

        <div class="text-end">
          <button type="submit" class="btn btn-danger btn-lg px-4" onclick="return confirm('Confirm closing and locking this shift?');">
            <i class="fa-solid fa-lock me-2"></i> Finalize &amp; Close Shift (شفٹ مکمل و مقفل کریں)
          </button>
        </div>
      </form>
    </div>
  </div>

<!-- ========================================================================= -->
<!-- VIEW 3: NO ACTIVE SHIFT RUNNING -> START NEW SHIFT FORM                   -->
<!-- ========================================================================= -->
<?php else: ?>
  <div class="card mb-4" style="border: 2px dashed #0f172a; border-radius: 12px; background: #f8fafc;">
    <div class="card-body p-4 text-center">
      <div class="mb-3">
        <i class="fa-solid fa-cash-register text-primary" style="font-size: 48px;"></i>
      </div>
      <h5 class="fw-bold" style="color: var(--nv);">Start / Open a New Shift (نئی شفٹ شروع کریں)</h5>
      <p class="text-muted" style="max-width: 600px; margin: 0 auto 20px; font-size: 13.5px;">
        To begin recording sales and register collections, open a shift and record your starting drawer cash float.
      </p>

      <div class="row justify-content-center">
        <div class="col-12 col-md-8 col-lg-6 text-start bg-white p-4 rounded border shadow-sm">
          <form method="POST" action="closing.php">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="open_shift">

            <div class="mb-3">
              <label class="form-label fw-bold">Shift Title / Name (شفٹ نام) <span class="text-danger">*</span></label>
              <select name="shift_name" class="form-select" required>
                <option value="General Day Shift">General Day Shift (روزانہ کل شفٹ)</option>
                <option value="Morning Shift (صبح شفٹ)">Morning Shift (صبح شفٹ)</option>
                <option value="Evening Shift (شام شفٹ)">Evening Shift (شام شفٹ)</option>
                <option value="Night Shift (رات شفٹ)">Night Shift (رات شفٹ)</option>
              </select>
            </div>

            <div class="mb-3">
              <label class="form-label fw-bold">Opening Drawer Cash Float (دراز کا ابتدائی کیش) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" min="0" name="opening_cash" class="form-control fs-5 fw-bold" required value="<?= $liveOpeningCash ?>" placeholder="0.00">
              </div>
              <small class="text-muted mt-1 d-block">
                Suggested from previous shift closing count: <strong><?= formatCurrency($liveOpeningCash) ?></strong>
              </small>
            </div>

            <div class="mb-3">
              <label class="form-label">Shift Notes / Remarks (اگر کوئی ہوں)</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. Starting morning register with 5k change">
            </div>

            <button type="submit" class="btn btn-zb-primary btn-lg w-100 py-2">
              <i class="fa-solid fa-play me-2"></i> Start &amp; Open Shift (شفٹ اوپن کریں)
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Past Closings & Shifts Ledger -->
<div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
  <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
      <i class="fa-solid fa-history me-1 text-primary"></i> Past Shifts &amp; Daily Closings Ledger (سابقہ کلوزنگ ریکارڈ)
    </h6>
    <span class="badge bg-light text-dark border"><?= count($pastClosings) ?> shifts recorded</span>
  </div>
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
      <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
        <tr>
          <th>Shift #</th>
          <th>Date</th>
          <th>Shift Name</th>
          <th>Cashier</th>
          <th class="text-end">Opening Cash</th>
          <th class="text-end">Cash Inflows</th>
          <th class="text-end">Expected Safe</th>
          <th class="text-end">Actual Counted</th>
          <th class="text-end">Diff (فرق)</th>
          <th>Status</th>
          <th class="text-center">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($pastClosings)): ?>
          <tr><td colspan="11" class="text-center py-4 text-muted">No shifts or closings recorded yet. Open your first shift above.</td></tr>
        <?php else: ?>
          <?php foreach ($pastClosings as $c): ?>
            <tr>
              <td class="font-num fw-bold text-primary">
                <?= htmlspecialchars($c['closing_no']) ?>
              </td>
              <td><?= htmlspecialchars($c['closing_date']) ?></td>
              <td>
                <span class="badge bg-light text-dark border"><?= htmlspecialchars($c['shift_name'] ?? 'Day Shift') ?></span>
              </td>
              <td><?= htmlspecialchars($c['user_name'] ?? ($c['opener_name'] ?? 'User')) ?></td>
              <td class="text-end font-num"><?= formatCurrency((float)$c['opening_cash']) ?></td>
              <td class="text-end font-num text-success">+ <?= formatCurrency((float)$c['cash_sales'] + (float)$c['customer_cash_collections']) ?></td>
              <td class="text-end font-num"><?= formatCurrency((float)$c['expected_cash']) ?></td>
              <td class="text-end font-num fw-bold"><?= $c['status'] === 'open' ? '<span class="text-muted">In Progress</span>' : formatCurrency((float)$c['actual_cash_counted']) ?></td>
              <td class="text-end font-num fw-bold">
                <?php if ($c['status'] === 'open'): ?>
                  <span class="badge bg-warning text-dark">Live</span>
                <?php else: ?>
                  <span class="<?= (float)$c['cash_difference'] < 0 ? 'text-danger' : 'text-success' ?>">
                    <?= formatCurrency((float)$c['cash_difference']) ?>
                  </span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($c['status'] === 'open'): ?>
                  <span class="badge bg-success">Open (جاری)</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Finalized</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ($c['status'] === 'open'): ?>
                  <a href="closing.php" class="btn btn-sm btn-outline-success">
                    <i class="fa-solid fa-cash-register me-1"></i> Manage
                  </a>
                <?php else: ?>
                  <a href="closing.php?view_id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa-solid fa-eye me-1"></i> View / Print
                  </a>
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
function recalcCountedCash() {
  let total = 0;
  document.querySelectorAll('.denom-inp').forEach(function(inp) {
    let count = parseFloat(inp.value) || 0;
    let denom = parseFloat(inp.getAttribute('data-val')) || 0;
    total += count * denom;
  });
  const actInp = document.getElementById('inpActualCash');
  if (actInp) {
    actInp.value = total.toFixed(2);
    updateDifference(total);
  }
}

function manualCountChange(val) {
  const num = parseFloat(val) || 0;
  updateDifference(num);
}

function updateDifference(actual) {
  const expInp = document.getElementById('inpExpectedCash');
  if (!expInp) return;
  const expected = parseFloat(expInp.value) || 0;
  const diff = actual - expected;
  const badge = document.getElementById('diffBadge');
  if (!badge) return;

  const formatted = Math.abs(diff).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  if (Math.abs(diff) < 0.01) {
    badge.className = 'p-2 border rounded fw-bold text-center fs-5 text-success bg-success-subtle';
    badge.innerHTML = 'Rs 0.00 <span class="badge bg-success ms-1 fs-6">Balanced (برابر)</span>';
  } else if (diff < 0) {
    badge.className = 'p-2 border rounded fw-bold text-center fs-5 text-danger bg-danger-subtle';
    badge.innerHTML = '- Rs ' + formatted + ' <span class="badge bg-danger ms-1 fs-6">Shortage (کمی)</span>';
  } else {
    badge.className = 'p-2 border rounded fw-bold text-center fs-5 text-primary bg-primary-subtle';
    badge.innerHTML = '+ Rs ' + formatted + ' <span class="badge bg-primary ms-1 fs-6">Excess (اضافہ)</span>';
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
