<?php
// accounts.php - Cash, Bank & Mobile Wallet Accounts Management
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

requireAuth();
requirePerm('accounts');

$pdo = getPDO();
$pageTitle = 'Cash & Bank Accounts (بینک و کیش کھاتے)';
$errors = [];

// Handle Add / Edit Financial Account
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'save_account') {
            $accId       = (int)($_POST['account_id'] ?? 0);
            $accCode     = trim($_POST['account_code'] ?? '');
            $accName     = trim($_POST['account_name'] ?? '');
            $accType     = trim($_POST['account_type'] ?? 'Bank');
            $bankName    = trim($_POST['bank_name'] ?? '');
            $accNumber   = trim($_POST['account_number'] ?? '');
            $coaId       = (int)($_POST['coa_id'] ?? 0);

            if ($accName === '') {
                $errors[] = 'Account Name is required.';
            }
            if ($accCode === '') {
                $errors[] = 'Account Code is required.';
            }
            if ($coaId <= 0) {
                // If COA not selected, fallback by type
                if ($accType === 'Cash') $coaId = COA_CASH_MAIN;
                elseif ($accType === 'Bank') $coaId = COA_BANK_DEFAULT;
                else $coaId = 7; // Mobile wallet
            }

            if (empty($errors)) {
                try {
                    if ($accId > 0) {
                        $upd = $pdo->prepare("UPDATE financial_accounts SET account_code = ?, account_name = ?, account_type = ?, bank_name = ?, account_number = ?, coa_id = ? WHERE id = ?");
                        $upd->execute([$accCode, $accName, $accType, $bankName, $accNumber, $coaId, $accId]);
                        logAudit('update', 'financial_accounts', $accId, "Updated financial account $accName ($accCode)");
                        setFlash('success', "Account '$accName' updated successfully.");
                    } else {
                        $openBal = (float)($_POST['opening_balance'] ?? 0);
                        $ins = $pdo->prepare("INSERT INTO financial_accounts (coa_id, account_code, account_name, account_type, bank_name, account_number, opening_balance, current_balance) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$coaId, $accCode, $accName, $accType, $bankName, $accNumber, $openBal, $openBal]);
                        $newId = (int)$pdo->lastInsertId();
                        logAudit('create', 'financial_accounts', $newId, "Created financial account $accName ($accCode) with balance $openBal");
                        setFlash('success', "Account '$accName' created successfully.");
                    }
                    header('Location: accounts.php');
                    exit;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// Fetch COA bank/cash accounts for mapping
$coaAccounts = $pdo->query("SELECT id, code, name FROM chart_of_accounts WHERE type = 'Asset' AND sub_type IN ('Cash', 'Bank', 'Mobile Wallet') ORDER BY code ASC")->fetchAll();

// Fetch all financial accounts with COA info
$accounts = $pdo->query("
    SELECT fa.*, coa.code AS coa_code, coa.name AS coa_name
    FROM financial_accounts fa
    JOIN chart_of_accounts coa ON fa.coa_id = coa.id
    ORDER BY fa.account_type ASC, fa.account_name ASC
")->fetchAll();

// KPIs
$totalCash = 0.0;
$totalBank = 0.0;
$totalWallets = 0.0;

foreach ($accounts as $a) {
    $bal = (float)$a['current_balance'];
    if ($a['account_type'] === 'Cash') {
        $totalCash += $bal;
    } elseif ($a['account_type'] === 'Bank') {
        $totalBank += $bal;
    } else {
        $totalWallets += $bal;
    }
}
$totalLiquid = $totalCash + $totalBank + $totalWallets;

// Check edit mode
$editAcc = null;
if (!empty($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $stE = $pdo->prepare("SELECT * FROM financial_accounts WHERE id = ?");
    $stE->execute([$eId]);
    $editAcc = $stE->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Cash, Bank & Wallet Accounts (کھاتے اور بینک رجسٹر)</h4>
        <span class="text-muted" style="font-size: 13px;">Manage counter cash registers, current bank accounts, JazzCash wallets, and liquid balances</span>
      </div>
      <div class="d-flex gap-2">
        <a href="transfers.php" class="btn btn-outline-primary">
          <i class="fa-solid fa-money-bill-transfer me-1"></i> Inter-Account Transfer (رقم ٹرانسفر)
        </a>
        <a href="#accountFormCard" class="btn btn-zb-primary" onclick="document.getElementById('accountFormCard').scrollIntoView({behavior:'smooth'});">
          <i class="fa-solid fa-plus me-1"></i> Add Account (نیا بینک / کیش کھاتہ)
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

    <!-- KPI Cards -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--gn);">
          <div class="kpi-icon" style="background: rgba(22,163,74,0.1); color: var(--gn);"><i class="fa-solid fa-vault"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalLiquid) ?></div>
          <div class="kpi-label">Total Liquid Funds (کل دستیاب نقد و بینک)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--am);">
          <div class="kpi-icon" style="background: rgba(245,166,35,0.1); color: #b7791f;"><i class="fa-solid fa-cash-register"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalCash) ?></div>
          <div class="kpi-label">Counter Cash & Safe (دکان کا کیش)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--nv);">
          <div class="kpi-icon" style="background: rgba(20,48,122,0.1); color: var(--nv);"><i class="fa-solid fa-building-columns"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalBank) ?></div>
          <div class="kpi-label">Bank Accounts (بینک ڈیپازٹس)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid #6366f1;">
          <div class="kpi-icon" style="background: rgba(99,102,241,0.1); color: #6366f1;"><i class="fa-solid fa-mobile-screen"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalWallets) ?></div>
          <div class="kpi-label">JazzCash / EasyPaisa (موبائل بٹوے)</div>
        </div>
      </div>
    </div>

    <!-- Accounts Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Registered Liquid Financial Accounts</h6>
        <span class="badge bg-light text-dark border"><?= count($accounts) ?> accounts</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Code</th>
              <th style="padding: 12px 14px;">Account Name</th>
              <th style="padding: 12px 14px;">Type</th>
              <th style="padding: 12px 14px;">Bank / Branch / Location</th>
              <th style="padding: 12px 14px;">Account / IBAN Number</th>
              <th style="padding: 12px 14px;">Linked COA</th>
              <th class="text-end" style="padding: 12px 14px;">Current Balance (موجودہ بیلنس)</th>
              <th class="text-center" style="padding: 12px 14px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($accounts as $a): 
              $bal = (float)$a['current_balance'];
              $typeBadge = match($a['account_type']) {
                  'Cash' => 'bg-warning-subtle text-warning border-warning',
                  'Bank' => 'bg-primary-subtle text-primary border-primary',
                  default => 'bg-info-subtle text-info border-info'
              };
            ?>
              <tr>
                <td style="padding: 12px 14px; font-weight: 600; color: #475569;">
                  <?= htmlspecialchars($a['account_code']) ?>
                </td>
                <td style="padding: 12px 14px;">
                  <div style="font-weight: 700; color: var(--nv); font-size: 14px;">
                    <a href="account_ledger.php?id=<?= $a['id'] ?>" style="color: inherit; text-decoration: none;">
                      <?= htmlspecialchars($a['account_name']) ?>
                    </a>
                  </div>
                </td>
                <td style="padding: 12px 14px;">
                  <span class="badge <?= $typeBadge ?> border"><?= htmlspecialchars($a['account_type']) ?></span>
                </td>
                <td style="padding: 12px 14px; color: #475569;">
                  <?= htmlspecialchars($a['bank_name'] ?? '-') ?>
                </td>
                <td style="padding: 12px 14px; font-family: monospace; font-size: 13px;">
                  <?= htmlspecialchars($a['account_number'] ?? '-') ?>
                </td>
                <td style="padding: 12px 14px; font-size: 12.5px; color: #64748b;">
                  <?= htmlspecialchars($a['coa_code']) ?> - <?= htmlspecialchars($a['coa_name']) ?>
                </td>
                <td class="text-end" style="padding: 12px 14px; font-weight: 700;">
                  <span style="font-size: 15px; color: <?= $bal >= 0 ? 'var(--gn)' : 'var(--rd)' ?>;">
                    <?= formatCurrency($bal) ?>
                  </span>
                </td>
                <td class="text-center" style="padding: 12px 14px;">
                  <div class="btn-group btn-group-sm">
                    <a href="account_ledger.php?id=<?= $a['id'] ?>" class="btn btn-outline-primary" title="View Statement (کھاتہ اسٹیٹمنٹ)">
                      <i class="fa-solid fa-list-check me-1"></i> Statement
                    </a>
                    <a href="accounts.php?edit=<?= $a['id'] ?>#accountFormCard" class="btn btn-outline-secondary" title="Edit Account">
                      <i class="fa-solid fa-pen"></i>
                    </a>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add / Edit Account Form Card (Modal-free, Zero Overlap) -->
    <div class="card" id="accountFormCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <?= $editAcc ? 'Edit Financial Account: ' . htmlspecialchars($editAcc['account_name']) : 'Add New Cash / Bank Account (نیا بینک یا کیش کھاتہ شامل کریں)' ?>
        </h6>
        <?php if ($editAcc): ?>
          <a href="accounts.php" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="accounts.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_account">
          <input type="hidden" name="account_id" value="<?= $editAcc ? (int)$editAcc['id'] : 0 ?>">

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Account Code / Short ID <span class="text-danger">*</span></label>
              <input type="text" name="account_code" class="form-control" required placeholder="e.g. BANK-HBL" value="<?= htmlspecialchars($editAcc['account_code'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-5">
              <label class="form-label">Account Name (اکاؤنٹ کا نام) <span class="text-danger">*</span></label>
              <input type="text" name="account_name" class="form-control" required placeholder="e.g. Habib Bank Limited - Current A/C" value="<?= htmlspecialchars($editAcc['account_name'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Account Type <span class="text-danger">*</span></label>
              <select name="account_type" class="form-select" required>
                <option value="Bank" <?= (($editAcc['account_type'] ?? 'Bank') === 'Bank') ? 'selected' : '' ?>>Commercial Bank (بینک کھاتہ)</option>
                <option value="Cash" <?= (($editAcc['account_type'] ?? '') === 'Cash') ? 'selected' : '' ?>>Cash Register / In-Shop Safe (دکان کا کیش)</option>
                <option value="Mobile Wallet" <?= (($editAcc['account_type'] ?? '') === 'Mobile Wallet') ? 'selected' : '' ?>>Mobile Wallet (JazzCash / EasyPaisa)</option>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Bank / Branch Name</label>
              <input type="text" name="bank_name" class="form-control" placeholder="e.g. HBL G.T. Road Branch Gujranwala" value="<?= htmlspecialchars($editAcc['bank_name'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Account Number / IBAN</label>
              <input type="text" name="account_number" class="form-control" placeholder="e.g. PK89 HABB 0001 2345 6789 01" value="<?= htmlspecialchars($editAcc['account_number'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Chart of Accounts Mapping</label>
              <select name="coa_id" class="form-select">
                <option value="">-- Auto Match COA --</option>
                <?php foreach ($coaAccounts as $coa): ?>
                  <option value="<?= $coa['id'] ?>" <?= (($editAcc['coa_id'] ?? 0) == $coa['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($coa['code']) ?> - <?= htmlspecialchars($coa['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <?php if (!$editAcc): ?>
              <div class="col-12 col-md-4">
                <label class="form-label">Initial Opening Balance (روپے)</label>
                <input type="number" step="0.01" name="opening_balance" class="form-control" placeholder="0.00" value="0">
              </div>
            <?php endif; ?>

            <div class="col-12 text-end mt-4">
              <?php if ($editAcc): ?>
                <a href="accounts.php" class="btn btn-secondary me-2">Cancel</a>
              <?php endif; ?>
              <button type="submit" class="btn btn-zb-primary px-4">
                <i class="fa-solid fa-save me-1"></i> <?= $editAcc ? 'Update Account' : 'Save Account (محفوظ کریں)' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
