<?php
// suppliers.php - Steel Mills & Supplier Accounts Directory
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
requirePerm('suppliers');

$pdo = getPDO();
$pageTitle = 'Suppliers Directory (سپلائرز و ملز رجسٹر)';
$errors = [];

// Handle Add / Edit Supplier
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'save_supplier') {
            $suppId      = (int)($_POST['supplier_id'] ?? 0);
            $name        = trim($_POST['name'] ?? '');
            $code        = trim($_POST['code'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');
            $cnic        = trim($_POST['cnic'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $address     = trim($_POST['address'] ?? '');
            $notes       = trim($_POST['notes'] ?? '');

            if ($name === '') {
                $errors[] = 'Supplier name is required.';
            }

            if (empty($errors)) {
                try {
                    if ($suppId > 0) {
                        $upd = $pdo->prepare("UPDATE parties SET name = ?, code = ?, phone = ?, cnic = ?, company_name = ?, address = ?, notes = ? WHERE id = ? AND type = 'supplier'");
                        $upd->execute([$name, $code, $phone, $cnic, $companyName, $address, $notes, $suppId]);
                        logAudit('update', 'parties', $suppId, "Updated supplier $name ($code)");
                        setFlash('success', "Supplier '$name' updated successfully.");
                    } else {
                        if ($code === '') {
                            $code = generateDocumentNumber('SUPP', 'parties', 'code');
                        }
                        $openBal = (float)($_POST['opening_balance'] ?? 0);
                        $ins = $pdo->prepare("INSERT INTO parties (type, code, name, phone, cnic, company_name, address, opening_balance, current_balance, notes) VALUES ('supplier', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$code, $name, $phone, $cnic, $companyName, $address, $openBal, $openBal, $notes]);
                        $newId = (int)$pdo->lastInsertId();
                        logAudit('create', 'parties', $newId, "Created supplier $name ($code) with opening balance $openBal");
                        setFlash('success', "Supplier '$name' added successfully.");
                    }
                    header('Location: suppliers.php');
                    exit;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// Search and filter
$search = trim($_GET['search'] ?? '');
$balanceFilter = trim($_GET['balance_filter'] ?? '');

$sql = "SELECT * FROM parties WHERE type = 'supplier'";
$params = [];

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR phone LIKE ? OR code LIKE ? OR company_name LIKE ? OR address LIKE ?)";
    $params = array_fill(0, 5, "%$search%");
}

if ($balanceFilter === 'payable') {
    $sql .= " AND current_balance > 0";
} elseif ($balanceFilter === 'advance') {
    $sql .= " AND current_balance < 0";
} elseif ($balanceFilter === 'zero') {
    $sql .= " AND current_balance = 0";
}

$sql .= " ORDER BY current_balance DESC, name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

// KPIs
$totalPayables = 0.0;
$totalAdvancesGiven = 0.0;
$withBalanceCount = 0;
foreach ($suppliers as $s) {
    $bal = (float)$s['current_balance'];
    if ($bal > 0) {
        $totalPayables += $bal;
        $withBalanceCount++;
    } elseif ($bal < 0) {
        $totalAdvancesGiven += abs($bal);
    }
}

// Check edit mode
$editSupp = null;
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stE = $pdo->prepare("SELECT * FROM parties WHERE id = ? AND type = 'supplier'");
    $stE->execute([$editId]);
    $editSupp = $stE->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Suppliers & Steel Mills (سپلائرز و رولنگ ملز)</h4>
        <span class="text-muted" style="font-size: 13px;">Manage steel mill accounts, raw billet/rebar suppliers, payables, and payment vouchers</span>
      </div>
      <div>
        <a href="#supplierFormCard" class="btn btn-zb-primary" onclick="document.getElementById('supplierFormCard').scrollIntoView({behavior:'smooth'});">
          <i class="fa-solid fa-plus me-1"></i> Add New Supplier (نیا سپلائر)
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
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="kpi-card" style="border-left: 4px solid var(--rd);">
          <div class="kpi-icon" style="background: rgba(224,36,36,0.1); color: var(--rd);"><i class="fa-solid fa-file-invoice-dollar"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalPayables) ?></div>
          <div class="kpi-label">Total Supplier Payables (ملز کے واجبات)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="kpi-card" style="border-left: 4px solid var(--gn);">
          <div class="kpi-icon" style="background: rgba(22,163,74,0.1); color: var(--gn);"><i class="fa-solid fa-hand-holding-dollar"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalAdvancesGiven) ?></div>
          <div class="kpi-label">Advances to Suppliers (سپلائر ایڈوانس)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="kpi-card" style="border-left: 4px solid var(--nv);">
          <div class="kpi-icon" style="background: rgba(20,48,122,0.1); color: var(--nv);"><i class="fa-solid fa-industry"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= count($suppliers) ?></div>
          <div class="kpi-label">Registered Suppliers & Mills (کل سپلائرز)</div>
        </div>
      </div>
    </div>

    <!-- Filter & Search Card -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="suppliers.php" class="row g-3 align-items-end">
          <div class="col-12 col-md-6">
            <label class="form-label">Search Supplier / Mill Name</label>
            <input type="text" name="search" class="form-control" placeholder="Search by mill name, phone, code or city..." value="<?= htmlspecialchars($search) ?>">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Balance Filter</label>
            <select name="balance_filter" class="form-select">
              <option value="">All Suppliers (تمام سپلائرز)</option>
              <option value="payable" <?= ($balanceFilter === 'payable') ? 'selected' : '' ?>>Payables Only (جن کو رقم دینی ہے > 0)</option>
              <option value="advance" <?= ($balanceFilter === 'advance') ? 'selected' : '' ?>>Advance Balance Only (< 0)</option>
              <option value="zero" <?= ($balanceFilter === 'zero') ? 'selected' : '' ?>>Zero Balance (Clear)</option>
            </select>
          </div>
          <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-search me-1"></i> Search
            </button>
            <?php if ($search !== '' || $balanceFilter !== ''): ?>
              <a href="suppliers.php" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Suppliers List Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Active Steel Suppliers & Mills</h6>
        <span class="badge bg-light text-dark border"><?= count($suppliers) ?> records</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Code</th>
              <th style="padding: 12px 14px;">Supplier / Mill Name</th>
              <th style="padding: 12px 14px;">Contact & Phone</th>
              <th style="padding: 12px 14px;">Address / Location</th>
              <th class="text-end" style="padding: 12px 14px;">Outstanding Balance (واجب الادا رقم)</th>
              <th class="text-center" style="padding: 12px 14px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($suppliers)): ?>
              <tr>
                <td colspan="6" class="text-center py-4 text-muted">No suppliers found matching your criteria.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($suppliers as $s): 
                $bal = (float)$s['current_balance'];
              ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 600; color: #475569;">
                    <?= htmlspecialchars($s['code'] ?? 'SUPP-' . $s['id']) ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div style="font-weight: 600; color: var(--nv); font-size: 14px;">
                      <a href="supplier_ledger.php?id=<?= $s['id'] ?>" style="color: inherit; text-decoration: none;">
                        <?= htmlspecialchars($s['name']) ?>
                      </a>
                    </div>
                    <?php if (!empty($s['company_name'])): ?>
                      <small class="text-muted"><i class="fa-solid fa-industry me-1"></i><?= htmlspecialchars($s['company_name']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <?= htmlspecialchars($s['phone'] ?? '-') ?>
                  </td>
                  <td style="padding: 12px 14px; color: #475569;">
                    <?= htmlspecialchars($s['address'] ?? '-') ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700;">
                    <?php if ($bal > 0): ?>
                      <span style="color: var(--rd); font-size: 14.5px;"><?= formatCurrency($bal) ?> Cr (Payable)</span>
                    <?php elseif ($bal < 0): ?>
                      <span style="color: var(--gn); font-size: 14.5px;"><?= formatCurrency(abs($bal)) ?> Dr (Advance)</span>
                    <?php else: ?>
                      <span class="text-muted">Nil (0.00)</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <div class="btn-group btn-group-sm">
                      <a href="supplier_ledger.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary" title="View Supplier Ledger (کھاتہ تفصیل)">
                        <i class="fa-solid fa-book-open me-1"></i> Ledger
                      </a>
                      <a href="suppliers.php?edit=<?= $s['id'] ?>#supplierFormCard" class="btn btn-outline-secondary" title="Edit Profile">
                        <i class="fa-solid fa-pen"></i>
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add / Edit Supplier Form Card (Clean, No Modal, Zero Overlap) -->
    <div class="card" id="supplierFormCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <?= $editSupp ? 'Edit Supplier: ' . htmlspecialchars($editSupp['name']) : 'Add New Steel Mill / Supplier Profile (نیا سپلائر شامل کریں)' ?>
        </h6>
        <?php if ($editSupp): ?>
          <a href="suppliers.php" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="suppliers.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_supplier">
          <input type="hidden" name="supplier_id" value="<?= $editSupp ? (int)$editSupp['id'] : 0 ?>">

          <div class="row g-3">
            <div class="col-12 col-md-4">
              <label class="form-label">Supplier / Mill Contact Person <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="e.g. Haji Bashir Ahmad (حاجی بشیر احمد)" value="<?= htmlspecialchars($editSupp['name'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Mill / Company Name (مل یا فرم کا نام)</label>
              <input type="text" name="company_name" class="form-control" placeholder="e.g. Mughal Re-Rolling Mills Ltd." value="<?= htmlspecialchars($editSupp['company_name'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Supplier Code / ID</label>
              <input type="text" name="code" class="form-control" placeholder="Leave empty for auto SUPP-XXXX" value="<?= htmlspecialchars($editSupp['code'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Phone / Mobile Number</label>
              <input type="text" name="phone" class="form-control" placeholder="0300-xxxxxxx" value="<?= htmlspecialchars($editSupp['phone'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">CNIC / NTN Number</label>
              <input type="text" name="cnic" class="form-control" placeholder="NTN / CNIC" value="<?= htmlspecialchars($editSupp['cnic'] ?? '') ?>">
            </div>

            <?php if (!$editSupp): ?>
              <div class="col-12 col-md-4">
                <label class="form-label">Opening Balance (ابتدائی واجب الادا رقم)</label>
                <input type="number" step="0.01" name="opening_balance" class="form-control" placeholder="0.00 (Positive for Payable)" value="0">
              </div>
            <?php endif; ?>

            <div class="col-12 col-md-12">
              <label class="form-label">Mill Address & Location (مل کا پتہ)</label>
              <input type="text" name="address" class="form-control" placeholder="e.g. Sheikhupura Road / G.T. Road, Gujranwala" value="<?= htmlspecialchars($editSupp['address'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-12">
              <label class="form-label">Notes & Remarks</label>
              <input type="text" name="notes" class="form-control" placeholder="Payment terms, bank details, loading point details..." value="<?= htmlspecialchars($editSupp['notes'] ?? '') ?>">
            </div>

            <div class="col-12 text-end mt-4">
              <?php if ($editSupp): ?>
                <a href="suppliers.php" class="btn btn-secondary me-2">Cancel</a>
              <?php endif; ?>
              <button type="submit" class="btn btn-zb-primary px-4">
                <i class="fa-solid fa-save me-1"></i> <?= $editSupp ? 'Update Supplier' : 'Save Supplier Profile (محفوظ کریں)' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
