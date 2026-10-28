<?php
// returns.php - Sales & Purchase Returns with Perpetual Weighted-Average Costing
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
requirePerm('returns');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Returns & Credit Notes (واپسی مال / ریٹرن کھاتہ)';
$errors = [];

// Handle POST New Return
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_return') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $returnType  = trim($_POST['return_type'] ?? 'sale_return'); // 'sale_return' or 'purchase_return'
        $returnDate  = trim($_POST['return_date'] ?? date('Y-m-d'));
        $partyId     = (int)($_POST['party_id'] ?? 0);
        $itemId      = (int)($_POST['item_id'] ?? 0);
        $qty         = (float)($_POST['qty'] ?? 0);
        $unitRate    = (float)($_POST['unit_rate'] ?? 0);
        $deductions  = (float)($_POST['deduction_charges'] ?? 0);
        $refundType  = trim($_POST['refund_type'] ?? 'credit_to_khata');
        $accountId   = (int)($_POST['financial_account_id'] ?? 0);
        $refNo       = trim($_POST['reference_no'] ?? '');
        $reason      = trim($_POST['reason'] ?? '');
        $notes       = trim($_POST['notes'] ?? '');

        // Validation
        if ($partyId <= 0) {
            $errors[] = ($returnType === 'sale_return') ? 'Please select a customer.' : 'Please select a supplier.';
        }
        if ($itemId <= 0) {
            $errors[] = 'Please select an inventory item.';
        }
        if ($qty <= 0) {
            $errors[] = 'Return quantity must be greater than zero.';
        }
        if ($unitRate <= 0) {
            $errors[] = 'Return rate must be greater than zero.';
        }
        if ($refundType !== 'credit_to_khata' && $accountId <= 0) {
            $errors[] = 'Please select a cash or bank account for cash refund.';
        }

        $grossAmount = round($qty * $unitRate, 2);
        $netAmount   = round(max(0.0, $grossAmount - $deductions), 2);

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1. Fetch & lock item
                $stItem = $pdo->prepare("SELECT id, name, unit, cost_price, sale_price, stock_qty FROM items WHERE id = ? FOR UPDATE");
                $stItem->execute([$itemId]);
                $item = $stItem->fetch();
                if (!$item) {
                    throw new Exception("Inventory item not found.");
                }

                // 2. Fetch & lock party
                $stParty = $pdo->prepare("SELECT id, name, type, current_balance FROM parties WHERE id = ? FOR UPDATE");
                $stParty->execute([$partyId]);
                $party = $stParty->fetch();
                if (!$party) {
                    throw new Exception("Party record not found.");
                }

                // 3. Fetch financial account if cash/bank refund
                $finAccount = null;
                if ($refundType !== 'credit_to_khata') {
                    $stAcc = $pdo->prepare("SELECT id, account_name, coa_id, current_balance FROM financial_accounts WHERE id = ? FOR UPDATE");
                    $stAcc->execute([$accountId]);
                    $finAccount = $stAcc->fetch();
                    if (!$finAccount) {
                        throw new Exception("Paying/Receiving financial account not found.");
                    }
                }

                $prefix = ($returnType === 'sale_return') ? 'SR' : 'PR';
                $returnNo = generateDocumentNumber($prefix, 'returns', 'return_no');
                $voucherNo = generateDocumentNumber('JV', 'journal_entries', 'entry_no');

                // 4. Double-Entry Accounting Lines
                $jLines = [];
                $journalId = null;

                if ($returnType === 'sale_return') {
                    // Customer returns goods to us:
                    // Relieve sales revenue: Debit Sales Revenue (or Discount/Contra-Revenue)
                    $jLines[] = [
                        'account_id'  => COA_SALES_REVENUE,
                        'party_id'    => null,
                        'debit'       => $netAmount,
                        'credit'      => 0.0,
                        'description' => "Sales Return: {$item['name']} from {$party['name']} ($returnNo)"
                    ];

                    // Refund destination:
                    if ($refundType === 'credit_to_khata') {
                        // Credit Customer Account (COA_RECEIVABLE) -> reduces customer debt!
                        $jLines[] = [
                            'account_id'  => COA_RECEIVABLE,
                            'party_id'    => $partyId,
                            'debit'       => 0.0,
                            'credit'      => $netAmount,
                            'description' => "Credit Note adjustment for return: {$party['name']} ($returnNo)"
                        ];
                    } else {
                        // Cash / Bank refund -> credit cash/bank account!
                        $jLines[] = [
                            'account_id'  => (int)$finAccount['coa_id'],
                            'party_id'    => null,
                            'debit'       => 0.0,
                            'credit'      => $netAmount,
                            'description' => "Cash refund disbursed to {$party['name']} via {$finAccount['account_name']} ($returnNo)"
                        ];
                    }

                    // Inventory & COGS Restoration at Weighted-Average Cost:
                    $itemCostRate = (float)$item['cost_price'];
                    $cogsRestored = round($qty * $itemCostRate, 2);

                    if ($cogsRestored > 0) {
                        // Debit Inventory (Asset restores)
                        $jLines[] = [
                            'account_id'  => COA_INVENTORY,
                            'party_id'    => null,
                            'debit'       => $cogsRestored,
                            'credit'      => 0.0,
                            'description' => "Inventory restored at weighted cost: {$qty} {$item['unit']} @ {$itemCostRate}"
                        ];
                        // Credit COGS (Reduces cost of goods sold expense)
                        $jLines[] = [
                            'account_id'  => COA_COGS,
                            'party_id'    => null,
                            'debit'       => 0.0,
                            'credit'      => $cogsRestored,
                            'description' => "COGS relief on customer return ($returnNo)"
                        ];
                    }

                    // Post balanced journal
                    $journalId = postJournalEntry($pdo, [
                        'entry_no'    => $voucherNo,
                        'entry_date'  => $returnDate,
                        'source_type' => 'voucher',
                        'narration'   => "Sales Return #$returnNo from {$party['name']}: $reason",
                        'created_by'  => $currentUser['id'] ?? null
                    ], $jLines);

                    // Insert returns master
                    $insRet = $pdo->prepare("
                        INSERT INTO returns (
                            return_no, return_type, return_date, party_id, reference_no,
                            total_qty, subtotal, deduction_charges, net_amount,
                            refund_type, account_id, journal_id, status, reason, notes, created_by
                        ) VALUES (?, 'sale_return', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?, ?)
                    ");
                    $insRet->execute([
                        $returnNo, $returnDate, $partyId, $refNo,
                        $qty, $grossAmount, $deductions, $netAmount,
                        $refundType, ($refundType === 'credit_to_khata' ? null : $accountId),
                        $journalId, $reason, $notes, $currentUser['id'] ?? null
                    ]);
                    $retId = (int)$pdo->lastInsertId();

                    // Insert return item
                    $insRetItem = $pdo->prepare("
                        INSERT INTO return_items (return_id, item_id, qty, unit_rate, line_total, cost_price, total_cost)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insRetItem->execute([
                        $retId, $itemId, $qty, $unitRate, $grossAmount, $itemCostRate, $cogsRestored
                    ]);

                    // Update stock & Perpetual Weighted-Average Cost
                    receiveSaleReturnItem($pdo, $itemId, $qty, $unitRate, $retId, $returnDate, $currentUser['id'] ?? null);

                } else {
                    // Supplier purchase return: We return defective/excess steel back to mill
                    if ($qty > (float)$item['stock_qty']) {
                        throw new Exception("Cannot return {$qty} {$item['unit']}. Available stock is only " . formatQty($item['stock_qty'], $item['unit']));
                    }

                    // Relieve supplier payable: Debit Accounts Payable (COA_PAYABLE) -> reduces our liability to mill!
                    if ($refundType === 'credit_to_khata') {
                        $jLines[] = [
                            'account_id'  => COA_PAYABLE,
                            'party_id'    => $partyId,
                            'debit'       => $netAmount,
                            'credit'      => 0.0,
                            'description' => "Purchase Return Debit Note: {$item['name']} to {$party['name']} ($returnNo)"
                        ];
                    } else {
                        // Cash / Bank refund received from supplier
                        $jLines[] = [
                            'account_id'  => (int)$finAccount['coa_id'],
                            'party_id'    => null,
                            'debit'       => $netAmount,
                            'credit'      => 0.0,
                            'description' => "Cash refund received from mill {$party['name']} into {$finAccount['account_name']} ($returnNo)"
                        ];
                    }

                    // Relieve inventory: Credit Inventory
                    $jLines[] = [
                        'account_id'  => COA_INVENTORY,
                        'party_id'    => null,
                        'debit'       => 0.0,
                        'credit'      => $netAmount,
                        'description' => "Inventory relief on supplier return: {$qty} {$item['unit']} @ {$unitRate}"
                    ];

                    // Post balanced journal
                    $journalId = postJournalEntry($pdo, [
                        'entry_no'    => $voucherNo,
                        'entry_date'  => $returnDate,
                        'source_type' => 'voucher',
                        'narration'   => "Purchase Return #$returnNo to {$party['name']}: $reason",
                        'created_by'  => $currentUser['id'] ?? null
                    ], $jLines);

                    // Insert returns master
                    $insRet = $pdo->prepare("
                        INSERT INTO returns (
                            return_no, return_type, return_date, party_id, reference_no,
                            total_qty, subtotal, deduction_charges, net_amount,
                            refund_type, account_id, journal_id, status, reason, notes, created_by
                        ) VALUES (?, 'purchase_return', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'posted', ?, ?, ?)
                    ");
                    $insRet->execute([
                        $returnNo, $returnDate, $partyId, $refNo,
                        $qty, $grossAmount, $deductions, $netAmount,
                        $refundType, ($refundType === 'credit_to_khata' ? null : $accountId),
                        $journalId, $reason, $notes, $currentUser['id'] ?? null
                    ]);
                    $retId = (int)$pdo->lastInsertId();

                    // Insert return item
                    $insRetItem = $pdo->prepare("
                        INSERT INTO return_items (return_id, item_id, qty, unit_rate, line_total, cost_price, total_cost)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insRetItem->execute([
                        $retId, $itemId, $qty, $unitRate, $grossAmount, (float)$item['cost_price'], $netAmount
                    ]);

                    // Relieve inventory stock & moving average
                    relievePurchaseReturnItem($pdo, $itemId, $qty, $unitRate, $retId, $returnDate, $currentUser['id'] ?? null);
                }

                $pdo->commit();
                logAudit('create', 'returns', $retId, "Processed $returnType $returnNo of Rs $netAmount for {$party['name']}");
                setFlash('success', "Return record $returnNo for Rs " . number_format($netAmount, 2) . " posted successfully. Inventory & Khata updated.");
                header("Location: returns.php");
                exit;

            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Filters
$filterType = trim($_GET['type'] ?? 'all');
$startDate  = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate    = trim($_GET['end_date'] ?? date('Y-m-d'));
$search     = trim($_GET['search'] ?? '');

$query = "
    SELECT r.*, p.name AS party_name, p.type AS party_type, p.phone AS party_phone,
           ri.item_id, ri.qty AS item_qty, ri.unit_rate AS item_rate, i.name AS item_name, i.unit AS item_unit,
           u.name AS user_name
    FROM returns r
    JOIN parties p ON r.party_id = p.id
    LEFT JOIN return_items ri ON r.id = ri.return_id
    LEFT JOIN items i ON ri.item_id = i.id
    LEFT JOIN users u ON r.created_by = u.id
    WHERE r.return_date BETWEEN ? AND ?
";
$params = [$startDate, $endDate];

if ($filterType === 'sale_return' || $filterType === 'purchase_return') {
    $query .= " AND r.return_type = ?";
    $params[] = $filterType;
}

if (!empty($search)) {
    $query .= " AND (r.return_no LIKE ? OR p.name LIKE ? OR r.reference_no LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$query .= " ORDER BY r.id DESC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$returnsList = $stmt->fetchAll();

// Metrics
$totalSaleReturnAmount = 0.0;
$totalPurchaseReturnAmount = 0.0;
$totalReturnWeight = 0.0;

foreach ($returnsList as $r) {
    if ($r['return_type'] === 'sale_return') {
        $totalSaleReturnAmount += (float)$r['net_amount'];
    } else {
        $totalPurchaseReturnAmount += (float)$r['net_amount'];
    }
    $totalReturnWeight += (float)$r['total_qty'];
}

// Fetch active customers, suppliers, items, financial accounts for the entry forms
$customers = $pdo->query("SELECT id, name, phone, current_balance FROM parties WHERE type = 'customer' AND is_active = 1 ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT id, name, phone, current_balance FROM parties WHERE type = 'supplier' AND is_active = 1 ORDER BY name")->fetchAll();
$itemsList = $pdo->query("SELECT id, name, item_code, unit, stock_qty, cost_price, sale_price FROM items WHERE is_active = 1 ORDER BY name")->fetchAll();
$finAccounts = $pdo->query("SELECT id, account_name, account_type, current_balance FROM financial_accounts WHERE is_active = 1 ORDER BY account_type, account_name")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

    <!-- Top Action Header -->
    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-0" style="font-weight: 700; color: var(--nv);">Returns &amp; Credit Notes (واپسی مال و کریڈٹ نوٹس)</h4>
        <span class="text-muted" style="font-size: 13px;">Customer Sales Returns, Supplier Purchases Returns &amp; Perpetual Weighted-Average Costing</span>
      </div>
      <div class="d-flex gap-2">
        <a href="#newReturnCard" class="btn btn-zb-gold" onclick="document.getElementById('newReturnCard').scrollIntoView({behavior:'smooth'});">
          <i class="fa-solid fa-plus me-1"></i> Record Return (نیا اندراج واپسی)
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

    <!-- Summary KPI Cards -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-md-4">
        <div class="card p-3" style="border: 1px solid var(--bd); border-left: 4px solid #0284c7; border-radius: 8px;">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <span class="text-muted small text-uppercase fw-bold">Customer Sale Returns</span>
              <h4 class="mb-0 fw-bold text-primary mt-1"><?= formatMoney($totalSaleReturnAmount) ?></h4>
              <small class="text-muted">گاہک سے واپس آنے والا مال</small>
            </div>
            <div style="font-size: 26px; color: #0284c7; opacity: 0.8;"><i class="fa-solid fa-rotate-left"></i></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="card p-3" style="border: 1px solid var(--bd); border-left: 4px solid #16a34a; border-radius: 8px;">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <span class="text-muted small text-uppercase fw-bold">Supplier Purchase Returns</span>
              <h4 class="mb-0 fw-bold text-success mt-1"><?= formatMoney($totalPurchaseReturnAmount) ?></h4>
              <small class="text-muted">مل یا سپلائر کو واپسی</small>
            </div>
            <div style="font-size: 26px; color: #16a34a; opacity: 0.8;"><i class="fa-solid fa-truck-arrow-right"></i></div>
          </div>
        </div>
      </div>
      <div class="col-12 col-md-4">
        <div class="card p-3" style="border: 1px solid var(--bd); border-left: 4px solid #f59e0b; border-radius: 8px;">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <span class="text-muted small text-uppercase fw-bold">Total Weight Returned</span>
              <h4 class="mb-0 fw-bold text-dark mt-1 font-num"><?= number_format($totalReturnWeight, 2) ?> kg</h4>
              <small class="text-muted">کل واپس شدہ اسٹیل کا وزن</small>
            </div>
            <div style="font-size: 26px; color: #f59e0b; opacity: 0.8;"><i class="fa-solid fa-scale-balanced"></i></div>
          </div>
        </div>
      </div>
    </div>

    <!-- Filter & Search Toolbar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 8px;">
      <div class="card-body p-3">
        <form method="GET" class="row g-2 align-items-center">
          <div class="col-12 col-md-3">
            <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
              <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>All Returns (تمام واپسی ریکارڈ)</option>
              <option value="sale_return" <?= $filterType === 'sale_return' ? 'selected' : '' ?>>Sale Returns (گاہک واپسی)</option>
              <option value="purchase_return" <?= $filterType === 'purchase_return' ? 'selected' : '' ?>>Purchase Returns (مل واپسی)</option>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <div class="input-group input-group-sm">
              <span class="input-group-text">From</span>
              <input type="date" name="start_date" value="<?= $startDate ?>" class="form-control">
            </div>
          </div>
          <div class="col-12 col-md-3">
            <div class="input-group input-group-sm">
              <span class="input-group-text">To</span>
              <input type="date" name="end_date" value="<?= $endDate ?>" class="form-control">
            </div>
          </div>
          <div class="col-12 col-md-3 d-flex gap-1">
            <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search Return #, Party..." class="form-control form-control-sm">
            <button type="submit" class="btn btn-sm btn-primary px-3">Filter</button>
            <?php if (!empty($search) || $filterType !== 'all'): ?>
              <a href="returns.php" class="btn btn-sm btn-outline-secondary">Reset</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Returns Listing Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 8px;">
      <div class="card-header bg-white border-bottom py-3">
        <h6 class="mb-0 fw-bold" style="color: var(--nv);">Returns Ledger History (تاریخچہ واپسی مال)</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13px;">
          <thead style="background: #f8fafc;">
            <tr>
              <th style="padding: 10px 14px;">Return #</th>
              <th>Date</th>
              <th>Type</th>
              <th>Customer / Supplier</th>
              <th>Returned Item &amp; Weight</th>
              <th>Rate (Rs)</th>
              <th class="text-end">Net Refund</th>
              <th>Refund Method</th>
              <th class="text-center">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($returnsList)): ?>
              <tr>
                <td colspan="9" class="text-center py-4 text-muted">
                  <em>No return records found matching criteria (کوئی ریٹرن ریکارڈ موجود نہیں ہے).</em>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($returnsList as $ret): ?>
                <tr>
                  <td style="padding: 10px 14px;">
                    <strong><?= htmlspecialchars($ret['return_no']) ?></strong>
                    <?php if (!empty($ret['reference_no'])): ?>
                      <div class="text-muted" style="font-size: 11px;">Ref: <?= htmlspecialchars($ret['reference_no']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= date('d-M-Y', strtotime($ret['return_date'])) ?></td>
                  <td>
                    <?php if ($ret['return_type'] === 'sale_return'): ?>
                      <span class="badge bg-primary">Sale Return (گاہک)</span>
                    <?php else: ?>
                      <span class="badge bg-success">Purchase Return (مل)</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($ret['party_name']) ?></strong>
                    <?php if (!empty($ret['party_phone'])): ?>
                      <div class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($ret['party_phone']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars($ret['item_name'] ?? 'Steel Item') ?></strong>
                    <div class="font-num text-primary fw-bold" style="font-size: 12px;">
                      <?= formatQty($ret['item_qty'], $ret['item_unit'] ?? 'kg') ?>
                    </div>
                  </td>
                  <td class="font-num">Rs <?= number_format((float)$ret['item_rate'], 2) ?></td>
                  <td class="text-end fw-bold font-num" style="font-size: 14px; color: <?= $ret['return_type'] === 'sale_return' ? '#b91c1c' : '#15803d' ?>;">
                    <?= formatMoney($ret['net_amount']) ?>
                  </td>
                  <td>
                    <?php if ($ret['refund_type'] === 'credit_to_khata'): ?>
                      <span class="badge bg-secondary">Ledger Khata Adjusted</span>
                    <?php else: ?>
                      <span class="badge bg-info text-dark">Cash / Bank Refund</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center">
                    <a href="print_return.php?id=<?= $ret['id'] ?>" target="_blank" class="btn btn-sm btn-outline-dark" title="Print Return Note">
                      <i class="fa-solid fa-print me-1"></i> Print Note
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- New Return Entry Card Form (Pure Multi-Page, No Modals) -->
    <div class="card" id="newReturnCard" style="border: 1px solid var(--bd); border-radius: 8px;">
      <div class="card-header bg-white border-bottom py-3 d-flex justify-content-between align-items-center">
        <div>
          <h5 class="mb-0 fw-bold" style="color: var(--nv);">Record Return &amp; Stock Revaluation (اندراج واپسی مال)</h5>
          <small class="text-muted">Perpetual Moving Weighted-Average Costing &amp; Real-time Khata Adjustments</small>
        </div>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="returns.php">
          <input type="hidden" name="action" value="save_return">
          <?= getCSRFInput() ?>

          <div class="row g-3">
            <!-- Return Type -->
            <div class="col-12 col-md-4">
              <label class="form-label fw-bold">Return Classification (قسم واپسی) <span class="text-danger">*</span></label>
              <select name="return_type" id="retTypeSelect" class="form-select" onchange="toggleReturnType(this.value)">
                <option value="sale_return">Customer Sale Return (گاہک سے مال واپس آنا)</option>
                <option value="purchase_return">Supplier Purchase Return (سپلائر / مل کو مال واپس کرنا)</option>
              </select>
            </div>

            <!-- Return Date -->
            <div class="col-12 col-md-4">
              <label class="form-label fw-bold">Return Date (تاریخ) <span class="text-danger">*</span></label>
              <input type="date" name="return_date" value="<?= date('Y-m-d') ?>" class="form-control" required>
            </div>

            <!-- Reference Document No -->
            <div class="col-12 col-md-4">
              <label class="form-label fw-bold">Reference Bill / Purchase #</label>
              <input type="text" name="reference_no" placeholder="e.g. BILL-2026-00001 or Mill Inv #" class="form-control">
            </div>

            <!-- Customer Selection (Shown when Sale Return) -->
            <div class="col-12 col-md-6" id="customerSelectBox">
              <label class="form-label fw-bold">Select Customer (گاہک نام) <span class="text-danger">*</span></label>
              <select name="party_id" id="custPartyId" class="form-select">
                <option value="">-- Select Customer --</option>
                <?php foreach ($customers as $c): ?>
                  <option value="<?= $c['id'] ?>">
                    <?= htmlspecialchars($c['name']) ?> (Bal: <?= formatMoney($c['current_balance']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Supplier Selection (Shown when Purchase Return) -->
            <div class="col-12 col-md-6 d-none" id="supplierSelectBox">
              <label class="form-label fw-bold">Select Supplier / Mill (سپلائر نام) <span class="text-danger">*</span></label>
              <select id="suppPartyId" class="form-select" onchange="document.getElementById('custPartyId').value = this.value">
                <option value="">-- Select Supplier --</option>
                <?php foreach ($suppliers as $s): ?>
                  <option value="<?= $s['id'] ?>">
                    <?= htmlspecialchars($s['name']) ?> (Payable: <?= formatMoney($s['current_balance']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Inventory Item Selection -->
            <div class="col-12 col-md-6">
              <label class="form-label fw-bold">Returned Steel Item (آئٹم نام) <span class="text-danger">*</span></label>
              <select name="item_id" id="itemSelect" class="form-select" onchange="handleItemSelect(this)" required>
                <option value="">-- Select Inventory Item --</option>
                <?php foreach ($itemsList as $it): ?>
                  <option value="<?= $it['id'] ?>" 
                          data-stock="<?= $it['stock_qty'] ?>" 
                          data-cost="<?= $it['cost_price'] ?>" 
                          data-sale="<?= $it['sale_price'] ?>"
                          data-unit="<?= $it['unit'] ?>">
                    <?= htmlspecialchars($it['name']) ?> [Stock: <?= formatQty($it['stock_qty'], $it['unit']) ?> | W-Avg Cost: Rs <?= number_format((float)$it['cost_price'], 2) ?>]
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Return Quantity in KG -->
            <div class="col-12 col-md-3">
              <label class="form-label fw-bold">Return Weight / Qty (وزن) <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="number" step="0.01" min="0.01" name="qty" id="retQty" class="form-control" placeholder="0.00" oninput="calculateReturnTotal()" required>
                <span class="input-group-text" id="unitSpan">kg</span>
              </div>
            </div>

            <!-- Return Rate per KG -->
            <div class="col-12 col-md-3">
              <label class="form-label fw-bold">Return Rate (ریٹ فی کلو) <span class="text-danger">*</span></label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" min="0.01" name="unit_rate" id="retRate" class="form-control" placeholder="0.00" oninput="calculateReturnTotal()" required>
              </div>
            </div>

            <!-- Deductions / Restocking Fee -->
            <div class="col-12 col-md-3">
              <label class="form-label fw-bold">Deductions / Damage Fee</label>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" min="0.00" name="deduction_charges" id="retDeduction" value="0.00" class="form-control" oninput="calculateReturnTotal()">
              </div>
            </div>

            <!-- Net Refund / Credit Calculation Display -->
            <div class="col-12 col-md-3">
              <label class="form-label fw-bold">Net Refundable Amount (صافی رقم)</label>
              <div class="form-control d-flex align-items-center justify-content-end bg-light" style="min-height: 44px; font-weight: 700;">
                <span id="displayNetAmount" class="font-num fs-5 text-primary">Rs 0.00</span>
              </div>
            </div>

            <!-- Refund Method -->
            <div class="col-12 col-md-6">
              <label class="form-label fw-bold">Refund / Adjustment Method (طریقہ کار) <span class="text-danger">*</span></label>
              <select name="refund_type" id="refundTypeSelect" class="form-select" onchange="toggleRefundAccount(this.value)">
                <option value="credit_to_khata">Credit to Party Ledger Khata (کھاتے میں ایڈجسٹمنٹ)</option>
                <option value="cash_refund">Cash Refund (کیش رجسٹر سے نقد واپسی)</option>
                <option value="bank_refund">Bank Transfer Refund (بینک سے واپسی)</option>
              </select>
            </div>

            <!-- Paying Financial Account (Shown if Cash/Bank refund) -->
            <div class="col-12 col-md-6 d-none" id="financialAccountBox">
              <label class="form-label fw-bold">Paying / Receiving Account <span class="text-danger">*</span></label>
              <select name="financial_account_id" class="form-select">
                <option value="">-- Select Cash/Bank Account --</option>
                <?php foreach ($finAccounts as $fa): ?>
                  <option value="<?= $fa['id'] ?>">
                    <?= htmlspecialchars($fa['account_name']) ?> (<?= htmlspecialchars($fa['account_type']) ?> - Bal: <?= formatMoney($fa['current_balance']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Return Reason -->
            <div class="col-12 col-md-6">
              <label class="form-label fw-bold">Reason for Return (وجہ واپسی)</label>
              <input type="text" name="reason" placeholder="e.g. Defective Bars, Rust, Over-ordered, Cancellation..." class="form-control">
            </div>

            <!-- Notes -->
            <div class="col-12 col-md-6">
              <label class="form-label fw-bold">Additional Notes (تفصیلات)</label>
              <input type="text" name="notes" placeholder="Any additional remarks..." class="form-control">
            </div>

            <!-- Submit Button -->
            <div class="col-12 text-end mt-4 pt-2 border-top">
              <button type="submit" class="btn btn-primary px-4 py-2 fw-bold">
                <i class="fa-solid fa-check-circle me-1"></i> Post Return &amp; Update Stock (محفوظ کریں)
              </button>
            </div>

          </div>
        </form>
      </div>
    </div>

<script>
function toggleReturnType(type) {
  const custBox = document.getElementById('customerSelectBox');
  const suppBox = document.getElementById('supplierSelectBox');
  const custSelect = document.getElementById('custPartyId');
  const suppSelect = document.getElementById('suppPartyId');

  if (type === 'sale_return') {
    custBox.classList.remove('d-none');
    suppBox.classList.add('d-none');
    custSelect.name = 'party_id';
    suppSelect.removeAttribute('name');
  } else {
    custBox.classList.add('d-none');
    suppBox.classList.remove('d-none');
    suppSelect.name = 'party_id';
    custSelect.removeAttribute('name');
  }
}

function handleItemSelect(select) {
  const opt = select.options[select.selectedIndex];
  if (opt && opt.dataset.unit) {
    document.getElementById('unitSpan').innerText = opt.dataset.unit;
    const retType = document.getElementById('retTypeSelect').value;
    if (retType === 'sale_return' && opt.dataset.sale) {
      document.getElementById('retRate').value = parseFloat(opt.dataset.sale).toFixed(2);
    } else if (retType === 'purchase_return' && opt.dataset.cost) {
      document.getElementById('retRate').value = parseFloat(opt.dataset.cost).toFixed(2);
    }
    calculateReturnTotal();
  }
}

function calculateReturnTotal() {
  const qty = parseFloat(document.getElementById('retQty').value) || 0;
  const rate = parseFloat(document.getElementById('retRate').value) || 0;
  const ded = parseFloat(document.getElementById('retDeduction').value) || 0;
  const gross = qty * rate;
  const net = Math.max(0, gross - ded);
  document.getElementById('displayNetAmount').innerText = 'Rs ' + net.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function toggleRefundAccount(method) {
  const box = document.getElementById('financialAccountBox');
  if (method === 'credit_to_khata') {
    box.classList.add('d-none');
  } else {
    box.classList.remove('d-none');
  }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
