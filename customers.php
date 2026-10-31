<?php
// customers.php - Customer Khata & Accounts Receivable Directory
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
requirePerm('customers');

$pdo = getPDO();
$pageTitle = 'Customers Directory (کسٹمر کھاتہ رجسٹر)';
$errors = [];

// Handle Add / Edit Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim($_POST['action'] ?? '');
        if ($action === 'save_customer') {
            $custId      = (int)($_POST['customer_id'] ?? 0);
            $customerType = trim($_POST['customer_type'] ?? 'regular');
            $isWalkin    = ($customerType === 'walkin') ? 1 : 0;
            $name        = trim($_POST['name'] ?? '');
            $code        = trim($_POST['code'] ?? '');
            $phone       = trim($_POST['phone'] ?? '');
            $cnic        = trim($_POST['cnic'] ?? '');
            $companyName = trim($_POST['company_name'] ?? '');
            $address     = trim($_POST['address'] ?? '');
            $creditLimit = (float)($_POST['credit_limit'] ?? 0);
            $notes       = trim($_POST['notes'] ?? '');

            if ($name === '') {
                $errors[] = 'Customer name is required.';
            }

            if (empty($errors)) {
                try {
                    if ($custId > 0) {
                        if ($isWalkin) { $creditLimit = 0.0; }
                        $upd = $pdo->prepare("UPDATE parties SET name = ?, code = ?, is_walkin = ?, phone = ?, cnic = ?, company_name = ?, address = ?, credit_limit = ?, notes = ? WHERE id = ? AND type = 'customer'");
                        $upd->execute([$name, $code, $isWalkin, $phone, $cnic, $companyName, $address, $creditLimit, $notes, $custId]);
                        logAudit('update', 'parties', $custId, "Updated customer $name ($code)");
                        setFlash('success', "Customer '$name' updated successfully.");
                    } else {
                        if ($code === '') {
                            $code = generateDocumentNumber('CUST', 'parties', 'code');
                        }
                        $openBal = (float)($_POST['opening_balance'] ?? 0);
                        if ($isWalkin) { $creditLimit = 0.0; $openBal = 0.0; }
                        $ins = $pdo->prepare("INSERT INTO parties (type, is_walkin, code, name, phone, cnic, company_name, address, opening_balance, current_balance, credit_limit, notes) VALUES ('customer', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$isWalkin, $code, $name, $phone, $cnic, $companyName, $address, $openBal, $openBal, $creditLimit, $notes]);
                        $newId = (int)$pdo->lastInsertId();
                        logAudit('create', 'parties', $newId, "Created customer $name ($code) with opening balance $openBal");
                        setFlash('success', "Customer '$name' added successfully.");
                    }
                    header('Location: customers.php');
                    exit;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } elseif ($action === 'save_statement_note') {
            $noteTitle = trim($_POST['customer_statement_note_title'] ?? 'نوٹ برائے کھاتہ دار / پارٹی:');
            $noteUrdu  = trim($_POST['customer_statement_note_urdu'] ?? '');
            $noteEn    = trim($_POST['customer_statement_note_en'] ?? '');

            try {
                $stUpd = $pdo->prepare("INSERT INTO company_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                $stUpd->execute(['customer_statement_note_title', $noteTitle]);
                $stUpd->execute(['customer_statement_note_urdu', $noteUrdu]);
                $stUpd->execute(['customer_statement_note_en', $noteEn]);

                logAudit('update', 'company_settings', 0, "Updated customer statement disclaimer note");
                setFlash('success', "Customer statement disclaimer note saved successfully (گاہک کھاتہ اسٹیٹمنٹ نوٹ محفوظ ہو گیا).");
                header('Location: customers.php#statementNoteCard');
                exit;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

$companySettings = getCompanySettings();

// Search and filter
$search = trim($_GET['search'] ?? '');
$balanceFilter = trim($_GET['balance_filter'] ?? '');

$sql = "SELECT * FROM parties WHERE type = 'customer'";
$params = [];

if ($search !== '') {
    $sql .= " AND (name LIKE ? OR phone LIKE ? OR code LIKE ? OR company_name LIKE ? OR address LIKE ?)";
    $params = array_fill(0, 5, "%$search%");
}

if ($balanceFilter === 'receivable') {
    $sql .= " AND current_balance > 0";
} elseif ($balanceFilter === 'advance') {
    $sql .= " AND current_balance < 0";
} elseif ($balanceFilter === 'zero') {
    $sql .= " AND current_balance = 0";
}

$sql .= " ORDER BY current_balance DESC, name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

// KPIs
$totalReceivables = 0.0;
$totalAdvances = 0.0;
$withBalanceCount = 0;
foreach ($customers as $c) {
    $bal = (float)$c['current_balance'];
    if ($bal > 0) {
        $totalReceivables += $bal;
        $withBalanceCount++;
    } elseif ($bal < 0) {
        $totalAdvances += abs($bal);
    }
}

// Check edit mode
$editCust = null;
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stE = $pdo->prepare("SELECT * FROM parties WHERE id = ? AND type = 'customer'");
    $stE->execute([$editId]);
    $editCust = $stE->fetch();
}
$isWalkInEdit = $editCust ? (!empty($editCust['is_walkin']) || (int)$editCust['id'] === 1 || stripos((string)$editCust['name'], 'walk-in') !== false) : false;

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Customers Directory & Ledgers (کسٹمر کھاتہ رجسٹر)</h4>
        <span class="text-muted" style="font-size: 13px;">Manage client profiles, credit terms, receivables, and WhatsApp payment reminders</span>
      </div>
      <div>
        <a href="#customerFormCard" class="btn btn-zb-primary" onclick="document.getElementById('customerFormCard').scrollIntoView({behavior:'smooth'});">
          <i class="fa-solid fa-user-plus me-1"></i> Add New Customer (نیا کسٹمر)
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
        <div class="kpi-card" style="border-left: 4px solid var(--rd);">
          <div class="kpi-icon" style="background: rgba(224,36,36,0.1); color: var(--rd);"><i class="fa-solid fa-hand-holding-dollar"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalReceivables) ?></div>
          <div class="kpi-label">Total Market Receivables (کل بقایا جات)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--gn);">
          <div class="kpi-icon" style="background: rgba(22,163,74,0.1); color: var(--gn);"><i class="fa-solid fa-money-bill-transfer"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalAdvances) ?></div>
          <div class="kpi-label">Customer Advances / Credits (ایڈوانس وصولی)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--am);">
          <div class="kpi-icon" style="background: rgba(245,166,35,0.1); color: #b7791f;"><i class="fa-solid fa-file-invoice-dollar"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= $withBalanceCount ?></div>
          <div class="kpi-label">Customers with Due Balance (مقروض کسٹمرز)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--nv);">
          <div class="kpi-icon" style="background: rgba(20,48,122,0.1); color: var(--nv);"><i class="fa-solid fa-users"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= count($customers) ?></div>
          <div class="kpi-label">Registered Customers (کل کسٹمرز)</div>
        </div>
      </div>
    </div>

    <!-- Filter & Search Card -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="customers.php" class="row g-3 align-items-end">
          <div class="col-12 col-md-6">
            <label class="form-label">Search Customer (نام، فون یا شہر سے تلاش کریں)</label>
            <input type="text" name="search" class="form-control" placeholder="Search by name, phone (0300...), code or company..." value="<?= htmlspecialchars($search) ?>">
          </div>
          <div class="col-12 col-md-4">
            <label class="form-label">Balance Filter</label>
            <select name="balance_filter" class="form-select">
              <option value="">All Customers (تمام کسٹمرز)</option>
              <option value="receivable" <?= ($balanceFilter === 'receivable') ? 'selected' : '' ?>>Receivable Only (جن سے پیسے لینے ہیں > 0)</option>
              <option value="advance" <?= ($balanceFilter === 'advance') ? 'selected' : '' ?>>Advance Balance Only (< 0)</option>
              <option value="zero" <?= ($balanceFilter === 'zero') ? 'selected' : '' ?>>Zero Balance (Clear)</option>
            </select>
          </div>
          <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-search me-1"></i> Search
            </button>
            <?php if ($search !== '' || $balanceFilter !== ''): ?>
              <a href="customers.php" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Customers List Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Active Customers Directory</h6>
        <span class="badge bg-light text-dark border"><?= count($customers) ?> records</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Code</th>
              <th style="padding: 12px 14px;">Customer / Firm Name</th>
              <th style="padding: 12px 14px;">Contact & Phone</th>
              <th style="padding: 12px 14px;">Address / City</th>
              <th class="text-end" style="padding: 12px 14px;">Credit Limit</th>
              <th class="text-end" style="padding: 12px 14px;">Current Balance (بقایا رقم)</th>
              <th class="text-center" style="padding: 12px 14px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($customers)): ?>
              <tr>
                <td colspan="7" class="text-center py-4 text-muted">No customers found matching your criteria.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($customers as $c): 
                $bal = (float)$c['current_balance'];
                $creditLimit = (float)$c['credit_limit'];
                $isOverLimit = ($creditLimit > 0 && $bal > $creditLimit);
                $cleanPhone = preg_replace('/[^0-9]/', '', (string)$c['phone']);
                if (str_starts_with($cleanPhone, '0')) {
                    $cleanPhone = '92' . substr($cleanPhone, 1);
                }
                $waMsg = urlencode("محترم " . $c['name'] . " صاحب!\nظفر برادرز آئرن مرچنٹس گوجرانوالہ کی طرف سے آپ کے کھاتہ کی کل بقایا رقم " . formatCurrency($bal) . " واجب الادا ہے۔ برائے مہربانی جلد از جلد ادائیگی یقینی بنائیں۔ شکریہ۔");
              ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 600; color: #475569;">
                    <?= htmlspecialchars($c['code'] ?? 'CUST-' . $c['id']) ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                      <span style="font-weight: 600; color: var(--nv); font-size: 14px;">
                        <a href="customer_ledger.php?id=<?= $c['id'] ?>" style="color: inherit; text-decoration: none;">
                          <?= htmlspecialchars($c['name']) ?>
                        </a>
                      </span>
                      <?php if (!empty($c['is_walkin']) || (int)$c['id'] === 1): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size: 10.5px;">
                          <i class="fa-solid fa-person-walking me-1"></i>Walk-in (واک ان)
                        </span>
                      <?php else: ?>
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size: 10.5px;">
                          <i class="fa-solid fa-book-bookmark me-1"></i>Regular (کھاتہ دار)
                        </span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($c['company_name'])): ?>
                      <small class="text-muted"><i class="fa-solid fa-building me-1"></i><?= htmlspecialchars($c['company_name']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div><?= htmlspecialchars($c['phone'] ?? '-') ?></div>
                    <?php if (!empty($c['phone'])): ?>
                      <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= $waMsg ?>" target="_blank" class="badge bg-success text-decoration-none mt-1" style="font-size: 11px;">
                        <i class="fa-brands fa-whatsapp me-1"></i> WhatsApp
                      </a>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px; color: #475569;">
                    <?= htmlspecialchars($c['address'] ?? '-') ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px;">
                    <?= $creditLimit > 0 ? formatCurrency($creditLimit) : '<span class="text-muted">No Limit</span>' ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700;">
                    <?php if ($bal > 0): ?>
                      <span style="color: var(--rd); font-size: 14.5px;"><?= formatCurrency($bal) ?> Dr</span>
                      <?php if ($isOverLimit): ?>
                        <div class="badge bg-danger mt-1" style="font-size: 10px;">Over Limit!</div>
                      <?php endif; ?>
                    <?php elseif ($bal < 0): ?>
                      <span style="color: var(--gn); font-size: 14.5px;"><?= formatCurrency(abs($bal)) ?> Cr (Adv)</span>
                    <?php else: ?>
                      <span class="text-muted">Nil (0.00)</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <div class="btn-group btn-group-sm">
                      <a href="customer_ledger.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary" title="View Ledger Statement (کھاتہ تفصیل)">
                        <i class="fa-solid fa-book-open me-1"></i> Ledger
                      </a>
                      <a href="customers.php?edit=<?= $c['id'] ?>#customerFormCard" class="btn btn-outline-secondary" title="Edit Profile">
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

    <!-- Add / Edit Customer Form Card (Clean, No Modal, Zero Overlap) -->
    <div class="card" id="customerFormCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <?= $editCust ? 'Edit Customer: ' . htmlspecialchars($editCust['name']) : 'Add New Customer Profile (نیا کسٹمر کھاتہ بنائیں)' ?>
        </h6>
        <?php if ($editCust): ?>
          <a href="customers.php" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="customers.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_customer">
          <input type="hidden" name="customer_id" value="<?= $editCust ? (int)$editCust['id'] : 0 ?>">

          <div class="row g-3">
            <div class="col-12">
              <label class="form-label">Customer Type (گاہک کی قسم) <span class="text-danger">*</span></label>
              <div class="d-flex flex-wrap gap-4 align-items-center p-3 rounded-3 border bg-light">
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="customer_type" id="typeRegular" value="regular" <?= (!$isWalkInEdit) ? 'checked' : '' ?> onchange="updateCustomerTypeUI()">
                  <label class="form-check-label fw-bold text-dark" for="typeRegular" style="cursor: pointer;">
                    <i class="fa-solid fa-book-bookmark text-primary me-1"></i> Regular Khata Account (کھاتہ دار / ریگولر کسٹمر)
                  </label>
                  <div class="text-muted small ps-4">Eligible for ledger credit limits and deferred udhaar balance payments.</div>
                </div>
                <div class="form-check">
                  <input class="form-check-input" type="radio" name="customer_type" id="typeWalkin" value="walkin" <?= ($isWalkInEdit) ? 'checked' : '' ?> onchange="updateCustomerTypeUI()">
                  <label class="form-check-label fw-bold text-dark" for="typeWalkin" style="cursor: pointer;">
                    <i class="fa-solid fa-person-walking text-success me-1"></i> Walk-in / Counter Cash (واک ان / نقد گاہک)
                  </label>
                  <div class="text-muted small ps-4">Cash/bank payment only. Credit/udhaar is strictly disabled for walk-in customers.</div>
                </div>
              </div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Customer Name (گاہک کا نام) <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="e.g. Mian Tariq Steel Traders (میاں طارق)" value="<?= htmlspecialchars($editCust['name'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Company / Firm Name (فرم یا دکان کا نام)</label>
              <input type="text" name="company_name" class="form-control" placeholder="e.g. Tariq & Sons Construction Co." value="<?= htmlspecialchars($editCust['company_name'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Customer Code / ID</label>
              <input type="text" name="code" class="form-control" placeholder="Leave empty for auto CUST-XXXX" value="<?= htmlspecialchars($editCust['code'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">WhatsApp / Phone Number</label>
              <input type="text" name="phone" class="form-control" placeholder="e.g. 0300-1234567" value="<?= htmlspecialchars($editCust['phone'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">CNIC / NTN Number</label>
              <input type="text" name="cnic" class="form-control" placeholder="34101-xxxxxxx-x" value="<?= htmlspecialchars($editCust['cnic'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Credit Limit (ادھار کی حد - روپے)</label>
              <input type="number" step="0.01" name="credit_limit" class="form-control" placeholder="0.00" value="<?= htmlspecialchars((string)($editCust['credit_limit'] ?? '0')) ?>">
            </div>

            <?php if (!$editCust): ?>
              <div class="col-12 col-md-3">
                <label class="form-label">Opening Balance (ابتدائی بقایا رقم)</label>
                <input type="number" step="0.01" name="opening_balance" class="form-control" placeholder="0.00 (Positive for Receivable)" value="0">
              </div>
            <?php endif; ?>

            <div class="col-12 col-md-<?= $editCust ? '6' : '12' ?>">
              <label class="form-label">Shop / Project Address & City (پتہ اور شہر)</label>
              <input type="text" name="address" class="form-control" placeholder="e.g. G.T. Road, Gujranwala / Shop # 14 Steel Market" value="<?= htmlspecialchars($editCust['address'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-12">
              <label class="form-label">Notes & Remarks (خصوصی ہدایات یا ضمانت)</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. Guaranteed by Haji Aslam, 15 days credit payment cycle" value="<?= htmlspecialchars($editCust['notes'] ?? '') ?>">
            </div>

            <div class="col-12 text-end mt-4">
              <?php if ($editCust): ?>
                <a href="customers.php" class="btn btn-secondary me-2">Cancel</a>
              <?php endif; ?>
              <button type="submit" class="btn btn-zb-primary px-4">
                <i class="fa-solid fa-save me-1"></i> <?= $editCust ? 'Update Customer' : 'Save Customer Profile (محفوظ کریں)' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- Customer Statement Disclaimer Note Card (Editable by User) -->
    <div class="card mt-4" id="statementNoteCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
          <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
            <i class="fa-solid fa-file-contract me-2 text-primary"></i> Customer Statement Disclaimer Note (گاہک کھاتہ اسٹیٹمنٹ نوٹ)
          </h6>
          <span class="text-muted" style="font-size: 12.5px;">This disclaimer note will be printed at the bottom of all customer ledger statements (پرنٹ گاہک کھاتہ اسٹیٹمنٹ کے نیچے پرنٹ ہوگا).</span>
        </div>
        <span class="badge bg-light text-primary border" style="font-size: 11.5px;">
          <i class="fa-solid fa-print me-1"></i> Customer Ledger Print
        </span>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="customers.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_statement_note">
          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Note Box Header (عنوان نوٹ)</label>
              <input type="text" name="customer_statement_note_title" class="form-control ur text-end" dir="rtl" style="font-size: 15px;"
                     value="<?= htmlspecialchars($companySettings['customer_statement_note_title'] ?? 'نوٹ برائے کھاتہ دار / پارٹی:') ?>" 
                     placeholder="نوٹ برائے کھاتہ دار / پارٹی:">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">English Verification &amp; Contact Line (انگریزی رابطہ لائن)</label>
              <input type="text" name="customer_statement_note_en" class="form-control" style="font-size: 13px;"
                     value="<?= htmlspecialchars($companySettings['customer_statement_note_en'] ?? 'For invoice verification, please contact accounts department at 0300-9641483 · 0300-7499499.') ?>" 
                     placeholder="For invoice verification, please contact accounts department at 0300-9641483 · 0300-7499499.">
            </div>
            <div class="col-12">
              <label class="form-label">Urdu Disclaimer &amp; Instructions (اردو ہدایات / اختلافی نوٹس)</label>
              <textarea name="customer_statement_note_urdu" class="form-control ur text-end" dir="rtl" rows="3" style="line-height: 1.8; font-size: 14.5px;"><?= htmlspecialchars($companySettings['customer_statement_note_urdu'] ?? 'کسی بھی قسم کے فرق یا تضاد کی صورت میں 7 یوم کے اندر اندر دفتر سے رابطہ فرما کر تصدیق کر لیں۔ بصورت دیگر یہ گوشوارہ حساب قطعی اور درست تسلیم کیا جائے گا۔') ?></textarea>
              <div class="form-text text-muted" style="font-size: 12px;">
                <i class="fa-solid fa-circle-info text-primary me-1"></i> یہ تحریر گاہک کے کھاتہ پرنٹ کے نیچے پرنٹ ہو گی۔
              </div>
            </div>
            <div class="col-12 text-end">
              <button type="submit" class="btn btn-zb-primary px-4">
                <i class="fa-solid fa-save me-1"></i> Save Statement Note (نوٹ محفوظ کریں)
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

<script>
function updateCustomerTypeUI() {
  const isWalkIn = document.getElementById('typeWalkin') ? document.getElementById('typeWalkin').checked : false;
  const creditInput = document.querySelector('input[name="credit_limit"]');
  const openBalInput = document.querySelector('input[name="opening_balance"]');
  
  if (creditInput) {
    if (isWalkIn) {
      creditInput.value = '0.00';
      creditInput.readOnly = true;
      creditInput.classList.add('bg-light');
    } else {
      creditInput.readOnly = false;
      creditInput.classList.remove('bg-light');
    }
  }

  if (openBalInput) {
    if (isWalkIn) {
      openBalInput.value = '0.00';
      openBalInput.readOnly = true;
      openBalInput.classList.add('bg-light');
    } else {
      openBalInput.readOnly = false;
      openBalInput.classList.remove('bg-light');
    }
  }
}
document.addEventListener('DOMContentLoaded', updateCustomerTypeUI);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
