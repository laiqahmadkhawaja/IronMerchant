<?php
// stock_adjust.php - Physical Inventory Audit & Stock Adjustment
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
requirePerm('inventory');

$currentUser = getCurrentUser();
$pdo = getPDO();
$pageTitle = 'Stock Adjustment (اسٹاک درستگی و اسکریپ)';
$errors = [];
$preselectedItemId = (int)($_GET['item_id'] ?? 0);

// Handle POST Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid or expired security token.';
    } else {
        $adjustDate = trim($_POST['adjustment_date'] ?? date('Y-m-d'));
        $itemId     = (int)($_POST['item_id'] ?? 0);
        $adjustType = trim($_POST['adjustment_type'] ?? 'decrease'); // 'increase' or 'decrease'
        $qty        = (float)($_POST['qty'] ?? 0);
        $reason     = trim($_POST['reason'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');

        if ($itemId <= 0) {
            $errors[] = 'Please select a steel product.';
        }
        if ($qty <= 0) {
            $errors[] = 'Quantity must be greater than zero.';
        }
        if ($reason === '') {
            $errors[] = 'Please select or provide an adjustment reason.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // Lock item row
                $stItem = $pdo->prepare("SELECT id, item_code, name, cost_price, stock_qty, unit FROM items WHERE id = ? FOR UPDATE");
                $stItem->execute([$itemId]);
                $item = $stItem->fetch();
                if (!$item) {
                    throw new Exception("Selected product not found.");
                }

                $costPrice = (float)$item['cost_price'];
                $currentStock = (float)$item['stock_qty'];
                $totalValuation = round($qty * $costPrice, 2);

                if ($adjustType === 'decrease' && $currentStock < $qty) {
                    // Allowed in audit if requested, but warn/adjust to zero if needed
                }

                $newStock = ($adjustType === 'increase') ? ($currentStock + $qty) : ($currentStock - $qty);
                $adjNo = generateDocumentNumber('ADJ', 'stock_adjustments', 'adjustment_no');

                // 1. Double-entry Journal Entry
                // If decrease (e.g. cutting scrap / shortage):
                // Debit COGS (28) or Expense, Credit Inventory (9)
                // If increase (e.g. audit surplus):
                // Debit Inventory (9), Credit COGS (28)
                $jLines = [];
                if ($adjustType === 'decrease') {
                    $jLines[] = [
                        'account_id'  => COA_COGS,
                        'debit'       => $totalValuation,
                        'credit'      => 0.0,
                        'description' => "Stock shrinkage/scrap loss: {$item['name']} - {$qty} {$item['unit']} @ {$costPrice}"
                    ];
                    $jLines[] = [
                        'account_id'  => COA_INVENTORY,
                        'debit'       => 0.0,
                        'credit'      => $totalValuation,
                        'description' => "Inventory adjustment reduction: {$item['name']}"
                    ];
                } else {
                    $jLines[] = [
                        'account_id'  => COA_INVENTORY,
                        'debit'       => $totalValuation,
                        'credit'      => 0.0,
                        'description' => "Inventory surplus/gain: {$item['name']} - {$qty} {$item['unit']} @ {$costPrice}"
                    ];
                    $jLines[] = [
                        'account_id'  => COA_COGS,
                        'debit'       => 0.0,
                        'credit'      => $totalValuation,
                        'description' => "Cost reduction from stock audit surplus: {$item['name']}"
                    ];
                }

                $journalId = postJournalEntry($pdo, [
                    'entry_no'    => getNextDocNo('JV', 'journal_entries', 'entry_no'),
                    'entry_date'  => $adjustDate,
                    'source_type' => 'adjustment',
                    'narration'   => ($adjustType === 'decrease' ? 'Stock Shrinkage / Scrap Reduction' : 'Stock Surplus Audit Gain') . " ($adjNo)",
                    'created_by'  => $currentUser['id'] ?? null
                ], $jLines);

                // 2. Insert into stock_adjustments
                $insAdj = $pdo->prepare("INSERT INTO stock_adjustments (adjustment_no, adjustment_date, item_id, adjustment_type, qty, unit_cost, total_cost, reason, notes, journal_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insAdj->execute([$adjNo, $adjustDate, $itemId, $adjustType, $qty, $costPrice, $totalValuation, $reason, $notes, $journalId, $currentUser['id'] ?? null]);
                $adjId = (int)$pdo->lastInsertId();

                // 3. Insert into stock_movements
                $moveType = ($adjustType === 'increase') ? 'adjustment_in' : 'adjustment_out';
                $qtyIn  = ($adjustType === 'increase') ? $qty : 0.0;
                $qtyOut = ($adjustType === 'decrease') ? $qty : 0.0;

                $insMove = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, reference_type, reference_id, qty_in, qty_out, unit_cost, total_cost, balance_qty, movement_date, notes, created_by) VALUES (?, ?, 'stock_adjustment', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insMove->execute([$itemId, $moveType, $adjId, $qtyIn, $qtyOut, $costPrice, $totalValuation, $newStock, $adjustDate, "$reason - $notes", $currentUser['id'] ?? null]);

                // 4. Update items table stock_qty
                $updItem = $pdo->prepare("UPDATE items SET stock_qty = ? WHERE id = ?");
                $updItem->execute([$newStock, $itemId]);

                $pdo->commit();
                logAudit('create', 'stock_adjustments', $adjId, "Adjusted stock for item {$item['name']} by $adjustType $qty {$item['unit']}");
                setFlash('success', "Stock adjustment $adjNo recorded successfully. New stock: $newStock {$item['unit']}.");
                header('Location: stock_adjust.php');
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $errors[] = $e->getMessage();
            }
        }
    }
}

// Fetch all active items for dropdown
$items = $pdo->query("SELECT id, item_code, name, unit, cost_price, sale_price, stock_qty FROM items WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

// Fetch recent adjustments
$recentAdjustments = $pdo->query("
    SELECT sa.*, i.item_code, i.name AS item_name, i.unit, u.name AS user_name
    FROM stock_adjustments sa
    JOIN items i ON sa.item_id = i.id
    LEFT JOIN users u ON sa.created_by = u.id
    ORDER BY sa.id DESC LIMIT 50
")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Stock Adjustment & Scrap (اسٹاک درستگی و اسکریپ)</h4>
        <span class="text-muted" style="font-size: 13px;">Physical audit discrepancy resolution, cutting scrap deduction, and weighted-average ledger correction</span>
      </div>
      <div>
        <a href="inventory.php" class="btn btn-outline-secondary">
          <i class="fa-solid fa-arrow-left me-1"></i> Back to Stock List
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

    <!-- Adjustment Entry Form Card (No Modals) -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);"><i class="fa-solid fa-pen-ruler me-2"></i> Record New Physical Stock Adjustment</h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="stock_adjust.php" id="adjustForm">
          <?= getCSRFInput() ?>

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Adjustment Date <span class="text-danger">*</span></label>
              <input type="date" name="adjustment_date" class="form-control" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="col-12 col-md-5">
              <label class="form-label">Select Steel Product <span class="text-danger">*</span></label>
              <select name="item_id" id="itemSelect" class="form-select" required onchange="updateProductDetails(this)">
                <option value="">-- Choose Steel Item --</option>
                <?php foreach ($items as $it): ?>
                  <option value="<?= $it['id'] ?>" 
                    data-stock="<?= (float)$it['stock_qty'] ?>" 
                    data-cost="<?= (float)$it['cost_price'] ?>"
                    data-unit="<?= htmlspecialchars($it['unit']) ?>"
                    <?= ($preselectedItemId === (int)$it['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($it['name']) ?> (Code: <?= htmlspecialchars($it['item_code']) ?>) - Current: <?= number_format((float)$it['stock_qty'], 1) ?> <?= htmlspecialchars($it['unit']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">Adjustment Type <span class="text-danger">*</span></label>
              <select name="adjustment_type" class="form-select" required onchange="recalcValuation()">
                <option value="decrease">Stock Decrease / Shortage / Scrap (کمی یا اسکریپ کا نقصان)</option>
                <option value="increase">Stock Increase / Surplus (اضافہ یا پیمائش کا فرق)</option>
              </select>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Adjustment Quantity (کلو یا تعداد) <span class="text-danger">*</span></label>
              <input type="number" step="0.01" name="qty" id="adjustQty" class="form-control" required placeholder="0.00" oninput="recalcValuation()">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Reason (وجہ) <span class="text-danger">*</span></label>
              <select name="reason" class="form-select" required>
                <option value="Cutting Scrap & Rust Loss">Cutting Scrap & Rust Loss (کٹائی و زنگ کا اسکریپ)</option>
                <option value="Physical Audit Discrepancy">Physical Audit Discrepancy (سالانہ/ماہانہ آڈٹ فرق)</option>
                <option value="Weighbridge Scale Tolerance">Weighbridge Scale Tolerance (کانٹے اور وزن کا فرق)</option>
                <option value="Corrosion / Damaged Rods">Corrosion / Damaged Rods (خراب یا ناکارہ مال)</option>
                <option value="Found Uncounted Surplus">Found Uncounted Surplus (غیر ریکارڈ شدہ زائد مال)</option>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Audit Notes & Remarks (تفصیلات)</label>
              <input type="text" name="notes" class="form-control" placeholder="e.g. Verified by Yard In-charge Bilal after stock counting">
            </div>

            <!-- Live Calculation Preview -->
            <div class="col-12">
              <div class="p-3" style="background: #f8fafc; border: 1px dashed var(--bd); border-radius: 8px;">
                <div class="row g-2 align-items-center">
                  <div class="col-12 col-md-3">
                    <span class="text-muted" style="font-size: 12px; display: block;">Current System Stock:</span>
                    <strong id="dispCurrentStock" style="font-size: 15px; color: #334155;">--</strong>
                  </div>
                  <div class="col-12 col-md-3">
                    <span class="text-muted" style="font-size: 12px; display: block;">Landed Cost Rate:</span>
                    <strong id="dispCostRate" style="font-size: 15px; color: #334155;">--</strong>
                  </div>
                  <div class="col-12 col-md-3">
                    <span class="text-muted" style="font-size: 12px; display: block;">Adjustment Financial Value:</span>
                    <strong id="dispTotalVal" style="font-size: 16px; color: var(--nv);">Rs 0.00</strong>
                  </div>
                  <div class="col-12 col-md-3 text-end">
                    <button type="submit" class="btn btn-zb-primary px-4" onclick="return confirm('Confirm stock adjustment and journal posting?');">
                      <i class="fa-solid fa-check me-1"></i> Post Adjustment (درستگی محفوظ کریں)
                    </button>
                  </div>
                </div>
              </div>
            </div>

          </div>
        </form>
      </div>
    </div>

    <!-- Recent Adjustments History -->
    <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Audit & Stock Adjustment History (سابقہ ریکارڈ)</h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Adj No</th>
              <th style="padding: 12px 14px;">Date</th>
              <th style="padding: 12px 14px;">Product</th>
              <th class="text-center" style="padding: 12px 14px;">Type</th>
              <th class="text-end" style="padding: 12px 14px;">Quantity</th>
              <th class="text-end" style="padding: 12px 14px;">Landed Cost</th>
              <th class="text-end" style="padding: 12px 14px;">Financial Value</th>
              <th style="padding: 12px 14px;">Reason & Notes</th>
              <th style="padding: 12px 14px;">Recorded By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($recentAdjustments)): ?>
              <tr>
                <td colspan="9" class="text-center py-4 text-muted">No stock adjustments recorded yet.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentAdjustments as $ra): ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 600; color: var(--nv);">
                    <?= htmlspecialchars($ra['adjustment_no']) ?>
                  </td>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($ra['adjustment_date']) ?></td>
                  <td style="padding: 12px 14px; font-weight: 600;">
                    <?= htmlspecialchars($ra['item_name']) ?>
                    <div class="text-muted" style="font-size: 11px;"><?= htmlspecialchars($ra['item_code']) ?></div>
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <span class="badge <?= ($ra['adjustment_type'] === 'increase') ? 'bg-success' : 'bg-danger' ?>">
                      <?= ucfirst($ra['adjustment_type']) ?>
                    </span>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700;">
                    <?= number_format((float)$ra['qty'], 2) ?> <?= htmlspecialchars($ra['unit']) ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px;">
                    <?= formatCurrency((float)$ra['unit_cost']) ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: #0f172a;">
                    <?= formatCurrency((float)$ra['total_cost']) ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div><?= htmlspecialchars($ra['reason']) ?></div>
                    <small class="text-muted"><?= htmlspecialchars($ra['notes'] ?? '') ?></small>
                  </td>
                  <td style="padding: 12px 14px; color: #64748b; font-size: 12.5px;">
                    <?= htmlspecialchars($ra['user_name'] ?? 'System') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

  </div>
</main>

<script>
let currentUnitCost = 0;
function updateProductDetails(sel) {
  const opt = sel.options[sel.selectedIndex];
  if (!opt || !opt.dataset.stock) {
    document.getElementById('dispCurrentStock').innerText = '--';
    document.getElementById('dispCostRate').innerText = '--';
    currentUnitCost = 0;
    recalcValuation();
    return;
  }
  const stock = parseFloat(opt.dataset.stock) || 0;
  currentUnitCost = parseFloat(opt.dataset.cost) || 0;
  const unit = opt.dataset.unit || 'kg';

  document.getElementById('dispCurrentStock').innerText = stock.toLocaleString() + ' ' + unit;
  document.getElementById('dispCostRate').innerText = 'Rs ' + currentUnitCost.toFixed(2) + ' / ' + unit;
  recalcValuation();
}

function recalcValuation() {
  const qty = parseFloat(document.getElementById('adjustQty').value) || 0;
  const totalVal = qty * currentUnitCost;
  document.getElementById('dispTotalVal').innerText = 'Rs ' + totalVal.toLocaleString('en-PK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
}

// Auto-trigger on page load if preselected
document.addEventListener('DOMContentLoaded', () => {
  const sel = document.getElementById('itemSelect');
  if (sel.value) updateProductDetails(sel);
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
