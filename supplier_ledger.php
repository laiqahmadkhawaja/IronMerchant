<?php
// supplier_ledger.php - Comprehensive Supplier Statement & Payment Voucher Entry
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
requirePerm('suppliers');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Supplier Ledger Statement (سپلائر لیجر کھاتہ)';
$errors = [];

$suppId = (int)($_GET['id'] ?? 0);
if ($suppId <= 0) {
    header('Location: suppliers.php');
    exit;
}

// Fetch Supplier
$stS = $pdo->prepare("SELECT * FROM parties WHERE id = ? AND type = 'supplier'");
$stS->execute([$suppId]);
$supplier = $stS->fetch();
if (!$supplier) {
    setFlash('danger', 'Supplier record not found.');
    header('Location: suppliers.php');
    exit;
}

// Handle Disburse Payment (Payment Voucher PV)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'disburse_payment') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $vDate       = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        $accountId   = (int)($_POST['financial_account_id'] ?? 0);
        $amount      = (float)($_POST['amount'] ?? 0);
        $paymentMode = trim($_POST['payment_mode'] ?? 'Cheque');
        $chequeNo    = trim($_POST['cheque_no'] ?? '');
        $narration   = trim($_POST['narration'] ?? '');

        if ($amount <= 0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }
        if ($accountId <= 0) {
            $errors[] = 'Please select a paying Cash or Bank account.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Lock account & supplier
                $stAcc = $pdo->prepare("SELECT id, account_name, coa_id, current_balance FROM financial_accounts WHERE id = ? FOR UPDATE");
                $stAcc->execute([$accountId]);
                $acc = $stAcc->fetch();
                if (!$acc) {
                    throw new Exception("Paying financial account not found.");
                }

                $stSuppLock = $pdo->prepare("SELECT id, current_balance, name FROM parties WHERE id = ? FOR UPDATE");
                $stSuppLock->execute([$suppId]);
                $sLock = $stSuppLock->fetch();

                $voucherNo = generateDocumentNumber('PV', 'vouchers', 'voucher_no');

                // Double Entry:
                // Debit: Accounts Payable (COA_PAYABLE id 13)
                // Credit: Cash/Bank Account (acc.coa_id)
                $jLines = [
                    [
                        'account_id'  => COA_PAYABLE,
                        'party_id'    => $suppId,
                        'debit'       => $amount,
                        'credit'      => 0.0,
                        'description' => "Payment made to {$sLock['name']} ($voucherNo)"
                    ],
                    [
                        'account_id'  => (int)$acc['coa_id'],
                        'party_id'    => null,
                        'debit'       => 0.0,
                        'credit'      => $amount,
                        'description' => "Disbursed via {$acc['account_name']} to {$sLock['name']} ($voucherNo)"
                    ]
                ];

                $journalId = postJournalEntry($pdo, [
                    'entry_no'    => getNextDocNo('JV', 'journal_entries', 'entry_no'),
                    'entry_date'  => $vDate,
                    'source_type' => 'voucher',
                    'narration'   => "Payment disbursed to supplier {$sLock['name']}: $narration",
                    'created_by'  => $currentUser['id'] ?? null
                ], $jLines);

                // Insert into vouchers
                $insV = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, party_id, account_id, amount, payment_mode, cheque_no, narration, journal_id, created_by) VALUES (?, 'PV', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insV->execute([$voucherNo, $vDate, $suppId, $accountId, $amount, $paymentMode, $chequeNo, $narration, $journalId, $currentUser['id'] ?? null]);
                $vId = (int)$pdo->lastInsertId();

                // Settle open purchase bills for this supplier (FIFO)
                $remPay = (float)$amount;
                $stOpenPur = $pdo->prepare("SELECT id, grand_total, paid_amount, due_amount FROM purchases WHERE supplier_id = ? AND status = 'posted' AND due_amount > 0 ORDER BY purchase_date ASC, id ASC FOR UPDATE");
                $stOpenPur->execute([$suppId]);
                $openPurchases = $stOpenPur->fetchAll();

                $stUpdPur = $pdo->prepare("UPDATE purchases SET paid_amount = ?, due_amount = ?, payment_status = ? WHERE id = ?");
                foreach ($openPurchases as $pur) {
                    if ($remPay <= 0) break;
                    $due = (float)$pur['due_amount'];
                    $alloc = min($remPay, $due);
                    $newPaid = (float)$pur['paid_amount'] + $alloc;
                    $newDue = max(0.0, $due - $alloc);
                    $newStatus = ($newDue <= 0.009) ? 'Paid' : 'Partial';
                    $stUpdPur->execute([$newPaid, $newDue, $newStatus, $pur['id']]);
                    $remPay -= $alloc;
                }

                $pdo->commit();
                logAudit('create', 'vouchers', $vId, "Recorded payment disbursement $voucherNo of Rs $amount to supplier {$sLock['name']}");
                setFlash('success', "Payment voucher $voucherNo for Rs " . number_format($amount, 2) . " posted successfully.");
                header("Location: supplier_ledger.php?id=$suppId");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Date Range Filter
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));

