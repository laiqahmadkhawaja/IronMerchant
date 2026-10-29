<?php
// expenses.php - Operating Expenses & Expense Voucher Management
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
requirePerm('expenses');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Operating Expenses (کاروباری اخراجات)';
$errors = [];

// Handle Add Expense Category (نئی کیٹگری شامل کریں)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_expense_category') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $catName = trim($_POST['category_name'] ?? '');
        $coaId   = (int)($_POST['coa_id'] ?? 39);

        if ($catName === '') {
            $errors[] = 'Category name is required.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM expense_categories WHERE LOWER(name) = LOWER(?)");
            $chk->execute([$catName]);
            if ($chk->fetch()) {
                $errors[] = "An expense category named '$catName' already exists.";
            } else {
                if ($coaId <= 0) {
                    $coaId = 39; // Default 5200 General & Miscellaneous Expenses
                }
                $ins = $pdo->prepare("INSERT INTO expense_categories (name, coa_id, is_active) VALUES (?, ?, 1)");
                $ins->execute([$catName, $coaId]);
                $newCatId = (int)$pdo->lastInsertId();
                logAudit('create', 'expense_categories', $newCatId, "Created expense category '$catName'");
                setFlash('success', "Expense category '$catName' added successfully.");
                header("Location: expenses.php");
                exit;
            }
        }
    }
}

