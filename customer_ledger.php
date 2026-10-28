<?php
// customer_ledger.php - Comprehensive Customer Statement & Payment Voucher Entry
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
requirePerm('customers');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Customer Ledger Statement (کسٹمر لیجر کھاتہ)';
$errors = [];

$custId = (int)($_GET['id'] ?? 0);
if ($custId <= 0) {
    header('Location: customers.php');
    exit;
}

// Fetch Customer
$stC = $pdo->prepare("SELECT * FROM parties WHERE id = ? AND type = 'customer'");
$stC->execute([$custId]);
$customer = $stC->fetch();
if (!$customer) {
    setFlash('danger', 'Customer record not found.');
    header('Location: customers.php');
    exit;
}

// Handle Receive Payment (Receipt Voucher RV)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'receive_payment') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $vDate       = trim($_POST['voucher_date'] ?? date('Y-m-d'));
        $accountId   = (int)($_POST['financial_account_id'] ?? 0);
        $amount      = (float)($_POST['amount'] ?? 0);
        $paymentMode = trim($_POST['payment_mode'] ?? 'Cash');
        $chequeNo    = trim($_POST['cheque_no'] ?? '');
        $narration   = trim($_POST['narration'] ?? '');

        if ($amount <= 0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }
        if ($accountId <= 0) {
            $errors[] = 'Please select a receiving Cash or Bank account.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Lock account & customer
                $stAcc = $pdo->prepare("SELECT id, account_name, coa_id, current_balance FROM financial_accounts WHERE id = ? FOR UPDATE");
                $stAcc->execute([$accountId]);
                $acc = $stAcc->fetch();
                if (!$acc) {
                    throw new Exception("Receiving financial account not found.");
                }

                $stCustLock = $pdo->prepare("SELECT id, current_balance, name FROM parties WHERE id = ? FOR UPDATE");
                $stCustLock->execute([$custId]);
                $cLock = $stCustLock->fetch();

                $voucherNo = generateDocumentNumber('RV', 'vouchers', 'voucher_no');

                // Double Entry:
                // Debit: Cash/Bank Account (acc.coa_id)
                // Credit: Accounts Receivable (COA_RECEIVABLE)
                $jLines = [
                    [
                        'account_id'  => (int)$acc['coa_id'],
                        'party_id'    => null,
                        'debit'       => $amount,
                        'credit'      => 0.0,
                        'description' => "Cash/Bank received from {$cLock['name']} ($voucherNo)"
                    ],
                    [
                        'account_id'  => COA_RECEIVABLE,
                        'party_id'    => $custId,
                        'debit'       => 0.0,
                        'credit'      => $amount,
                        'description' => "Account payment by {$cLock['name']} ($voucherNo)"
                    ]
                ];

                $journalId = postJournalEntry($pdo, [
                    'entry_no'    => getNextDocNo('JV', 'journal_entries', 'entry_no'),
                    'entry_date'  => $vDate,
                    'source_type' => 'voucher',
                    'narration'   => "Payment received from customer {$cLock['name']}: $narration",
                    'created_by'  => $currentUser['id'] ?? null
                ], $jLines);

                // Insert into vouchers
                $insV = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, party_id, account_id, amount, payment_mode, cheque_no, narration, journal_id, created_by) VALUES (?, 'RV', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insV->execute([$voucherNo, $vDate, $custId, $accountId, $amount, $paymentMode, $chequeNo, $narration, $journalId, $currentUser['id'] ?? null]);
                $vId = (int)$pdo->lastInsertId();

                $pdo->commit();
                logAudit('create', 'vouchers', $vId, "Recorded payment receipt $voucherNo of Rs $amount from customer {$cLock['name']}");
                setFlash('success', "Payment receipt $voucherNo for Rs " . number_format($amount, 2) . " posted successfully.");
                header("Location: customer_ledger.php?id=$custId");
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
$initOpening = (float)$customer['opening_balance'];

// Sales before startDate
$stSalesPre = $pdo->prepare("SELECT COALESCE(SUM(grand_total - paid_amount), 0) FROM sales WHERE customer_id = ? AND sale_date < ? AND status = 'completed'");
$stSalesPre->execute([$custId, $startDate]);
$preSales = (float)$stSalesPre->fetchColumn();

// Vouchers before startDate
$stVPre = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM vouchers WHERE party_id = ? AND voucher_date < ? AND voucher_type = 'RV' AND status = 'posted'");
$stVPre->execute([$custId, $startDate]);
$preVouchers = (float)$stVPre->fetchColumn();

$periodOpeningBalance = $initOpening + $preSales - $preVouchers;

// 2. Fetch Transactions in period
// Sales
$stSales = $pdo->prepare("SELECT id, invoice_no AS doc_no, sale_date AS trans_date, 'Sale Bill' AS trans_type, grand_total AS debit, paid_amount AS immediate_credit, (grand_total - paid_amount) AS net_debit, notes AS description FROM sales WHERE customer_id = ? AND sale_date BETWEEN ? AND ? AND status = 'completed'");
$stSales->execute([$custId, $startDate, $endDate]);
$periodSales = $stSales->fetchAll();

// Vouchers (RV)
$stV = $pdo->prepare("SELECT v.id, v.voucher_no AS doc_no, v.voucher_date AS trans_date, 'Receipt Voucher' AS trans_type, 0.0 AS debit, v.amount AS credit, v.payment_mode, v.cheque_no, v.narration, a.account_name FROM vouchers v LEFT JOIN financial_accounts a ON v.account_id = a.id WHERE v.party_id = ? AND v.voucher_date BETWEEN ? AND ? AND v.voucher_type = 'RV' AND v.status = 'posted'");
$stV->execute([$custId, $startDate, $endDate]);
$periodVouchers = $stV->fetchAll();

// Merge & Sort Chronologically
$ledgerEntries = [];
foreach ($periodSales as $ps) {
    $ledgerEntries[] = [
        'date'        => $ps['trans_date'],
        'doc_no'      => $ps['doc_no'],
        'type'        => 'Sale Invoice',
        'desc'        => 'Steel Supply Bill' . (!empty($ps['description']) ? ' (' . $ps['description'] . ')' : '') . ((float)$ps['immediate_credit'] > 0 ? ' [Paid at counter: ' . formatCurrency((float)$ps['immediate_credit']) . ']' : ''),
        'debit'       => (float)$ps['debit'],
        'credit'      => (float)$ps['immediate_credit'],
        'link'        => 'print_bill.php?id=' . $ps['id']
    ];
}
foreach ($periodVouchers as $pv) {
    $ledgerEntries[] = [
        'date'        => $pv['trans_date'],
        'doc_no'      => $pv['doc_no'],
        'type'        => 'Payment Received (RV)',
        'desc'        => 'Received into ' . ($pv['account_name'] ?? 'Cash') . ' (' . $pv['payment_mode'] . ($pv['cheque_no'] ? ' Cheque/Slip: ' . $pv['cheque_no'] : '') . ') ' . ($pv['narration'] ?? ''),
        'debit'       => 0.0,
        'credit'      => (float)$pv['credit'],
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
    $runningBal += ($entry['debit'] - $entry['credit']);
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
          <a href="customers.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i></a>
          <h4 class="mb-0" style="font-weight: 700; color: var(--nv);">Customer Statement: <?= htmlspecialchars($customer['name']) ?></h4>
        </div>
        <span class="text-muted ms-4" style="font-size: 13px;">Code: <?= htmlspecialchars($customer['code'] ?? 'CUST-' . $customer['id']) ?> · <?= htmlspecialchars($customer['phone'] ?? 'No Phone') ?> · <?= htmlspecialchars($customer['address'] ?? 'No Address') ?></span>
      </div>
      <div class="d-flex gap-2">
        <?php
          $cleanPhone = preg_replace('/[^0-9]/', '', (string)$customer['phone']);
          if (str_starts_with($cleanPhone, '0')) $cleanPhone = '92' . substr($cleanPhone, 1);
          $waText = urlencode("محترم " . $customer['name'] . " صاحب!\nظفر برادرز آئرن مرچنٹس گوجرانوالہ کی طرف سے آپ کے کھاتہ کی کل بقایا رقم " . formatCurrency((float)$customer['current_balance']) . " ہے۔ براہ کرم جلد ادائیگی فرمائیں۔ شکریہ۔");
        ?>
        <?php if (!empty($customer['phone'])): ?>
          <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= $waText ?>" target="_blank" class="btn btn-success">
            <i class="fa-brands fa-whatsapp me-1"></i> Send WhatsApp Reminder
          </a>
        <?php endif; ?>
        <a href="print_ledger.php?type=customer&id=<?= $custId ?>&start_date=<?= $startDate ?>&end_date=<?= $endDate ?>" target="_blank" class="btn btn-outline-dark">
          <i class="fa-solid fa-print me-1"></i> Print Statement (پرنٹ کھاتہ)
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
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; background: linear-gradient(135deg, #14307a 0%, #1e293b 100%); color: #fff;">
      <div class="card-body p-4">
        <div class="row g-3 align-items-center">
          <div class="col-12 col-md-4">
            <span style="font-size: 13px; opacity: 0.85; display: block;">Total Current Balance Due (کل بقایا رقم):</span>
            <div style="font-size: 28px; font-weight: 800; color: #fbbf24;">
              <?= formatCurrency((float)$customer['current_balance']) ?>
            </div>
          </div>
          <div class="col-12 col-md-4">
            <span style="font-size: 13px; opacity: 0.85; display: block;">Approved Credit Limit (ادھار کی حد):</span>
            <div style="font-size: 20px; font-weight: 700; color: #fff;">
              <?= (float)$customer['credit_limit'] > 0 ? formatCurrency((float)$customer['credit_limit']) : 'No Limit Assigned' ?>
            </div>
          </div>
          <div class="col-12 col-md-4 text-md-end">
            <a href="#paymentCard" class="btn btn-zb-gold px-4 py-2" onclick="document.getElementById('paymentCard').scrollIntoView({behavior:'smooth'});">
              <i class="fa-solid fa-money-bill-wave me-1"></i> Receive Payment (رقم وصول کریں)
            </a>
          </div>
        </div>
      </div>
    </div>

    <!-- Date Filter Bar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="customer_ledger.php" class="row g-3 align-items-end">
          <input type="hidden" name="id" value="<?= $custId ?>">
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
            <a href="customer_ledger.php?id=<?= $custId ?>" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
          </div>
        </form>
      </div>
    </div>

    <!-- Ledger Statement Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Account Statement: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></h6>
        <span class="badge bg-light text-dark border"><?= count($ledgerEntries) ?> entries</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Date</th>
              <th style="padding: 12px 14px;">Document #</th>
              <th style="padding: 12px 14px;">Description / Particulars</th>
              <th class="text-end" style="padding: 12px 14px;">Debit (بنام / مال فروخت)</th>
              <th class="text-end" style="padding: 12px 14px;">Credit (جمع / وصولی)</th>
              <th class="text-end" style="padding: 12px 14px;">Balance (میزان بقایا)</th>
            </tr>
          </thead>
          <tbody>
            <!-- Opening Balance Row -->
            <tr style="background: #f1f5f9; font-weight: 600;">
              <td style="padding: 12px 14px;"><?= htmlspecialchars($startDate) ?></td>
              <td style="padding: 12px 14px;">OPENING</td>
              <td style="padding: 12px 14px;">Opening Balance as of <?= htmlspecialchars($startDate) ?></td>
              <td class="text-end" style="padding: 12px 14px;">-</td>
              <td class="text-end" style="padding: 12px 14px;">-</td>
              <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: <?= $periodOpeningBalance >= 0 ? 'var(--rd)' : 'var(--gn)' ?>;">
                <?= formatCurrency($periodOpeningBalance) ?>
              </td>
            </tr>

            <?php if (empty($ledgerEntries)): ?>
              <tr>
                <td colspan="6" class="text-center py-4 text-muted">No transactions recorded during this period.</td>
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
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600; color: #0f172a;">
                    <?= $le['debit'] > 0 ? formatCurrency($le['debit']) : '-' ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600; color: var(--gn);">
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
              <td class="text-end" style="padding: 12px 14px;"><?= formatCurrency($totalPeriodDebit) ?></td>
              <td class="text-end" style="padding: 12px 14px; color: var(--gn);"><?= formatCurrency($totalPeriodCredit) ?></td>
              <td class="text-end" style="padding: 12px 14px; color: <?= $runningBal >= 0 ? 'var(--rd)' : 'var(--gn)' ?>; font-size: 15px;">
                <?= formatCurrency($runningBal) ?>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Receive Payment Form (No modal - Clean inline Card) -->
    <div class="card" id="paymentCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <i class="fa-solid fa-receipt me-2"></i> Receive Customer Payment (Receipt Voucher RV - کسٹمر سے وصولی)
        </h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="customer_ledger.php?id=<?= $custId ?>">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="receive_payment">

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Receipt Date <span class="text-danger">*</span></label>
              <input type="date" name="voucher_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Deposit To Account (کیش یا بینک کھاتہ) <span class="text-danger">*</span></label>
              <select name="financial_account_id" class="form-select" required>
                <option value="">-- Select Receiving Account --</option>
                <?php foreach ($finAccounts as $fa): ?>
                  <option value="<?= $fa['id'] ?>">
                    <?= htmlspecialchars($fa['account_name']) ?> (<?= htmlspecialchars($fa['account_type']) ?>) - Balance: <?= formatCurrency((float)$fa['current_balance']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Amount Received (روپے) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00" value="<?= max(0, (float)$customer['current_balance']) ?>">
              </div>
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Payment Mode</label>
              <select name="payment_mode" class="form-select">
                <option value="Cash">Cash (نقد)</option>
                <option value="Cheque">Bank Cheque (چیک)</option>
                <option value="Online">Online Bank Transfer</option>
                <option value="PayOrder">Pay Order / DD</option>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Cheque / Slip / Transaction Ref #</label>
              <input type="text" name="cheque_no" class="form-control" placeholder="e.g. Chq # 482910 Bank Al Habib">
            </div>

            <div class="col-12 col-md-8">
              <label class="form-label">Narration / Remarks (تفصیلات)</label>
              <input type="text" name="narration" class="form-control" placeholder="e.g. Payment against Bill # INV-2026-0001">
            </div>

            <div class="col-12 text-end mt-4">
              <button type="submit" class="btn btn-zb-primary px-4 py-2" onclick="return confirm('Confirm posting this receipt voucher?');">
                <i class="fa-solid fa-check me-1"></i> Post Receipt Voucher (وصولی درج کریں)
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