// 1. Calculate Opening Balance before $startDate
$initOpening = (float)$supplier['opening_balance'];

// Purchases before startDate
$stPurPre = $pdo->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM purchases WHERE supplier_id = ? AND purchase_date < ?");
$stPurPre->execute([$suppId, $startDate]);
$prePurchases = (float)$stPurPre->fetchColumn();

// Vouchers before startDate
$stVPre = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM vouchers WHERE party_id = ? AND voucher_date < ? AND voucher_type = 'PV' AND status = 'posted'");
$stVPre->execute([$suppId, $startDate]);
$preVouchers = (float)$stVPre->fetchColumn();

$periodOpeningBalance = $initOpening + $prePurchases - $preVouchers;

// 2. Fetch Transactions in period
// Purchases (Credit to supplier)
$stPur = $pdo->prepare("SELECT id, purchase_no AS doc_no, purchase_date AS trans_date, 'Purchase Bill' AS trans_type, grand_total AS credit, paid_amount AS immediate_debit, (grand_total - paid_amount) AS net_credit, supplier_inv_no, journal_id, created_at FROM purchases WHERE supplier_id = ? AND purchase_date BETWEEN ? AND ?");
$stPur->execute([$suppId, $startDate, $endDate]);
$periodPurchases = $stPur->fetchAll();

// Vouchers (PV) (Debit to supplier)
$stV = $pdo->prepare("SELECT v.id, v.voucher_no AS doc_no, v.voucher_date AS trans_date, 'Payment Voucher' AS trans_type, v.amount AS debit, 0.0 AS credit, v.payment_mode, v.cheque_no, v.narration, a.account_name, v.journal_id, v.created_at FROM vouchers v LEFT JOIN financial_accounts a ON v.account_id = a.id WHERE v.party_id = ? AND v.voucher_date BETWEEN ? AND ? AND v.voucher_type = 'PV' AND v.status = 'posted'");
$stV->execute([$suppId, $startDate, $endDate]);
$periodVouchers = $stV->fetchAll();

// Merge & Sort Chronologically
$ledgerEntries = [];
foreach ($periodPurchases as $pp) {
    $ledgerEntries[] = [
        'date'        => $pp['trans_date'],
        'doc_no'      => $pp['doc_no'],
        'type'        => 'Steel Purchase Bill',
        'desc'        => 'Purchase Invoice' . (!empty($pp['supplier_inv_no']) ? ' (Mill Inv: ' . $pp['supplier_inv_no'] . ')' : '') . ((float)$pp['immediate_debit'] > 0 ? ' [Paid at loading: ' . formatCurrency((float)$pp['immediate_debit']) . ']' : ''),
        'debit'       => 0.0,
        'credit'      => (float)$pp['credit'],
        'link'        => 'purchases.php?search=' . $pp['doc_no']
    ];
}
foreach ($periodVouchers as $pv) {
    $ledgerEntries[] = [
        'date'        => $pv['trans_date'],
        'doc_no'      => $pv['doc_no'],
        'type'        => 'Payment Voucher (PV)',
        'desc'        => 'Paid via ' . ($pv['account_name'] ?? 'Bank') . ' (' . $pv['payment_mode'] . ($pv['cheque_no'] ? ' Cheque: ' . $pv['cheque_no'] : '') . ') ' . ($pv['narration'] ?? ''),
        'debit'       => (float)$pv['debit'],
        'credit'      => 0.0,
        'link'        => 'vouchers.php?search=' . $pv['doc_no']
    ];
}

