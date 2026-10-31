<?php
// account_ledger.php - Financial Account Bank/Cash Statement
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
requirePerm('accounts');

$pdo = getPDO();
$pageTitle = 'Account Ledger Statement (بینک و کیش اسٹیٹمنٹ)';

$accId = (int)($_GET['id'] ?? 0);
if ($accId <= 0) {
    header('Location: accounts.php');
    exit;
}

// Fetch Account
$stA = $pdo->prepare("SELECT fa.*, coa.code AS coa_code, coa.name AS coa_name FROM financial_accounts fa JOIN chart_of_accounts coa ON fa.coa_id = coa.id WHERE fa.id = ?");
$stA->execute([$accId]);
$account = $stA->fetch();
if (!$account) {
    setFlash('danger', 'Financial account not found.');
    header('Location: accounts.php');
    exit;
}

// Date Range Filter
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));

// Gather all vouchers & transfers touching this account
// 1. Receipts (RV) -> Inflow (Debit to Cash/Bank)
$stRV = $pdo->prepare("
    SELECT v.id, v.created_at, v.voucher_date AS trans_date, v.voucher_no AS doc_no, 'Receipt (وصولی)' AS trans_type,
           CONCAT('Received from ', p.name, ' (', v.payment_mode, IF(v.cheque_no != '', CONCAT(' Ref: ', v.cheque_no), ''), ') ', COALESCE(v.narration, '')) AS description,
           v.amount AS inflow, 0.0 AS outflow
    FROM vouchers v
    LEFT JOIN parties p ON v.party_id = p.id
    WHERE v.account_id = ? AND v.voucher_type = 'RV' AND v.status = 'posted' AND v.voucher_date BETWEEN ? AND ?
");
$stRV->execute([$accId, $startDate, $endDate]);
$rEntries = $stRV->fetchAll();

// 2. Payments (PV) -> Outflow (Credit to Cash/Bank)
$stPV = $pdo->prepare("
    SELECT v.id, v.created_at, v.voucher_date AS trans_date, v.voucher_no AS doc_no, 'Payment (ادائیگی)' AS trans_type,
           CONCAT('Paid to supplier ', p.name, ' (', v.payment_mode, IF(v.cheque_no != '', CONCAT(' Ref: ', v.cheque_no), ''), ') ', COALESCE(v.narration, '')) AS description,
           0.0 AS inflow, v.amount AS outflow
    FROM vouchers v
    LEFT JOIN parties p ON v.party_id = p.id
    WHERE v.account_id = ? AND v.voucher_type = 'PV' AND v.status = 'posted' AND v.voucher_date BETWEEN ? AND ?
");
$stPV->execute([$accId, $startDate, $endDate]);
$pEntries = $stPV->fetchAll();

// 3. Expenses (EV) -> Outflow
$stEV = $pdo->prepare("
    SELECT e.id, e.created_at, e.expense_date AS trans_date, e.expense_no AS doc_no, 'Expense (خرچہ)' AS trans_type,
           CONCAT(c.name, ' - Payee: ', COALESCE(e.payee, '-'), ' (', COALESCE(e.notes, ''), ')') AS description,
           0.0 AS inflow, e.amount AS outflow
    FROM expenses e
    JOIN expense_categories c ON e.category_id = c.id
    WHERE e.payment_account_id = ? AND e.expense_date BETWEEN ? AND ?
");
$stEV->execute([$accId, $startDate, $endDate]);
$eEntries = $stEV->fetchAll();

// 4. Transfers In -> Inflow
$stTIn = $pdo->prepare("
    SELECT t.id, t.created_at, t.transfer_date AS trans_date, t.transfer_no AS doc_no, 'Transfer IN (آمد)' AS trans_type,
           CONCAT('Transfer received from ', fa.account_name, IF(t.reference_no != '', CONCAT(' Ref: ', t.reference_no), ''), ' - ', COALESCE(t.narration, '')) AS description,
           t.amount AS inflow, 0.0 AS outflow
    FROM inter_account_transfers t
    JOIN financial_accounts fa ON t.from_account_id = fa.id
    WHERE t.to_account_id = ? AND t.status = 'posted' AND t.transfer_date BETWEEN ? AND ?
");
$stTIn->execute([$accId, $startDate, $endDate]);
$tInEntries = $stTIn->fetchAll();

// 5. Transfers Out -> Outflow
$stTOut = $pdo->prepare("
    SELECT t.id, t.created_at, t.transfer_date AS trans_date, t.transfer_no AS doc_no, 'Transfer OUT (روانگی)' AS trans_type,
           CONCAT('Transfer sent to ', fa.account_name, IF(t.reference_no != '', CONCAT(' Ref: ', t.reference_no), ''), ' - ', COALESCE(t.narration, ''), IF(t.fee_amount > 0, CONCAT(' [Fee: ', t.fee_amount, ']'), '')) AS description,
           0.0 AS inflow, (t.amount + t.fee_amount) AS outflow
    FROM inter_account_transfers t
    JOIN financial_accounts fa ON t.to_account_id = fa.id
    WHERE t.from_account_id = ? AND t.status = 'posted' AND t.transfer_date BETWEEN ? AND ?
");
$stTOut->execute([$accId, $startDate, $endDate]);
$tOutEntries = $stTOut->fetchAll();

// Merge and sort: Date ASC -> Inflows (1) -> Outflows (2) -> Zero-movement (3) -> Time/Doc ASC
$allEntries = array_merge($rEntries, $pEntries, $eEntries, $tInEntries, $tOutEntries);
usort($allEntries, function($a, $b) {
    // 1. Transaction Date
    $dateCmp = strcmp($a['trans_date'], $b['trans_date']);
    if ($dateCmp !== 0) {
        return $dateCmp;
    }

    // 2. Priority: Inflows (1) -> Outflows (2) -> Zero-movement (3)
    $priA = ((float)$a['inflow'] > 0) ? 1 : (((float)$a['outflow'] > 0) ? 2 : 3);
    $priB = ((float)$b['inflow'] > 0) ? 1 : (((float)$b['outflow'] > 0) ? 2 : 3);
    if ($priA !== $priB) {
        return $priA <=> $priB;
    }

    // 3. Creation timestamp if available
    $timeA = $a['created_at'] ?? '';
    $timeB = $b['created_at'] ?? '';
    if ($timeA !== '' && $timeB !== '' && $timeA !== $timeB) {
        return strcmp($timeA, $timeB);
    }

    // 4. Fallback to doc_no
    return strcmp($a['doc_no'], $b['doc_no']);
});

// Running Balances
$runningBal = (float)$account['opening_balance'];
$totalInflow = 0.0;
$totalOutflow = 0.0;
foreach ($allEntries as &$ent) {
    $in  = (float)$ent['inflow'];
    $out = (float)$ent['outflow'];
    $totalInflow += $in;
    $totalOutflow += $out;
    $runningBal += ($in - $out);
    $ent['balance'] = $runningBal;
}
unset($ent);

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <div class="d-flex align-items-center gap-2">
          <a href="accounts.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i></a>
          <h4 class="mb-0" style="font-weight: 700; color: var(--nv);"><?= htmlspecialchars($account['account_name']) ?> Statement</h4>
        </div>
        <span class="text-muted ms-4" style="font-size: 13px;">Code: <?= htmlspecialchars($account['account_code']) ?> · <?= htmlspecialchars($account['account_type']) ?> · <?= htmlspecialchars($account['bank_name'] ?? 'In-Shop') ?> · A/C: <?= htmlspecialchars($account['account_number'] ?? '-') ?></span>
      </div>
      <div class="d-flex gap-2">
        <a href="transfers.php?from_id=<?= $accId ?>" class="btn btn-outline-primary">
          <i class="fa-solid fa-money-bill-transfer me-1"></i> Transfer Funds
        </a>
        <a href="print_ledger.php?type=account&id=<?= $accId ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" target="_blank" class="btn btn-outline-dark">
          <i class="fa-solid fa-print me-1"></i> Print Statement (پرنٹ اسٹیٹمنٹ)
        </a>
      </div>
    </div>

    <!-- Balance Banner -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; background: linear-gradient(135deg, #14307a 0%, #1e293b 100%); color: #fff;">
      <div class="card-body p-4">
        <div class="row g-3 align-items-center">
          <div class="col-12 col-md-6">
            <span style="font-size: 13px; opacity: 0.85; display: block;">Current Live Balance (موجودہ بیلنس):</span>
            <div style="font-size: 28px; font-weight: 800; color: #fbbf24;">
              <?= formatCurrency((float)$account['current_balance']) ?>
            </div>
          </div>
          <div class="col-12 col-md-6 text-md-end">
            <span style="font-size: 13px; opacity: 0.85; display: block;">Initial Account Balance:</span>
            <div style="font-size: 18px; font-weight: 600; color: #fff;">
              <?= formatCurrency((float)$account['opening_balance']) ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Date Filter Bar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="account_ledger.php" class="row g-3 align-items-end">
          <input type="hidden" name="id" value="<?= $accId ?>">
          <div class="col-12 col-md-4">
            <label class="form-label">From Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">To Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
          </div>
          <div class="col-12 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <a href="account_ledger.php?id=<?= $accId ?>" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
          </div>
        </form>
      </div>
    </div>

    <!-- Statement Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Cash / Bank Statement: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></h6>
        <span class="badge bg-light text-dark border"><?= count($allEntries) ?> transactions</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Date</th>
              <th style="padding: 12px 14px;">Document #</th>
              <th style="padding: 12px 14px;">Type</th>
              <th style="padding: 12px 14px;">Description / Particulars</th>
              <th class="text-end" style="padding: 12px 14px;">Inflow / Received (آمد)</th>
              <th class="text-end" style="padding: 12px 14px;">Outflow / Paid (روانگی)</th>
              <th class="text-end" style="padding: 12px 14px;">Running Balance</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allEntries)): ?>
              <tr>
                <td colspan="7" class="text-center py-4 text-muted">No transactions found for this account in the selected period.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($allEntries as $entry): ?>
                <tr>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($entry['trans_date']) ?></td>
                  <td style="padding: 12px 14px; font-weight: 600; color: var(--nv);">
                    <?= htmlspecialchars($entry['doc_no']) ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($entry['trans_type']) ?></span>
                  </td>
                  <td style="padding: 12px 14px;">
                    <?= htmlspecialchars($entry['description']) ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600; color: var(--gn);">
                    <?= (float)$entry['inflow'] > 0 ? formatCurrency((float)$entry['inflow']) : '-' ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600; color: var(--rd);">
                    <?= (float)$entry['outflow'] > 0 ? formatCurrency((float)$entry['outflow']) : '-' ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: <?= $entry['balance'] >= 0 ? 'var(--nv)' : 'var(--rd)' ?>;">
                    <?= formatCurrency($entry['balance']) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot style="background: #f8fafc; border-top: 2px solid var(--bd); font-weight: 700;">
            <tr>
              <td colspan="4" class="text-end" style="padding: 12px 14px;">Period Totals:</td>
              <td class="text-end" style="padding: 12px 14px; color: var(--gn);"><?= formatCurrency($totalInflow) ?></td>
              <td class="text-end" style="padding: 12px 14px; color: var(--rd);"><?= formatCurrency($totalOutflow) ?></td>
              <td class="text-end" style="padding: 12px 14px; font-size: 15px;"><?= formatCurrency($runningBal) ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