// Handle New Expense Entry
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_expense') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $expDate     = trim($_POST['expense_date'] ?? date('Y-m-d'));
        $catId       = (int)($_POST['category_id'] ?? 0);
        $payee       = trim($_POST['payee'] ?? '');
        $amount      = (float)($_POST['amount'] ?? 0);
        $payAccId    = (int)($_POST['payment_account_id'] ?? 0);
        $payMethod   = trim($_POST['payment_method'] ?? 'Cash');
        $refNo       = trim($_POST['reference_no'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');

        if ($catId <= 0) {
            $errors[] = 'Please select an expense category.';
        }
        if ($amount <= 0) {
            $errors[] = 'Expense amount must be greater than zero.';
        }
        if ($payAccId <= 0) {
            $errors[] = 'Please select the paying Cash or Bank account.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Fetch Category and COA
                $stCat = $pdo->prepare("SELECT id, name, coa_id FROM expense_categories WHERE id = ?");
                $stCat->execute([$catId]);
                $cat = $stCat->fetch();
                if (!$cat) {
                    throw new Exception("Expense category not found.");
                }

                // Lock paying financial account
                $stAcc = $pdo->prepare("SELECT id, account_name, coa_id, current_balance FROM financial_accounts WHERE id = ? FOR UPDATE");
                $stAcc->execute([$payAccId]);
                $acc = $stAcc->fetch();
                if (!$acc) {
                    throw new Exception("Paying cash/bank account not found.");
                }

                $expenseNo = generateDocumentNumber('EXP', 'expenses', 'expense_no');
                $voucherNo = generateDocumentNumber('EV', 'vouchers', 'voucher_no');

                // Double Entry Journal:
                // Debit: Expense COA Account (cat.coa_id)
                // Credit: Financial Account COA (acc.coa_id)
                $jLines = [
                    [
                        'account_id'  => (int)$cat['coa_id'],
                        'debit'       => $amount,
                        'credit'      => 0.0,
                        'description' => "Expense [{$cat['name']}]: $payee - $notes"
                    ],
                    [
                        'account_id'  => (int)$acc['coa_id'],
                        'debit'       => 0.0,
                        'credit'      => $amount,
                        'description' => "Paid from {$acc['account_name']} ($voucherNo)"
                    ]
                ];

                $journalId = postJournalEntry($pdo, [
                    'entry_no'    => getNextDocNo('JV', 'journal_entries', 'entry_no'),
                    'entry_date'  => $expDate,
                    'source_type' => 'expense',
                    'narration'   => "Operating expense: {$cat['name']} paid to $payee",
                    'created_by'  => $currentUser['id'] ?? null
                ], $jLines);

                // Insert into vouchers
                $insV = $pdo->prepare("INSERT INTO vouchers (voucher_no, voucher_type, voucher_date, account_id, amount, payment_mode, cheque_no, narration, journal_id, created_by) VALUES (?, 'EV', ?, ?, ?, ?, ?, ?, ?, ?)");
                $insV->execute([$voucherNo, $expDate, $payAccId, $amount, $payMethod, $refNo, "Paid to $payee: $notes", $journalId, $currentUser['id'] ?? null]);
                $voucherId = (int)$pdo->lastInsertId();

                // Insert into expenses
                $insE = $pdo->prepare("INSERT INTO expenses (expense_no, expense_date, category_id, coa_id, payee, amount, payment_account_id, payment_method, reference_no, notes, journal_id, voucher_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insE->execute([$expenseNo, $expDate, $catId, (int)$cat['coa_id'], $payee, $amount, $payAccId, $payMethod, $refNo, $notes, $journalId, $voucherId, $currentUser['id'] ?? null]);
                $expId = (int)$pdo->lastInsertId();

                $pdo->commit();
                logAudit('create', 'expenses', $expId, "Recorded expense $expenseNo ($voucherNo) of Rs $amount under {$cat['name']}");
                setFlash('success', "Expense $expenseNo (Voucher: $voucherNo) of Rs " . number_format($amount, 2) . " posted successfully.");
                header('Location: expenses.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Fetch categories & financial accounts
$categories = $pdo->query("SELECT * FROM expense_categories WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$finAccounts = $pdo->query("SELECT id, account_name, account_type, current_balance FROM financial_accounts WHERE is_active = 1 ORDER BY account_type, account_name")->fetchAll();
$expenseCoaAccounts = $pdo->query("SELECT id, code, name FROM chart_of_accounts WHERE type = 'Expense' AND is_active = 1 ORDER BY code ASC")->fetchAll();

// Filters
$startDate = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate   = trim($_GET['end_date'] ?? date('Y-m-d'));
$catFilter = !empty($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$accFilter = !empty($_GET['account_id']) ? (int)$_GET['account_id'] : 0;

$sql = "SELECT e.*, c.name AS category_name, fa.account_name, u.name AS user_name, v.voucher_no
        FROM expenses e
        JOIN expense_categories c ON e.category_id = c.id
        LEFT JOIN financial_accounts fa ON e.payment_account_id = fa.id
        LEFT JOIN users u ON e.created_by = u.id
        LEFT JOIN vouchers v ON e.voucher_id = v.id
        WHERE e.expense_date BETWEEN ? AND ?";
$params = [$startDate, $endDate];

if ($catFilter > 0) {
    $sql .= " AND e.category_id = ?";
    $params[] = $catFilter;
}
if ($accFilter > 0) {
    $sql .= " AND e.payment_account_id = ?";
    $params[] = $accFilter;
}

$sql .= " ORDER BY e.expense_date DESC, e.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll();

// KPIs
$totalMonthExpenses = 0.0;
$todayExpenses = 0.0;
$todayStr = date('Y-m-d');
$catTotals = [];

foreach ($expenses as $e) {
    $amt = (float)$e['amount'];
    $totalMonthExpenses += $amt;
    if ($e['expense_date'] === $todayStr) {
        $todayExpenses += $amt;
    }
    $cName = $e['category_name'];
    $catTotals[$cName] = ($catTotals[$cName] ?? 0.0) + $amt;
}

arsort($catTotals);
$topCat = !empty($catTotals) ? key($catTotals) . ' (' . formatCurrency(current($catTotals)) . ')' : 'None';

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Operating Expenses & Vouchers (کاروباری اخراجات)</h4>
        <span class="text-muted" style="font-size: 13px;">Yard rent, electricity, loading labour, vehicle fuel, and daily office expenditure</span>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addExpenseCategoryModal">
          <i class="fa-solid fa-tags me-1"></i> Add Category (نئی کیٹگری)
        </button>
        <a href="#expenseFormCard" class="btn btn-zb-primary" onclick="document.getElementById('expenseFormCard').scrollIntoView({behavior:'smooth'});">
          <i class="fa-solid fa-plus me-1"></i> Record New Expense (نیا خرچہ درج کریں)
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

    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="kpi-card" style="border-left: 4px solid var(--rd);">
          <div class="kpi-icon" style="background: rgba(224,36,36,0.1); color: var(--rd);"><i class="fa-solid fa-receipt"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalMonthExpenses) ?></div>
          <div class="kpi-label">Filtered Period Expenses (کل اخراجات)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="kpi-card" style="border-left: 4px solid var(--am);">
          <div class="kpi-icon" style="background: rgba(245,166,35,0.1); color: #b7791f;"><i class="fa-solid fa-calendar-day"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($todayExpenses) ?></div>
          <div class="kpi-label">Today's Expenses (آج کا کل خرچ)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-4">
        <div class="kpi-card" style="border-left: 4px solid var(--nv);">
          <div class="kpi-icon" style="background: rgba(20,48,122,0.1); color: var(--nv);"><i class="fa-solid fa-chart-pie"></i></div>
          <div class="kpi-value" style="font-size: 16px; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($topCat) ?></div>
          <div class="kpi-label">Top Expense Category (سب سے بڑا خرچہ)</div>
        </div>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="expenses.php" class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">From Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">To Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <option value="">All Categories (تمام اخراجات)</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($catFilter === (int)$cat['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <a href="expenses.php" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
          </div>
        </form>
      </div>
    </div>

    <!-- Expenses Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Expenses Journal & Vouchers</h6>
        <span class="badge bg-light text-dark border"><?= count($expenses) ?> records</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Expense #</th>
              <th style="padding: 12px 14px;">Date</th>
              <th style="padding: 12px 14px;">Category (مد / کھاتہ)</th>
              <th style="padding: 12px 14px;">Paid To (وصول کنندہ)</th>
              <th style="padding: 12px 14px;">Paid From (کھاتہ)</th>
              <th class="text-end" style="padding: 12px 14px;">Amount (روپے)</th>
              <th style="padding: 12px 14px;">Ref & Notes</th>
              <th style="padding: 12px 14px;">Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($expenses)): ?>
              <tr>
                <td colspan="8" class="text-center py-4 text-muted">No expense records found for the selected dates.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($expenses as $e): ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 600; color: var(--nv);">
                    <?= htmlspecialchars($e['expense_no']) ?>
                    <?php if (!empty($e['voucher_no'])): ?>
                      <div class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($e['voucher_no']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($e['expense_date']) ?></td>
                  <td style="padding: 12px 14px;">
                    <span class="badge bg-light text-dark border" style="font-size: 12.5px;">
                      <?= htmlspecialchars($e['category_name']) ?>
                    </span>
                  </td>
                  <td style="padding: 12px 14px; font-weight: 600; color: #1e293b;">
                    <?= htmlspecialchars($e['payee'] ?? '-') ?>
                  </td>
                  <td style="padding: 12px 14px; color: #475569;">
                    <?= htmlspecialchars($e['account_name'] ?? 'Cash') ?>
                    <small class="text-muted d-block" style="font-size: 11px;"><?= htmlspecialchars($e['payment_method']) ?></small>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: var(--rd); font-size: 14.5px;">
                    <?= formatCurrency((float)$e['amount']) ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div><?= htmlspecialchars($e['notes'] ?? '-') ?></div>
                    <?php if (!empty($e['reference_no'])): ?>
                      <small class="text-muted">Ref: <?= htmlspecialchars($e['reference_no']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px; color: #64748b; font-size: 12.5px;">
                    <?= htmlspecialchars($e['user_name'] ?? 'System') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot style="background: #f8fafc; border-top: 2px solid var(--bd); font-weight: 700;">
            <tr>
              <td colspan="5" class="text-end" style="padding: 12px 14px;">Total Filtered Expenses:</td>
              <td class="text-end" style="padding: 12px 14px; color: var(--rd); font-size: 15px;">
                <?= formatCurrency($totalMonthExpenses) ?>
              </td>
              <td colspan="2"></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <!-- Record Expense Form Card (No Modal, Zero Overlap) -->
    <div class="card" id="expenseFormCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <i class="fa-solid fa-receipt me-2"></i> Record Operating Expense (نیا خرچہ واؤچر درج کریں)
        </h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="expenses.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_expense">

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Expense Date <span class="text-danger">*</span></label>
              <input type="date" name="expense_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-12 col-md-5">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label mb-0">Expense Category (اخراجات کی مد) <span class="text-danger">*</span></label>
                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" style="font-size: 12px;" data-bs-toggle="modal" data-bs-target="#addExpenseCategoryModal">
                  <i class="fa-solid fa-plus-circle me-1"></i>+ New Category
                </button>
              </div>
              <select name="category_id" class="form-select" required>
                <option value="">-- Choose Category --</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Paid To / Payee (کس کو رقم ادا کی؟)</label>
              <input type="text" name="payee" class="form-control" placeholder="e.g. GEPCO / Yard Owner / Crane Driver">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Amount (رقم - روپے) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" name="amount" class="form-control" required placeholder="0.00">
              </div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Paid From Account (کس کھاتے سے ادائیگی کی؟) <span class="text-danger">*</span></label>
              <select name="payment_account_id" class="form-select" required>
                <option value="">-- Select Cash/Bank Account --</option>
                <?php foreach ($finAccounts as $fa): ?>
                  <option value="<?= $fa['id'] ?>">
                    <?= htmlspecialchars($fa['account_name']) ?> (<?= htmlspecialchars($fa['account_type']) ?>) - Balance: <?= formatCurrency((float)$fa['current_balance']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Payment Mode</label>
              <select name="payment_method" class="form-select">
                <option value="Cash">Cash (نقد)</option>
                <option value="Online">Online Bank Transfer</option>
                <option value="Cheque">Bank Cheque</option>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Bill / Receipt / Cheque #</label>
              <input type="text" name="reference_no" class="form-control" placeholder="e.g. Bill # 938210">
            </div>

            <div class="col-12 col-md-12">
              <label class="form-label">Notes & Description (تفصیلات)</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. Yard electric bill payment for current month">
            </div>

            <div class="col-12 text-end mt-4">
              <button type="submit" class="btn btn-zb-primary px-4 py-2" onclick="return confirm('Confirm posting this expense?');">
                <i class="fa-solid fa-check me-1"></i> Post Expense Voucher (خرچہ محفوظ کریں)
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

<!-- Modal: Add Expense Category (اخراجات کی نئی قسم شامل کریں) -->
<div class="modal fade" id="addExpenseCategoryModal" tabindex="-1" aria-labelledby="addExpenseCategoryModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 12px; overflow: hidden; border: 1px solid var(--bd);">
      <div class="modal-header bg-white py-3">
        <h6 class="modal-title fw-bold" id="addExpenseCategoryModalLabel" style="color: var(--nv);">
          <i class="fa-solid fa-tags me-2 text-primary"></i> Add Expense Category (اخراجات کی نئی قسم)
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="expenses.php">
        <?= getCSRFInput() ?>
        <input type="hidden" name="action" value="save_expense_category">
        <div class="modal-body p-4">
          <div class="mb-3">
            <label class="form-label">Category Name (مد کا نام) <span class="text-danger">*</span></label>
            <input type="text" name="category_name" class="form-control" required placeholder="e.g. Generator Diesel / جنریٹر ڈیزل و فیول">
          </div>
          <div class="mb-3">
            <label class="form-label">Accounting Ledger Account (کھاتہ) <span class="text-muted">(Optional)</span></label>
            <select name="coa_id" class="form-select">
              <?php foreach ($expenseCoaAccounts as $acc): ?>
                <option value="<?= $acc['id'] ?>" <?= ((int)$acc['id'] === 39) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($acc['code']) ?> - <?= htmlspecialchars($acc['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <div class="form-text" style="font-size: 11.5px;">Auto-defaults to 5200 General & Miscellaneous Expenses if left unchanged.</div>
          </div>
        </div>
        <div class="modal-footer bg-light py-2 px-4">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-zb-primary btn-sm px-3">
            <i class="fa-solid fa-check me-1"></i> Save Category (محفوظ کریں)
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
