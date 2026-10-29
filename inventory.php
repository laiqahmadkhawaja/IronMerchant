<?php
// inventory.php - Stock Management & Inventory Valuation
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
$pageTitle = 'Stock & Valuation (اسٹاک اور انوینٹری)';
$errors = [];
$successMsg = '';

// Handle Add / Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid or expired security token. Please refresh and try again.';
    } else {
        $action = trim($_POST['action'] ?? '');

        // Handle Add Item / Product Category
        if ($action === 'save_item_category') {
            $catName = trim($_POST['category_name'] ?? '');
            $catDesc = trim($_POST['description'] ?? '');

            if ($catName === '') {
                $errors[] = 'Product category name is required.';
            } else {
                $chk = $pdo->prepare("SELECT id FROM item_categories WHERE LOWER(name) = LOWER(?)");
                $chk->execute([$catName]);
                if ($chk->fetch()) {
                    $errors[] = "A product category named '$catName' already exists.";
                } else {
                    $ins = $pdo->prepare("INSERT INTO item_categories (name, description) VALUES (?, ?)");
                    $ins->execute([$catName, $catDesc]);
                    $newCatId = (int)$pdo->lastInsertId();
                    logAudit('create', 'item_categories', $newCatId, "Created product category '$catName'");
                    setFlash('success', "Product category '$catName' added successfully.");
                    header('Location: inventory.php');
                    exit;
                }
            }
        }

        // Handle Delete Item (Only allowed if item has NO stock)
        if ($action === 'delete_item') {
            $itemId = (int)($_POST['item_id'] ?? 0);
            $st = $pdo->prepare("SELECT id, item_code, name, stock_qty FROM items WHERE id = ?");
            $st->execute([$itemId]);
            $item = $st->fetch();

            if (!$item) {
                $errors[] = 'Item not found.';
            } elseif (abs((float)$item['stock_qty']) > 0.0001) {
                $errors[] = "Cannot delete '{$item['name']}': It currently has active stock of " . number_format((float)$item['stock_qty'], 2) . ". Stock must be 0 before an item can be deleted.";
            } else {
                try {
                    $pdo->beginTransaction();
                    $chkTransactions = $pdo->prepare("
                        SELECT 
                            (SELECT COUNT(*) FROM sale_items WHERE item_id = ?) +
                            (SELECT COUNT(*) FROM purchase_items WHERE item_id = ?) +
                            (SELECT COUNT(*) FROM return_items WHERE item_id = ?) +
                            (SELECT COUNT(*) FROM stock_adjustments WHERE item_id = ?) AS active_refs
                    ");
                    $chkTransactions->execute([$itemId, $itemId, $itemId, $itemId]);
                    $activeRefs = (int)$chkTransactions->fetchColumn();

                    if ($activeRefs > 0) {
                        // Soft delete / archive so accounting and historical sales/bills are not broken
                        $upd = $pdo->prepare("UPDATE items SET is_active = 0 WHERE id = ?");
                        $upd->execute([$itemId]);
                        logAudit('delete', 'items', $itemId, "Archived zero-stock item '{$item['name']}'");
                        setFlash('success', "Item '{$item['name']}' ({$item['item_code']}) with 0 stock has been removed from active inventory.");
                    } else {
                        // Hard delete completely
                        $pdo->prepare("DELETE FROM stock_movements WHERE item_id = ?")->execute([$itemId]);
                        $pdo->prepare("DELETE FROM items WHERE id = ?")->execute([$itemId]);
                        logAudit('delete', 'items', $itemId, "Permanently deleted zero-stock item '{$item['name']}'");
                        setFlash('success', "Item '{$item['name']}' ({$item['item_code']}) has been permanently deleted.");
                    }
                    $pdo->commit();
                    header('Location: inventory.php');
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $errors[] = "Failed to delete item: " . $e->getMessage();
                }
            }
        }

        if ($action === 'save_item') {
            $itemId    = (int)($_POST['item_id'] ?? 0);
            $itemCode  = trim($_POST['item_code'] ?? '');
            $name      = trim($_POST['name'] ?? '');
            $catId     = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $unit      = trim($_POST['unit'] ?? 'kg');
            $costPrice = (float)($_POST['cost_price'] ?? 0);
            $salePrice = (float)($_POST['sale_price'] ?? 0);
            $alertQty  = (float)($_POST['alert_qty'] ?? 1000);

            if ($itemCode === '') {
                $errors[] = 'Item Code / SKU is required.';
            }
            if ($name === '') {
                $errors[] = 'Item Name (English / Urdu) is required.';
            }
            if ($costPrice < 0 || $salePrice < 0) {
                $errors[] = 'Rates cannot be negative.';
            }

            if (empty($errors)) {
                try {
                    if ($itemId > 0) {
                        // Update
                        $stmt = $pdo->prepare("UPDATE items SET item_code = ?, name = ?, category_id = ?, unit = ?, cost_price = ?, sale_price = ?, alert_qty = ? WHERE id = ?");
                        $stmt->execute([$itemCode, $name, $catId, $unit, $costPrice, $salePrice, $alertQty, $itemId]);
                        logAudit('update', 'items', $itemId, "Updated item $name ($itemCode)");
                        setFlash('success', "Item '$name' updated successfully.");
                    } else {
                        // Check unique item_code
                        $chk = $pdo->prepare("SELECT id FROM items WHERE item_code = ?");
                        $chk->execute([$itemCode]);
                        if ($chk->fetch()) {
                            throw new Exception("An item with code '$itemCode' already exists.");
                        }

                        $openingQty = (float)($_POST['opening_qty'] ?? 0);
                        $stmt = $pdo->prepare("INSERT INTO items (item_code, name, category_id, unit, cost_price, sale_price, stock_qty, alert_qty) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([$itemCode, $name, $catId, $unit, $costPrice, $salePrice, $openingQty, $alertQty]);
                        $newId = (int)$pdo->lastInsertId();

                        // If opening qty provided, record stock movement
                        if ($openingQty > 0) {
                            $totalVal = round($openingQty * $costPrice, 2);
                            $sm = $pdo->prepare("INSERT INTO stock_movements (item_id, movement_type, reference_type, reference_id, qty_in, unit_cost, total_cost, balance_qty, movement_date, notes, created_by) VALUES (?, 'opening', 'opening', ?, ?, ?, ?, ?, CURDATE(), 'Opening stock balance', ?)");
                            $sm->execute([$newId, $newId, $openingQty, $costPrice, $totalVal, $openingQty, $currentUser['id'] ?? null]);
                        }

                        logAudit('create', 'items', $newId, "Created item $name ($itemCode) with opening qty $openingQty");
                        setFlash('success', "Item '$name' created successfully.");
                    }
                    header('Location: inventory.php');
                    exit;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// Fetch categories for filter & dropdown
$categories = $pdo->query("SELECT * FROM item_categories ORDER BY name ASC")->fetchAll();

// Filter & search
$search = trim($_GET['search'] ?? '');
$categoryFilter = !empty($_GET['category']) ? (int)$_GET['category'] : 0;
$stockStatus = trim($_GET['stock_status'] ?? '');

$sql = "SELECT i.*, c.name AS category_name FROM items i LEFT JOIN item_categories c ON i.category_id = c.id WHERE i.is_active = 1";
$params = [];

if ($search !== '') {
    $sql .= " AND (i.name LIKE ? OR i.item_code LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($categoryFilter > 0) {
    $sql .= " AND i.category_id = ?";
    $params[] = $categoryFilter;
}

if ($stockStatus === 'low') {
    $sql .= " AND i.stock_qty <= i.alert_qty";
} elseif ($stockStatus === 'out') {
    $sql .= " AND i.stock_qty <= 0";
}

$sql .= " ORDER BY i.name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

// KPI Calculations
$totalInventoryValue = 0.0;
$totalStockKg = 0.0;
$lowStockCount = 0;
foreach ($items as $it) {
    $qty = (float)$it['stock_qty'];
    $cost = (float)$it['cost_price'];
    $totalInventoryValue += ($qty * $cost);
    $totalStockKg += $qty;
    if ($qty <= (float)$it['alert_qty']) {
        $lowStockCount++;
    }
}

// Check edit mode
$editItem = null;
if (!empty($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $st = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $st->execute([$editId]);
    $editItem = $st->fetch();
}

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Stock & Valuation (اسٹاک اور انوینٹری)</h4>
        <span class="text-muted" style="font-size: 13px;">Real-time weighted-average landed cost valuation and steel group inventory</span>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#newItemCategoryCollapse" aria-expanded="false" aria-controls="newItemCategoryCollapse" onclick="document.getElementById('newItemCategoryCollapse').scrollIntoView({behavior:'smooth'});">
          <i class="fa-solid fa-layer-group me-1"></i> Add Category (نئی کیٹگری)
        </button>
        <a href="stock_adjust.php" class="btn btn-outline-secondary">
          <i class="fa-solid fa-sliders me-1"></i> Stock Adjustment (اسٹاک درستگی)
        </a>
        <a href="#itemFormCard" class="btn btn-zb-primary" onclick="document.getElementById('itemFormCard').scrollIntoView({behavior:'smooth'});">
          <i class="fa-solid fa-plus me-1"></i> Add New Product (نیا پروڈکٹ)
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
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--nv);">
          <div class="kpi-icon" style="background: rgba(20,48,122,0.1); color: var(--nv);"><i class="fa-solid fa-vault"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= formatCurrency($totalInventoryValue) ?></div>
          <div class="kpi-label">Total Inventory Valuation (کل قیمت)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--am);">
          <div class="kpi-icon" style="background: rgba(245,166,35,0.1); color: #b7791f;"><i class="fa-solid fa-weight-hanging"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= number_format($totalStockKg, 1) ?> kg</div>
          <div class="kpi-label"><?= number_format($totalStockKg / 1000, 2) ?> Metric Tons in Hand</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid <?= $lowStockCount > 0 ? 'var(--rd)' : 'var(--gn)' ?>;">
          <div class="kpi-icon" style="background: <?= $lowStockCount > 0 ? 'rgba(224,36,36,0.1)' : 'rgba(22,163,74,0.1)' ?>; color: <?= $lowStockCount > 0 ? 'var(--rd)' : 'var(--gn)' ?>;">
            <i class="fa-solid fa-triangle-exclamation"></i>
          </div>
          <div class="kpi-value" style="font-size: 20px;"><?= $lowStockCount ?></div>
          <div class="kpi-label">Low Stock Items (کم اسٹاک کی تنبیہ)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid #4a5568;">
          <div class="kpi-icon" style="background: rgba(74,85,104,0.1); color: #4a5568;"><i class="fa-solid fa-bars-staggered"></i></div>
          <div class="kpi-value" style="font-size: 20px;"><?= count($items) ?></div>
          <div class="kpi-label">Active Steel Products (کل آئٹمز)</div>
        </div>
      </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="inventory.php" class="row g-3 align-items-end">
          <div class="col-12 col-md-4">
            <label class="form-label">Search Product / SKU</label>
            <input type="text" name="search" class="form-control" placeholder="Search by name, grade or item code..." value="<?= htmlspecialchars($search) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Filter by Category</label>
            <select name="category" class="form-select">
              <option value="">All Categories (تمام کیٹیگریز)</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= $cat['id'] ?>" <?= ($categoryFilter === (int)$cat['id']) ? 'selected' : '' ?>>
                  <?= htmlspecialchars($cat['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Stock Status</label>
            <select name="stock_status" class="form-select">
              <option value="">All Stock Levels</option>
              <option value="low" <?= ($stockStatus === 'low') ? 'selected' : '' ?>>Low Stock (<= Alert Qty)</option>
              <option value="out" <?= ($stockStatus === 'out') ? 'selected' : '' ?>>Out of Stock (Zero / Negative)</option>
            </select>
          </div>
          <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <?php if ($search !== '' || $categoryFilter > 0 || $stockStatus !== ''): ?>
              <a href="inventory.php" class="btn btn-outline-secondary" title="Reset Filters"><i class="fa-solid fa-rotate-left"></i></a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Inventory Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Steel Stock Master & Valuation Ledger</h6>
        <span class="badge bg-light text-dark border"><?= count($items) ?> items</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Code</th>
              <th style="padding: 12px 14px;">Item Name (اردو / English)</th>
              <th style="padding: 12px 14px;">Category</th>
              <th class="text-end" style="padding: 12px 14px;">Current Stock</th>
              <th class="text-end" style="padding: 12px 14px;">Landed Cost / kg</th>
              <th class="text-end" style="padding: 12px 14px;">Selling Rate / kg</th>
              <th class="text-end" style="padding: 12px 14px;">Total Valuation</th>
              <th class="text-center" style="padding: 12px 14px;">Margin</th>
              <th class="text-center" style="padding: 12px 14px;">Status</th>
              <th class="text-center" style="padding: 12px 14px;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($items)): ?>
              <tr>
                <td colspan="10" class="text-center py-4 text-muted">No inventory products found matching your search.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($items as $it): 
                $stockQty = (float)$it['stock_qty'];
                $costPrice = (float)$it['cost_price'];
                $salePrice = (float)$it['sale_price'];
                $valuation = round($stockQty * $costPrice, 2);
                $marginPercent = ($costPrice > 0) ? round((($salePrice - $costPrice) / $costPrice) * 100, 1) : 0;
                $isLow = ($stockQty <= (float)$it['alert_qty']);
              ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 600; color: #475569;">
                    <?= htmlspecialchars($it['item_code']) ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div style="font-weight: 600; color: var(--nv);"><?= htmlspecialchars($it['name']) ?></div>
                    <small class="text-muted" style="font-size: 11.5px;">Alert Level: <?= number_format((float)$it['alert_qty'], 0) ?> <?= htmlspecialchars($it['unit']) ?></small>
                  </td>
                  <td style="padding: 12px 14px;">
                    <span class="badge bg-light text-dark border"><?= htmlspecialchars($it['category_name'] ?? 'Uncategorized') ?></span>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700;">
                    <span style="font-size: 14px;"><?= number_format($stockQty, 1) ?> <?= htmlspecialchars($it['unit']) ?></span>
                    <div class="text-muted" style="font-size: 11px;"><?= number_format($stockQty / 1000, 2) ?> Tons</div>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600; color: #334155;">
                    <?= formatCurrency($costPrice) ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: var(--nv);">
                    <?= formatCurrency($salePrice) ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; color: #0f172a;">
                    <?= formatCurrency($valuation) ?>
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <span class="badge <?= $marginPercent >= 0 ? 'bg-success-subtle text-success' : 'bg-danger-subtle text-danger' ?>" style="font-size: 12px; font-weight: 600;">
                      <?= $marginPercent ?>%
                    </span>
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <?php if ($stockQty <= 0): ?>
                      <span class="badge bg-danger">Out of Stock</span>
                    <?php elseif ($isLow): ?>
                      <span class="badge bg-warning text-dark"><i class="fa-solid fa-triangle-exclamation me-1"></i> Low Stock</span>
                    <?php else: ?>
                      <span class="badge bg-success">In Stock</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <div class="btn-group btn-group-sm">
                      <a href="inventory.php?edit=<?= $it['id'] ?>#itemFormCard" class="btn btn-outline-primary" title="Edit Item">
                        <i class="fa-solid fa-pen-to-square"></i>
                      </a>
                      <a href="stock_adjust.php?item_id=<?= $it['id'] ?>" class="btn btn-outline-warning" title="Adjust Stock">
                        <i class="fa-solid fa-sliders"></i>
                      </a>
                      <?php if ($stockQty <= 0.0001 && $stockQty >= -0.0001): ?>
                        <form method="POST" action="inventory.php" class="d-inline" onsubmit="return confirm('Are you sure you want to delete zero-stock item: <?= htmlspecialchars(addslashes($it['name'])) ?>?');">
                          <?= getCSRFInput() ?>
                          <input type="hidden" name="action" value="delete_item">
                          <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                          <button type="submit" class="btn btn-outline-danger" title="Delete Zero-Stock Item">
                            <i class="fa-solid fa-trash"></i>
                          </button>
                        </form>
                      <?php else: ?>
                        <button type="button" class="btn btn-outline-secondary" disabled title="Cannot delete: Item has active stock (<?= number_format($stockQty, 1) ?> <?= htmlspecialchars($it['unit']) ?>)">
                          <i class="fa-solid fa-trash"></i>
                        </button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Add Product Category Inline Card (No Modal, Smooth Collapse) -->
    <div class="collapse mb-4" id="newItemCategoryCollapse">
      <div class="card shadow-sm" style="border: 1.5px solid #93c5fd; border-radius: 10px; background-color: #f8fafc;">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
          <h6 class="mb-0 fw-bold text-primary">
            <i class="fa-solid fa-layer-group me-2"></i> Add New Product Category (اسٹاک کی نئی قسم درج کریں)
          </h6>
          <button type="button" class="btn-close" data-bs-toggle="collapse" data-bs-target="#newItemCategoryCollapse" aria-label="Close"></button>
        </div>
        <div class="card-body p-4">
          <form method="POST" action="inventory.php">
            <?= getCSRFInput() ?>
            <input type="hidden" name="action" value="save_item_category">
            <div class="row g-3 align-items-end">
              <div class="col-12 col-md-5">
                <label class="form-label">Category Name (کیٹگری نام) <span class="text-danger">*</span></label>
                <input type="text" name="category_name" class="form-control" required placeholder="e.g. MS Square Pipes / پائپ اور چکور نلیاں">
              </div>
              <div class="col-12 col-md-5">
                <label class="form-label">Description (تفصیل) <span class="text-muted">(Optional)</span></label>
                <input type="text" name="description" class="form-control" placeholder="Brief description of products in this category">
              </div>
              <div class="col-12 col-md-2 text-end">
                <button type="submit" class="btn btn-zb-primary w-100 py-2">
                  <i class="fa-solid fa-check me-1"></i> Save Category
                </button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add / Edit Item Form (No modal - Clean inline Card) -->
    <div class="card" id="itemFormCard" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <?= $editItem ? 'Edit Product: ' . htmlspecialchars($editItem['name']) : 'Add New Steel Product (نیا پروڈکٹ شامل کریں)' ?>
        </h6>
        <?php if ($editItem): ?>
          <a href="inventory.php" class="btn btn-sm btn-outline-secondary">Cancel Edit</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="inventory.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_item">
          <input type="hidden" name="item_id" value="<?= $editItem ? (int)$editItem['id'] : 0 ?>">

          <div class="row g-3">
            <div class="col-12 col-md-3">
              <label class="form-label">Item Code / SKU <span class="text-danger">*</span></label>
              <input type="text" name="item_code" class="form-control" required placeholder="e.g. ST-012" value="<?= htmlspecialchars($editItem['item_code'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-5">
              <label class="form-label">Item Name (اردو اور انگریزی نام) <span class="text-danger">*</span></label>
              <input type="text" name="name" class="form-control" required placeholder="e.g. Deformed Steel Rebar 60-Grade (سریا ۶۰ گریڈ)" value="<?= htmlspecialchars($editItem['name'] ?? '') ?>">
            </div>

            <div class="col-12 col-md-4">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                <label class="form-label mb-0">Category (کیٹیگری)</label>
                <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none fw-semibold" style="font-size: 12px;" data-bs-toggle="collapse" data-bs-target="#newItemCategoryCollapse" aria-expanded="false" aria-controls="newItemCategoryCollapse" onclick="document.getElementById('newItemCategoryCollapse').scrollIntoView({behavior:'smooth'});">
                  <i class="fa-solid fa-plus-circle me-1"></i>+ New Category
                </button>
              </div>
              <select name="category_id" class="form-select">
                <option value="">Select Category...</option>
                <?php foreach ($categories as $cat): ?>
                  <option value="<?= $cat['id'] ?>" <?= (($editItem['category_id'] ?? 0) == $cat['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat['name']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Unit of Measure</label>
              <select name="unit" class="form-select">
                <option value="kg" <?= (($editItem['unit'] ?? 'kg') === 'kg') ? 'selected' : '' ?>>Kilogram (kg - کلوگرام)</option>
                <option value="ton" <?= (($editItem['unit'] ?? '') === 'ton') ? 'selected' : '' ?>>Metric Ton (ٹن)</option>
                <option value="pcs" <?= (($editItem['unit'] ?? '') === 'pcs') ? 'selected' : '' ?>>Pieces (pcs - عدد / نگ)</option>
                <option value="bundle" <?= (($editItem['unit'] ?? '') === 'bundle') ? 'selected' : '' ?>>Bundle (بنڈل)</option>
                <option value="feet" <?= (($editItem['unit'] ?? '') === 'feet') ? 'selected' : '' ?>>Feet (فٹ)</option>
                <option value="meter" <?= (($editItem['unit'] ?? '') === 'meter') ? 'selected' : '' ?>>Meter (میٹر)</option>
                <option value="bag" <?= (($editItem['unit'] ?? '') === 'bag') ? 'selected' : '' ?>>Bag / Bori (بوری)</option>
              </select>
            </div>

            <div class="col-12 col-md-2">
              <label class="form-label">Cost Rate (Rs/unit)</label>
              <input type="number" step="0.01" name="cost_price" class="form-control" placeholder="0.00" value="<?= htmlspecialchars((string)($editItem['cost_price'] ?? '0')) ?>">
              <small class="text-muted" style="font-size: 11px;">Updates with purchases</small>
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Selling Rate (فروخت قیمت فی کلو)</label>
              <input type="number" step="0.01" name="sale_price" class="form-control" placeholder="0.00" value="<?= htmlspecialchars((string)($editItem['sale_price'] ?? '0')) ?>">
            </div>

            <?php if (!$editItem): ?>
              <div class="col-12 col-md-2">
                <label class="form-label">Opening Stock (ابتدائی اسٹاک)</label>
                <input type="number" step="0.01" name="opening_qty" class="form-control" placeholder="0.00" value="0">
              </div>
            <?php endif; ?>

            <div class="col-12 col-md-<?= $editItem ? '5' : '3' ?>">
              <label class="form-label">Low Stock Alert Level (کم از کم اسٹاک)</label>
              <input type="number" step="0.01" name="alert_qty" class="form-control" placeholder="1000" value="<?= htmlspecialchars((string)($editItem['alert_qty'] ?? '1000')) ?>">
            </div>

            <div class="col-12 text-end mt-4">
              <?php if ($editItem): ?>
                <a href="inventory.php" class="btn btn-secondary me-2">Cancel</a>
              <?php endif; ?>
              <button type="submit" class="btn btn-zb-primary px-4">
                <i class="fa-solid fa-save me-1"></i> <?= $editItem ? 'Update Product' : 'Save New Product (محفوظ کریں)' ?>
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>



<?php require_once __DIR__ . '/includes/footer.php'; ?>
