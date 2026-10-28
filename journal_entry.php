<?php
// journal_entry.php - Manual Journal Voucher (JV) Entry Screen
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
requirePerm('vouchers');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Manual Journal Voucher (روزنامچہ جے وی)';
$errors = [];

// Handle POST Journal Entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_jv') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
        $narration = trim($_POST['narration'] ?? '');
        $refNo     = trim($_POST['reference_no'] ?? '');

        $accountIds = $_POST['account_id'] ?? [];
        $lineDescs  = $_POST['line_desc'] ?? [];
        $debits     = $_POST['debit'] ?? [];
        $credits    = $_POST['credit'] ?? [];

        $jLines = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        for ($i = 0; $i < count($accountIds); $i++) {
            $accId = (int)($accountIds[$i] ?? 0);
            $deb   = (float)($debits[$i] ?? 0);
            $crd   = (float)($credits[$i] ?? 0);
            $desc  = trim($lineDescs[$i] ?? '');

            if ($accId <= 0 || ($deb <= 0 && $crd <= 0)) {
                continue;
            }

            $totalDebit += $deb;
            $totalCredit += $crd;
            $jLines[] = [
                'account_id'  => $accId,
                'debit'       => $deb,
                'credit'      => $crd,
                'description' => $desc
            ];
        }

        if (count($jLines) < 2) {
            $errors[] = 'A journal voucher must contain at least two balanced entry lines.';
        }
        if (abs($totalDebit - $totalCredit) > 0.01) {
            $errors[] = "Journal entry is not balanced. Total Debits (Rs " . number_format($totalDebit, 2) . ") must equal Total Credits (Rs " . number_format($totalCredit, 2) . ").";
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $voucherNo = generateDocumentNumber('JV', 'vouchers', 'voucher_no');

                $journalId = postJournalEntry($pdo, [
                    'entry_no'    => getNextDocNo('JV', 'journal_entries', 'entry_no'),
                    'entry_date'  => $entryDate,
                    'source_type' => 'voucher',
                    'narration'   => $narration . (!empty($refNo) ? " [Ref: $refNo]" : ''),
                    'created_by'  => $currentUser['id'] ?? null
                ], $jLines);

                // Insert into vouchers
                $insV = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, amount, cheque_no, narration, journal_id, created_by) VALUES (?, 'JV', ?, ?, ?, ?, ?, ?)");
                $insV->execute([$voucherNo, $entryDate, $totalDebit, $refNo, $narration, $journalId, $currentUser['id'] ?? null]);
                $vId = (int)$pdo->lastInsertId();

                $pdo->commit();
                logAudit('create', 'vouchers', $vId, "Posted manual journal voucher $voucherNo of Rs $totalDebit");
                setFlash('success', "Journal voucher $voucherNo for Rs " . number_format($totalDebit, 2) . " posted successfully.");
                header('Location: vouchers.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Fetch COA accounts for row dropdowns
$coaAccounts = $pdo->query("SELECT id, code, name, type, sub_type FROM chart_of_accounts WHERE is_active = 1 ORDER BY code ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <div class="d-flex align-items-center gap-2">
          <a href="vouchers.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left"></i></a>
          <h4 class="mb-0" style="font-weight: 700; color: var(--nv);">New Journal Voucher (مینول جے وی واؤچر)</h4>
        </div>
        <span class="text-muted ms-4" style="font-size: 13px;">Create custom balanced accounting entries for asset depreciation, corrections, or year-end adjustments</span>
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

    <!-- JV Form Card -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Journal Voucher Header</h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="journal_entry.php" id="jvForm">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_jv">

          <div class="row g-3 mb-4">
            <div class="col-12 col-md-3">
              <label class="form-label">Voucher Date <span class="text-danger">*</span></label>
              <input type="date" name="entry_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Reference / Slip #</label>
              <input type="text" name="reference_no" class="form-control" placeholder="e.g. Doc Ref # / Cheque #">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">General Narration / Purpose (تفصیل واؤچر) <span class="text-danger">*</span></label>
              <input type="text" name="narration" class="form-control" required placeholder="e.g. Month-end adjustment, depreciation, or correction entry">
            </div>
          </div>

          <!-- Journal Lines Table -->
          <div class="table-responsive mb-3">
            <table class="table table-bordered align-middle" id="jvLinesTable" style="font-size: 13.5px;">
              <thead style="background: #f8fafc;">
                <tr>
                  <th style="width: 35%;">Chart of Accounts (کھاتہ)</th>
                  <th style="width: 30%;">Line Particulars / Remarks</th>
                  <th style="width: 15%;" class="text-end">Debit (بنام)</th>
                  <th style="width: 15%;" class="text-end">Credit (جمع)</th>
                  <th style="width: 5%;" class="text-center">Action</th>
                </tr>
              </thead>
              <tbody id="jvTableBody">
                <!-- Row 1 -->
                <tr>
                  <td>
                    <select name="account_id[]" class="form-select jv-acc" required>
                      <option value="">-- Choose Account --</option>
                      <?php foreach ($coaAccounts as $coa): ?>
                        <option value="<?= $coa['id'] ?>"><?= htmlspecialchars($coa['code']) ?> - <?= htmlspecialchars($coa['name']) ?> (<?= $coa['type'] ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <input type="text" name="line_desc[]" class="form-control" placeholder="Description...">
                  </td>
                  <td>
                    <input type="number" step="0.01" name="debit[]" class="form-control text-end jv-debit" placeholder="0.00" oninput="recalcJV()">
                  </td>
                  <td>
                    <input type="number" step="0.01" name="credit[]" class="form-control text-end jv-credit" placeholder="0.00" oninput="recalcJV()">
                  </td>
                  <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button>
                  </td>
                </tr>

                <!-- Row 2 -->
                <tr>
                  <td>
                    <select name="account_id[]" class="form-select jv-acc" required>
                      <option value="">-- Choose Account --</option>
                      <?php foreach ($coaAccounts as $coa): ?>
                        <option value="<?= $coa['id'] ?>"><?= htmlspecialchars($coa['code']) ?> - <?= htmlspecialchars($coa['name']) ?> (<?= $coa['type'] ?>)</option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td>
                    <input type="text" name="line_desc[]" class="form-control" placeholder="Description...">
                  </td>
                  <td>
                    <input type="number" step="0.01" name="debit[]" class="form-control text-end jv-debit" placeholder="0.00" oninput="recalcJV()">
                  </td>
                  <td>
                    <input type="number" step="0.01" name="credit[]" class="form-control text-end jv-credit" placeholder="0.00" oninput="recalcJV()">
                  </td>
                  <td class="text-center">
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button>
                  </td>
                </tr>
              </tbody>
              <tfoot style="background: #f8fafc; font-weight: 700;">
                <tr>
                  <td colspan="2" class="text-end">Total:</td>
                  <td class="text-end" id="jvTotalDebit">Rs 0.00</td>
                  <td class="text-end" id="jvTotalCredit">Rs 0.00</td>
                  <td></td>
                </tr>
                <tr id="diffRow">
                  <td colspan="2" class="text-end">Balance Difference:</td>
                  <td colspan="2" class="text-center" id="jvDiff">Rs 0.00 (Balanced)</td>
                  <td></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary" onclick="addRow()">
              <i class="fa-solid fa-plus me-1"></i> Add Line Row (مزید لائن شامل کریں)
            </button>
            <button type="submit" id="jvSubmitBtn" class="btn btn-zb-primary px-4 py-2" onclick="return validateBeforeSubmit();">
              <i class="fa-solid fa-check me-1"></i> Post Journal Voucher (جے وی محفوظ کریں)
            </button>
          </div>

        </form>
      </div>
    </div>

  </div>
</main>

<script>
const coaOptionsHTML = `<?php foreach ($coaAccounts as $coa): ?><option value="<?= $coa['id'] ?>"><?= htmlspecialchars($coa['code']) ?> - <?= htmlspecialchars(addslashes($coa['name'])) ?> (<?= $coa['type'] ?>)</option><?php endforeach; ?>`;

function addRow() {
  const tbody = document.getElementById('jvTableBody');
  const tr = document.createElement('tr');
  tr.innerHTML = `
    <td>
      <select name="account_id[]" class="form-select jv-acc" required>
        <option value="">-- Choose Account --</option>
        ${coaOptionsHTML}
      </select>
    </td>
    <td>
      <input type="text" name="line_desc[]" class="form-control" placeholder="Description...">
    </td>
    <td>
      <input type="number" step="0.01" name="debit[]" class="form-control text-end jv-debit" placeholder="0.00" oninput="recalcJV()">
    </td>
    <td>
      <input type="number" step="0.01" name="credit[]" class="form-control text-end jv-credit" placeholder="0.00" oninput="recalcJV()">
    </td>
    <td class="text-center">
      <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeRow(this)"><i class="fa-solid fa-trash"></i></button>
    </td>
  `;
  tbody.appendChild(tr);
}

function removeRow(btn) {
  const tbody = document.getElementById('jvTableBody');
  if (tbody.querySelectorAll('tr').length <= 2) {
    alert('At least two journal lines are required for double-entry.');
    return;
  }
  btn.closest('tr').remove();
  recalcJV();
}

function recalcJV() {
  let totalD = 0;
  let totalC = 0;

  document.querySelectorAll('.jv-debit').forEach(inp => {
    totalD += parseFloat(inp.value) || 0;
  });
  document.querySelectorAll('.jv-credit').forEach(inp => {
    totalC += parseFloat(inp.value) || 0;
  });

  document.getElementById('jvTotalDebit').innerText = 'Rs ' + totalD.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
  document.getElementById('jvTotalCredit').innerText = 'Rs ' + totalC.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});

  const diff = Math.abs(totalD - totalC);
  const diffEl = document.getElementById('jvDiff');
  const btn = document.getElementById('jvSubmitBtn');

  if (diff < 0.01 && (totalD > 0 || totalC > 0)) {
    diffEl.innerHTML = '<span class="text-success"><i class="fa-solid fa-circle-check me-1"></i> Rs 0.00 (Perfectly Balanced)</span>';
    btn.disabled = false;
  } else {
    diffEl.innerHTML = '<span class="text-danger"><i class="fa-solid fa-circle-xmark me-1"></i> Difference: Rs ' + diff.toFixed(2) + ' (Unbalanced)</span>';
    btn.disabled = false;
  }
}

function validateBeforeSubmit() {
  let totalD = 0;
  let totalC = 0;
  document.querySelectorAll('.jv-debit').forEach(inp => { totalD += parseFloat(inp.value) || 0; });
  document.querySelectorAll('.jv-credit').forEach(inp => { totalC += parseFloat(inp.value) || 0; });

  if (Math.abs(totalD - totalC) > 0.01) {
    alert('Journal entry cannot be saved because Debits do not equal Credits.');
    return false;
  }
  if (totalD <= 0) {
    alert('Journal amount must be greater than zero.');
    return false;
  }
  return confirm('Are you sure you want to post this Journal Voucher to the General Ledger?');
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