usort($ledgerEntries, fn($a, $b) => strcmp($a['date'], $b['date']) ?: strcmp($a['doc_no'], $b['doc_no']));

// Compute running balances
$runningBal = $periodOpeningBalance;
$totalPeriodDebit = 0.0;
$totalPeriodCredit = 0.0;
foreach ($ledgerEntries as &$entry) {
    $totalPeriodDebit += $entry['debit'];
    $totalPeriodCredit += $entry['credit'];
    $runningBal += ($entry['credit'] - $entry['debit']);
    $entry['balance'] = $runningBal;
}
unset($entry);

// Fetch cash/bank accounts for the payment form
$finAccounts = $pdo->query("SELECT id, account_name, account_type, current_balance FROM financial_accounts WHERE is_active = 1 ORDER BY account_type, account_name")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

    <!-- Top Action Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <div class="d-flex align-items-center gap-2">
          <a href="suppliers.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i></a>
          <h4 class="mb-0" style="font-weight: 700; color: var(--nv);">Supplier Statement: <?= htmlspecialchars($supplier['name']) ?></h4>
        </div>
        <span class="text-muted ms-4" style="font-size: 13px;">Firm: <?= htmlspecialchars($supplier['company_name'] ?? 'Mill') ?> · Phone: <?= htmlspecialchars($supplier['phone'] ?? '-') ?> · Location: <?= htmlspecialchars($supplier['address'] ?? '-') ?></span>
      </div>
      <div>
        <a href="print_ledger.php?type=supplier&id=<?= $suppId ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" target="_blank" class="btn btn-outline-dark">
          <i class="fa-solid fa-print me-1"></i> Print Supplier Ledger (پرنٹ کھاتہ)
        </a>
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

    <!-- Summary Balance Bar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; background: linear-gradient(135deg, #1b2350 0%, #0f172a 100%); color: #fff;">
      <div class="card-body p-4">
        <div class="row g-3 align-items-center">
          <div class="col-12 col-md-6">
            <span style="font-size: 13px; opacity: 0.85; display: block;">Total Outstanding Payable to Supplier (کل واجب الادا رقم):</span>
            <div style="font-size: 28px; font-weight: 800; color: #fbbf24;">
              <?= formatCurrency((float)$supplier['current_balance']) ?>
            </div>
          </div>
          <div class="col-12 col-md-6 text-md-end">
            <a href="#disbursePaymentCard" class="btn btn-zb-gold px-4 py-2" onclick="document.getElementById('disbursePaymentCard').scrollIntoView({behavior:'smooth'});">
              <i class="fa-solid fa-money-bill-transfer me-1"></i> Disburse Payment (رقم ادا کریں)
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- Date Filter Bar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="supplier_ledger.php" class="row g-3 align-items-end">
          <input type="hidden" name="id" value="<?= $suppId ?>">
          <div class="col-12 col-md-4">
            <label class="form-label">From Date (شروع تاریخ)</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">To Date (آخری تاریخ)</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
          </div>
          <div class="col-12 col-md-4 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-filter me-1"></i> Filter Statement
            </button>
            <a href="supplier_ledger.php?id=<?= $suppId ?>" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
          </div>
        </form>
      </div>
    </div>

    <!-- Ledger Statement Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Mill Purchase & Payment Ledger: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></h6>
        <span class="badge bg-light text-dark border"><?= count($ledgerEntries) ?> transactions</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Date</th>
              <th style="padding: 12px 14px;">Document #</th>
              <th style="padding: 12px 14px;">Description / Particulars</th>
              <th class="text-end" style="padding: 12px 14px;">Debit (بنام / ادائیگیاں)</th>
              <th class="text-end" style="padding: 12px 14px;">Credit (جمع / خریداری بل)</th>
              <th class="text-end" style="padding: 12px 14px;">Balance (واجب الادا بقیہ)</th>
            </tr>
          </thead>
          <tbody>
            <!-- Opening Balance Row -->
            <tr style="background: #f1f5f9; font-weight: 600;">
              <td style="padding: 12px 14px;"><?= htmlspecialchars($startDate) ?></td>
              <td style="padding: 12px 14px;">OPENING</td>
              <td style="padding: 12px 14px;">Opening Payable Balance as of <?= htmlspecialchars($startDate) ?></td>
              <td class="text-end" style="padding: 12px 14px;">-</td>
              <td class="text-end" style="padding: 12px 14px;">-</td>
              <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: <?= $periodOpeningBalance >= 0 ? 'var(--rd)' : 'var(--gn)' ?>;">
                <?= formatCurrency($periodOpeningBalance) ?>
              </td>
            </tr>

            <?php if (empty($ledgerEntries)): ?>
              <tr>
                <td colspan="6" class="text-center py-4 text-muted">No transactions found in this period.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($ledgerEntries as $le): ?>
                <tr>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($le['date']) ?></td>
                  <td style="padding: 12px 14px; font-weight: 600;">
                    <a href="<?= $le['link'] ?>" target="_blank" style="color: var(--nv); text-decoration: none;">
                      <?= htmlspecialchars($le['doc_no']) ?>
                    </a>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div><?= htmlspecialchars($le['desc']) ?></div>
                    <small class="badge bg-light text-secondary border"><?= htmlspecialchars($le['type']) ?></small>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600; color: var(--gn);">
                    <?= $le['debit'] > 0 ? formatCurrency($le['debit']) : '-' ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600; color: #0f172a;">
                    <?= $le['credit'] > 0 ? formatCurrency($le['credit']) : '-' ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: <?= $le['balance'] >= 0 ? 'var(--rd)' : 'var(--gn)' ?>;">
                    <?= formatCurrency($le['balance']) ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot style="background: #f8fafc; border-top: 2px solid var(--bd); font-weight: 700;">
            <tr>
              <td colspan="3" class="text-end" style="padding: 12px 14px;">Period Totals:</td>
              <td class="text-end" style="padding: 12px 14px; color: var(--gn);"><?= formatCurrency($totalPeriodDebit) ?></td>
              <td class="text-end" style="padding: 12px 14px;"><?= formatCurrency($totalPeriodCredit) ?></td>
              <td class="text-end" style="padding: 12px 14px; color: <?= $runningBal >= 0 ? 'var(--rd)' : 'var(--gn)' ?>; font-size: 15px;">
                <?= formatCurrency($runningBal) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Disburse Payment Form (No modal - Clean inline Card) -->
    <div class="card" id="disbursePaymentCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <i class="fa-solid fa-money-bill-wave me-2"></i> Disburse Supplier Payment (Payment Voucher PV - ادائیگی واؤچر)
        </h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="supplier_ledger.php?id=<?= $suppId ?>">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="disburse_payment">

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Payment Date <span class="text-danger">*</span></label>
              <input type="date" name="voucher_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Disburse From Account (کیش یا بینک کھاتہ) <span class="text-danger">*</span></label>
              <select name="financial_account_id" class="form-select" required>
                <option value="">-- Select Paying Account --</option>
                <?php foreach ($finAccounts as $fa): ?>
                  <option value="<?= $fa['id'] ?>">
                    <?= htmlspecialchars($fa['account_name']) ?> (<?= htmlspecialchars($fa['account_type']) ?>) - Balance: <?= formatCurrency((float)$fa['current_balance']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Amount Disbursed (روپے) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00" value="<?= max(0, (float)$supplier['current_balance']) ?>">
              </div>
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="Cheque">Bank Cheque (چیک)</option>
                <option value="Online">Online Bank Transfer</option>
                <option value="Cash">Cash in Hand (نقد)</option>
                <option value="PayOrder">Pay Order / DD</option>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Cheque / Slip / Transaction Ref #</label>
              <input type="text" name="cheque_no" class="form-control" placeholder="e.g. Chq # 552019 drawn on Meezan Bank">
            </div>

            <div class="col-12 col-md-8">
              <label class="form-label">Narration / Remarks (تفصیلات)</label>
              <input type="text" name="narration" class="form-control" placeholder="e.g. Billet purchase consignment payment">
            </div>

            <div class="col-12 text-end mt-4">
              <button type="submit" class="btn btn-zb-primary px-4 py-2" onclick="return confirm('Confirm posting this payment voucher?');">
                <i class="fa-solid fa-check me-1"></i> Post Payment Voucher (ادائیگی واؤچر درج کریں)
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
