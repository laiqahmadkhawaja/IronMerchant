<?php
// transfers.php - Inter-Account Fund Transfers (Internal Liquidity Movement)
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
requirePerm('transfers');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Fund Transfers (اندرونی فنڈ ٹرانسفر)';
$errors = [];

$preFromId = (int)($_GET['from_id'] ?? 0);

// Handle POST Transfer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_transfer') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $trfDate  = trim($_POST['transfer_date'] ?? date('Y-m-d'));
        $fromId   = (int)($_POST['from_account_id'] ?? 0);
        $toId     = (int)($_POST['to_account_id'] ?? 0);
        $amount   = (float)($_POST['amount'] ?? 0);
        $feeAmt   = (float)($_POST['fee_amount'] ?? 0);
        $refNo    = trim($_POST['reference_no'] ?? '');
        $notes    = trim($_POST['narration'] ?? '');

        if ($fromId <= 0 || $toId <= 0) {
            $errors[] = 'Please select both source and destination accounts.';
        } elseif ($fromId === $toId) {
            $errors[] = 'Source and destination accounts cannot be the same.';
        }
        if ($amount <= 0) {
            $errors[] = 'Transfer amount must be greater than zero.';
        }
        if ($feeAmt < 0) {
            $errors[] = 'Fee amount cannot be negative.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Lock both accounts in deterministic order to prevent deadlock
                $firstId = min($fromId, $toId);
                $secondId = max($fromId, $toId);

                $stLock = $pdo->prepare("SELECT id, account_name, coa_id, current_balance FROM financial_accounts WHERE id IN (?, ?) FOR UPDATE");
                $stLock->execute([$firstId, $secondId]);
                $accRows = $stLock->fetchAll();

                $fromAcc = null;
                $toAcc = null;
                foreach ($accRows as $ar) {
                    if ((int)$ar['id'] === $fromId) $fromAcc = $ar;
                    if ((int)$ar['id'] === $toId) $toAcc = $ar;
                }

                if (!$fromAcc || !$toAcc) {
                    throw new Exception("One or both financial accounts could not be found.");
                }

                $totalOutflow = $amount + $feeAmt;
                if ((float)$fromAcc['current_balance'] < $totalOutflow) {
                    // In real business sometimes temporary overdraft is permitted, but let's warn or allow if authorized
                }

                $trfNo = generateDocumentNumber('TRF', 'inter_account_transfers', 'transfer_no');
                $voucherNo = generateDocumentNumber('TV', 'vouchers', 'voucher_no');

                // Double Entry Accounting:
                // Debit: Destination Account COA (toAcc.coa_id) for amount
                // Debit (if fee): Bank Service Charges (37 / COA 5180) for feeAmt
                // Credit: Source Account COA (fromAcc.coa_id) for amount + feeAmt
                $jLines = [];
                $jLines[] = [
                    'account_id'  => (int)$toAcc['coa_id'],
                    'debit'       => $amount,
                    'credit'      => 0.0,
                    'description' => "Transfer deposit from {$fromAcc['account_name']} ($trfNo)"
                ];

                if ($feeAmt > 0) {
                    $jLines[] = [
                        'account_id'  => 37, // 5180 Bank Service Charges
                        'debit'       => $feeAmt,
                        'credit'      => 0.0,
                        'description' => "Bank IBFT/Transfer service charges on $trfNo"
                    ];
                }

                $jLines[] = [
                    'account_id'  => (int)$fromAcc['coa_id'],
                    'debit'       => 0.0,
                    'credit'      => $totalOutflow,
                    'description' => "Transfer sent to {$toAcc['account_name']} ($trfNo)"
                ];

                $journalId = postJournalEntry($pdo, [
                    'entry_no'    => getNextDocNo('JV', 'journal_entries', 'entry_no'),
                    'entry_date'  => $trfDate,
                    'source_type' => 'transfer',
                    'narration'   => "Internal transfer from {$fromAcc['account_name']} to {$toAcc['account_name']}: $notes",
                    'created_by'  => $currentUser['id'] ?? null
                ], $jLines);

                // Insert into vouchers
                $insV = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, account_id, amount, payment_mode, cheque_no, narration, journal_id, created_by) VALUES (?, 'TV', ?, ?, ?, 'Transfer', ?, ?, ?, ?)");
                $insV->execute([$voucherNo, $trfDate, $fromId, $amount, $refNo, "Transfer from {$fromAcc['account_name']} to {$toAcc['account_name']}: $notes", $journalId, $currentUser['id'] ?? null]);
                $voucherId = (int)$pdo->lastInsertId();

                // Insert into inter_account_transfers
                $insT = $pdo->prepare("INSERT INTO inter_account_transfers (transfer_no, transfer_date, from_account_id, to_account_id, amount, fee_amount, fee_account_id, reference_no, narration, voucher_id, journal_id, status) VALUES (?, ?, ?, ?, ?, ?, 37, ?, ?, ?, ?, 'posted')");
                $insT->execute([$trfNo, $trfDate, $fromId, $toId, $amount, $feeAmt, $refNo, $notes, $voucherId, $journalId]);
                $trfId = (int)$pdo->lastInsertId();

                $pdo->commit();
                logAudit('create', 'inter_account_transfers', $trfId, "Transferred Rs $amount from {$fromAcc['account_name']} to {$toAcc['account_name']} ($trfNo)");
                setFlash('success', "Transfer $trfNo ($voucherNo) for Rs " . number_format($amount, 2) . " executed successfully.");
                header('Location: transfers.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Fetch financial accounts
$accounts = $pdo->query("SELECT id, account_code, account_name, account_type, current_balance FROM financial_accounts WHERE is_active = 1 ORDER BY account_type, account_name")->fetchAll();

// Fetch transfer history
$transfers = $pdo->query("
    SELECT t.*, faFrom.account_name AS from_name, faFrom.account_type AS from_type,
           faTo.account_name AS to_name, faTo.account_type AS to_type,
           v.voucher_no
    FROM inter_account_transfers t
    JOIN financial_accounts faFrom ON t.from_account_id = faFrom.id
    JOIN financial_accounts faTo ON t.to_account_id = faTo.id
    LEFT JOIN vouchers v ON t.voucher_id = v.id
    ORDER BY t.id DESC LIMIT 50
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Inter-Account Fund Transfers (اندرونی فنڈ ٹرانسفر)</h4>
        <span class="text-muted" style="font-size: 13px;">Move funds between cash registers, banks, and mobile wallets with zero false revenue</span>
      </div>
      <div>
        <a href="accounts.php" class="btn btn-outline-secondary">
          <i class="fa-solid fa-arrow-left me-1"></i> Back to Accounts
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

    <!-- Transfer Entry Form Card (Modal-free, Zero Overlap) -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <i class="fa-solid fa-money-bill-transfer me-2"></i> Execute New Inter-Account Transfer (رقم منتقل کریں)
        </h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="transfers.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_transfer">

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Transfer Date <span class="text-danger">*</span></label>
              <input type="date" name="transfer_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Source Account (کس کھاتے سے رقم نکالنی ہے؟) <span class="text-danger">*</span></label>
              <select name="from_account_id" class="form-select" required>
                <option value="">-- Select Source Account --</option>
                <?php foreach ($accounts as $a): ?>
                  <option value="<?= $a['id'] ?>" <?= ($preFromId === (int)$a['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($a['account_name']) ?> (<?= htmlspecialchars($a['account_type']) ?>) - Balance: <?= formatCurrency((float)$a['current_balance']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-5">
              <label class="form-label">Destination Account (کس کھاتے میں جمع کرنی ہے؟) <span class="text-danger">*</span></label>
              <select name="to_account_id" class="form-select" required>
                <option value="">-- Select Destination Account --</option>
                <?php foreach ($accounts as $a): ?>
                  <option value="<?= $a['id'] ?>">
                    <?= htmlspecialchars($a['account_name']) ?> (<?= htmlspecialchars($a['account_type']) ?>) - Balance: <?= formatCurrency((float)$a['current_balance']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Transfer Amount (رقم - روپے) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00">
              </div>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Transfer Fee / IBFT Charges (اگر لاگو ہوں)</label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" name="fee_amount" class="form-control" placeholder="0.00" value="0.00">
              </div>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Cheque / Slip / IBFT Ref #</label>
              <input type="text" name="reference_no" class="form-control" placeholder="e.g. Deposit Slip # 48201 / IBFT ID">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Narration / Purpose (تفصیل)</label>
              <input type="text" name="narration" class="form-control" placeholder="e.g. Daily cash collection deposit into bank">
            </div>

            <div class="col-12 text-end mt-4">
              <button type="submit" class="btn btn-zb-primary px-4 py-2" onclick="return confirm('Confirm executing this inter-account transfer?');">
                <i class="fa-solid fa-check me-1"></i> Confirm & Execute Transfer (منتقلی مکمل کریں)
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Transfer History Table -->
    <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Recent Transfers History (سابقہ ٹرانسفر ریکارڈ)</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Transfer #</th>
              <th style="padding: 12px 14px;">Date</th>
              <th style="padding: 12px 14px;">From (بھیجنے والا)</th>
              <th style="padding: 12px 14px;">To (وصول کرنے والا)</th>
              <th class="text-end" style="padding: 12px 14px;">Amount Transferred</th>
              <th class="text-end" style="padding: 12px 14px;">Fee / Tax</th>
              <th style="padding: 12px 14px;">Reference & Narration</th>
              <th class="text-center" style="padding: 12px 14px;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($transfers)): ?>
              <tr>
                <td colspan="8" class="text-center py-4 text-muted">No fund transfers recorded yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($transfers as $t): ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 600; color: var(--nv);">
                    <?= htmlspecialchars($t['transfer_no']) ?>
                    <?php if (!empty($t['voucher_no'])): ?>
                      <div class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($t['voucher_no']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($t['transfer_date']) ?></td>
                  <td style="padding: 12px 14px;">
                    <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($t['from_name']) ?></div>
                    <small class="badge bg-light text-secondary border"><?= htmlspecialchars($t['from_type']) ?></small>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($t['to_name']) ?></div>
                    <small class="badge bg-light text-secondary border"><?= htmlspecialchars($t['to_type']) ?></small>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: var(--nv); font-size: 14.5px;">
                    <?= formatCurrency((float)$t['amount']) ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; color: #64748b;">
                    <?= (float)$t['fee_amount'] > 0 ? formatCurrency((float)$t['fee_amount']) : '-' ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div><?= htmlspecialchars($t['narration'] ?? '-') ?></div>
                    <?php if (!empty($t['reference_no'])): ?>
                      <small class="text-muted">Ref: <?= htmlspecialchars($t['reference_no']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <span class="badge bg-success">Posted</span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
