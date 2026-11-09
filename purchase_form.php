<?php
// purchase_form.php - Dedicated Purchase Entry Form with Landed Cost
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

requireAuth();
requirePerm('purchases');

$db = getDB();
$editPurId = (int)($_GET['edit'] ?? 0);
$editPur = null;
$editItems = [];
$editCharges = [];

if ($editPurId > 0) {
    $st = $db->prepare("SELECT * FROM purchases WHERE id = ?");
    $st->execute([$editPurId]);
    $editPur = $st->fetch();

    if ($editPur) {
        $pageTitle = 'Edit Purchase #' . htmlspecialchars($editPur['purchase_no']) . ' (خریداری ایڈٹ کریں)';
        $stItems = $db->prepare("
            SELECT pi.*, i.name, i.unit
            FROM purchase_items pi
            JOIN items i ON pi.item_id = i.id
            WHERE pi.purchase_id = ?
        ");
        $stItems->execute([$editPurId]);
        $editItems = $stItems->fetchAll();

        $stChg = $db->prepare("SELECT * FROM purchase_charges WHERE purchase_id = ?");
        $stChg->execute([$editPurId]);
        $editCharges = $stChg->fetchAll();
    }
}

$pageTitle = 'New Purchase Entry (خریداری اندراج)';
require_once __DIR__ . '/includes/header.php';

$db = getDB();
$suppliers = $db->query("SELECT id, name, current_balance FROM parties WHERE type = 'supplier' AND is_active = 1 ORDER BY name ASC")->fetchAll();
$items = $db->query("SELECT id, item_code, name, unit, cost_price FROM items WHERE is_active = 1 ORDER BY 
    CASE WHEN item_code IS NULL OR item_code = '' THEN 1 ELSE 0 END, 
    CASE WHEN item_code REGEXP '^[0-9]+$' THEN 0 ELSE 1 END, 
    CAST(item_code AS UNSIGNED) ASC, 
    item_code ASC, 
    name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, account_type, bank_name, account_number FROM financial_accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$activeShift = getActiveShift($db);
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1" style="color:var(--nv);"><i class="fa-solid fa-truck-ramp-box me-2 text-warning"></i> New Purchase &amp; Landed Cost Entry</h3>
    <small class="text-secondary">Base purchase price + Allocated labour/freight into inventory landed cost</small>
  </div>
  <div>
    <a href="purchases.php" class="btn btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Back to Purchases List</a>
  </div>
</div>

<?php if ($editPur): ?>
<div class="alert alert-warning py-3 px-4 mb-3 d-flex justify-content-between align-items-center shadow-sm" style="border-radius: 10px; border-left: 6px solid #d97706;">
  <div>
    <h5 class="fw-bold mb-1 text-dark"><i class="fa-solid fa-pen-to-square me-2 text-warning"></i> Editing Purchase #<?= htmlspecialchars($editPur['purchase_no']) ?> (خریداری میں تبدیلی موڈ)</h5>
    <small class="text-secondary">Saving changes will safely reverse previous inventory &amp; ledger postings, then apply the updated purchase atomically.</small>
  </div>
  <a href="purchases.php" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-xmark me-1"></i> Cancel &amp; Back to Purchases</a>
</div>
<?php endif; ?>

<?php if (!$activeShift): ?>
<div class="alert alert-danger py-3 px-4 mb-3 d-flex justify-content-between align-items-center shadow-sm" style="border-radius: 10px; border-left: 6px solid #dc3545;">
  <div class="d-flex align-items-center gap-3">
    <i class="fa-solid fa-lock text-danger fs-1"></i>
    <div>
      <h5 class="fw-bold mb-1 text-dark">Shift Closed / Not Started (شفٹ بند ہے / شروع نہیں کی گئی)</h5>
      <div class="text-secondary small">کوئی بھی خریداری یا ادائیگی درج کرنے سے پہلے روزانہ کیش شفٹ اوپن کرنا لازمی ہے۔ بغیر شفٹ شروع کیے ٹرانزیکشن کی اجازت نہیں ہے۔</div>
    </div>
  </div>
  <a href="closing.php" class="btn btn-danger fw-bold px-3 py-2"><i class="fa-solid fa-door-open me-1"></i> Open Day Shift (روزانہ شفٹ شروع کریں)</a>
</div>
<?php endif; ?>
<div class="zb-card mb-4">
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-5">
      <label class="form-label">Supplier / Steel Mill (سپلائر مل کا نام)</label>
      <select id="purSupplier" class="form-select">
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>" <?= ($editPur && (int)$s['id'] === (int)$editPur['supplier_id']) ? 'selected' : '' ?>><?= htmlspecialchars($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label">Purchase Date (تاریخ)</label>
      <input type="date" id="purDate" class="form-control" value="<?= isset($editPur) ? htmlspecialchars($editPur['purchase_date']) : date('Y-m-d') ?>">
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label">Supplier Bilty / Invoice # (بلٹی / انوائس نمبر)</label>
      <input type="text" id="purInvNo" class="form-control" value="<?= htmlspecialchars($editPur['supplier_inv_no'] ?? '') ?>" placeholder="e.g. MS-9872">
    </div>
  </div>

  <!-- 1. Steel Items Section -->
  <div class="p-3 bg-light rounded-3 border mb-4">
    <h5 class="fw-bold mb-3" style="color:var(--nv);"><i class="fa-solid fa-boxes-stacked me-1"></i> 1. Purchase Steel Products (خرید کردہ مال)</h5>
    
    <div class="row g-2 align-items-end mb-3">
      <div class="col-12 col-md-5">
        <label class="form-label">Select Steel Product</label>
        <select id="purItemSelect" class="form-select">
          <?php foreach ($items as $it): ?>
            <option value="<?= $it['id'] ?>" data-unit="<?= $it['unit'] ?>" data-cost="<?= $it['cost_price'] ?>" data-name="<?= htmlspecialchars($it['name']) ?>">
              <?= htmlspecialchars($it['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Weight / Quantity (kg)</label>
        <input type="number" id="purItemQty" class="form-control" placeholder="e.g. 1000" step="any">
      </div>
      <div class="col-6 col-md-2">
        <label class="form-label">Purchase Rate (Rs/kg)</label>
        <input type="number" id="purItemRate" class="form-control" placeholder="e.g. 240" step="any">
      </div>
      <div class="col-12 col-md-2">
        <button type="button" class="btn btn-zb-gold w-100" onclick="addPurchaseLine()">
          <i class="fa-solid fa-plus me-1"></i> Add Line
        </button>
      </div>
    </div>

    <!-- Items Landed Table -->
    <div class="table-responsive border rounded bg-white">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Product</th>
            <th>Qty (kg)</th>
            <th>Purchase Rate</th>
            <th>Base Amount</th>
            <th>Allocated Labour/Freight</th>
            <th>Final Landed Cost</th>
            <th>Landed Unit Rate</th>
            <th class="text-center" style="width:50px;">Action</th>
          </tr>
        </thead>
        <tbody id="purItemsBody">
          <tr><td colspan="8" class="text-center py-4 text-muted">No items added yet. Select product and click "Add Line".</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 2. Landed Charges Section -->
  <div class="p-3 bg-light rounded-3 border mb-4">
    <h5 class="fw-bold mb-3" style="color:var(--nv);"><i class="fa-solid fa-receipt me-1"></i> 2. Additional Landed Charges (لیبر، کرایہ، ان لوڈنگ وغیرہ)</h5>
    
    <div class="row g-2 align-items-end mb-3">
      <div class="col-12 col-md-4">
        <label class="form-label">Charge Name / Description (خرچہ کی قسم)</label>
        <select id="chargeName" class="form-select">
          <option value="لوڈنگ">لوڈنگ</option>
          <option value="کٹنگ">کٹنگ</option>
          <option value="کرایہ">کرایہ</option>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Charge Amount (Rs)</label>
        <div class="input-group">
          <span class="input-group-text">Rs</span>
          <input type="number" id="chargeAmount" class="form-control" placeholder="3000" step="any">
        </div>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label">Allocation Method</label>
        <select id="chargeMethod" class="form-select">
          <option value="weight">By Weight / Qty (وزن کے حساب سے)</option>
          <option value="value">By Item Value (رقم کے حساب سے)</option>
          <option value="equal">Equally across items</option>
        </select>
      </div>
      <div class="col-12 col-md-2">
        <button type="button" class="btn btn-zb-primary w-100" onclick="addPurchaseCharge()">
          <i class="fa-solid fa-plus me-1"></i> Add Charge
        </button>
      </div>
    </div>

    <div class="table-responsive border rounded bg-white">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Charge Name</th>
            <th>Amount (Rs)</th>
            <th>Accounting Capitalization</th>
            <th>Allocation Method</th>
            <th class="text-center" style="width:50px;">Action</th>
          </tr>
        </thead>
        <tbody id="purChargesBody">
          <tr><td colspan="5" class="text-center py-3 text-muted">No additional charges added.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 3. Payment & Finalize Section -->
  <div class="p-3 rounded-3 border mb-4 bg-white">
    <h5 class="fw-bold mb-3" style="color:var(--nv);"><i class="fa-solid fa-money-check-dollar me-1"></i> 3. Payment Details &amp; Confirmation</h5>

    <div class="row g-3">
      <div class="col-12 col-md-3">
        <label class="form-label">Payment Method (طریقہ ادائیگی)</label>
        <select id="purPayMethod" class="form-select" data-filter-accounts-for="#purAccount" onchange="filterAccountsByPaymentMethod(this, document.getElementById('purAccount'))">
          <option value="Cash">Cash (نقد)</option>
          <option value="Bank">Bank Deposit / Transfer</option>
          <option value="EasyPaisa">EasyPaisa</option>
          <option value="JazzCash">JazzCash</option>
          <option value="Credit">Credit / Payable (ادھار)</option>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Amount Paid to Supplier Now (Rs)</label>
        <div class="input-group">
          <span class="input-group-text">Rs</span>
          <input type="number" id="purPaid" class="form-control fw-bold" value="<?= isset($editPur) ? (float)$editPur['paid_amount'] : '' ?>" placeholder="0 if full credit purchase" step="any" oninput="calculatePurchaseTotals()">
        </div>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Payment Source Account</label>
        <select id="purAccount" class="form-select">
          <?php foreach ($accounts as $a): ?>
            <option value="<?= $a['id'] ?>" 
                    data-type="<?= htmlspecialchars($a['account_type']) ?>" 
                    data-name="<?= htmlspecialchars(strtolower($a['account_name'] . ' ' . ($a['bank_name'] ?? ''))) ?>"
                    <?= ($editPur && (int)$a['id'] === (int)$editPur['payment_account_id']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($a['account_name']) ?> (<?= $a['account_type'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-3">
        <label class="form-label">Purchase Discount (Rs)</label>
        <div class="input-group">
          <span class="input-group-text">Rs</span>
          <input type="number" id="purDiscount" class="form-control" value="<?= (isset($editPur) && (float)$editPur['discount'] > 0) ? (float)$editPur['discount'] : '' ?>" placeholder="0" onfocus="this.select()" step="any" oninput="calculatePurchaseTotals()">
        </div>
      </div>
    </div>
  </div>

  <!-- Grand Total Summary Box -->
  <div class="p-3 rounded-3 mb-4 text-white" style="background: linear-gradient(135deg, var(--nv2), var(--nv));">
    <div class="row align-items-center">
      <div class="col-12 col-md-4 mb-2 mb-md-0">
        <div class="small" style="opacity:0.85;">ITEMS BASE TOTAL:</div>
        <div class="fs-4 fw-bold text-white" id="purSubtotalDisplay">Rs 0.00</div>
      </div>
      <div class="col-12 col-md-4 mb-2 mb-md-0">
        <div class="small" style="opacity:0.85;">LANDED CHARGES CAPITALIZED:</div>
        <div class="fs-4 fw-bold text-warning" id="purChargesDisplay">+ Rs 0.00</div>
      </div>
      <div class="col-12 col-md-4 text-md-end">
        <div class="small" style="opacity:0.85;">FINAL TOTAL INVENTORY COST:</div>
        <div class="fs-3 fw-bold text-warning" id="purGrandDisplay">Rs 0.00</div>
      </div>
    </div>
  </div>

  <div class="d-flex justify-content-end gap-2">
    <a href="purchases.php" class="btn btn-outline-secondary px-4">Cancel</a>
    <button type="button" class="btn btn-zb-primary px-4 py-2 fs-5 fw-bold" id="btnSavePurchase" onclick="submitPurchaseForm()" <?= !$activeShift ? 'disabled title="Please open day shift first in Daily Closing"' : '' ?>>
      <i class="fa-solid fa-floppy-disk me-1"></i> Save Purchase &amp; Landed Cost (خریداری محفوظ کریں)
    </button>
  </div>
</div>

<script>
    const EDIT_PURCHASE_ID = <?= (int)($editPurId ?: 0) ?>;
  let purchaseItems = <?= !empty($editItems) ? json_encode(array_map(function($it) {
      return [
          'item_id' => (int)$it['item_id'],
          'name' => $it['name'],
          'unit' => $it['unit'],
          'qty' => (float)$it['qty'],
          'rate' => (float)$it['unit_rate'],
          'unit_rate' => (float)$it['unit_rate'],
          'line_discount' => (float)($it['line_discount'] ?? 0),
          'base_amount' => (float)$it['base_amount']
      ];
  }, $editItems)) : '[]' ?>;

  let purchaseCharges = <?= !empty($editCharges) ? json_encode(array_map(function($c) {
      return [
          'charge_name' => $c['charge_name'],
          'amount' => (float)$c['amount'],
          'payee_name' => $c['payee_name'] ?? '',
          'capitalize_inventory' => (int)$c['capitalize_inventory'],
          'allocation_method' => $c['allocation_method'] ?? 'weight'
      ];
  }, $editCharges)) : '[]' ?>;

  function addPurchaseLine() {
    const sel = document.getElementById('purItemSelect');
    const opt = sel.options[sel.selectedIndex];
    const qtyInput = document.getElementById('purItemQty');
    const rateInput = document.getElementById('purItemRate');

    const itemId = parseInt(sel.value);
    const itemName = opt.getAttribute('data-name') || opt.text.split(' (Current Cost:')[0].trim();
    const unit = opt.getAttribute('data-unit') || 'kg';
    const qty = parseFloat(qtyInput.value) || 0;
    const rate = parseFloat(rateInput.value) || 0;

    if (itemId <= 0 || qty <= 0 || rate <= 0) {
      alert('براہ کرم صحیح مقدار (Weight) اور خریداری ریٹ (Rate) درج کریں۔');
      return;
    }

    purchaseItems.push({
      item_id: itemId,
      name: itemName,
      unit: unit,
      qty: qty,
      unit_rate: rate,
      base_amount: qty * rate
    });

    qtyInput.value = '';
    rateInput.value = '';
    recalculateLandedDistribution();
  }

  function removePurchaseLine(idx) {
    purchaseItems.splice(idx, 1);
    recalculateLandedDistribution();
  }

  function addPurchaseCharge() {
    const nameInput = document.getElementById('chargeName');
    const amtInput = document.getElementById('chargeAmount');
    const methodInput = document.getElementById('chargeMethod');

    const name = nameInput.value.trim();
    const amt = parseFloat(amtInput.value) || 0;
    const method = methodInput.value;

    if (!name || amt <= 0) {
      alert('براہ کرم اضافی خرچہ کا نام اور رقم درج کریں۔');
      return;
    }

    purchaseCharges.push({
      charge_name: name,
      amount: amt,
      capitalize_inventory: 1,
      allocation_method: method
    });

    amtInput.value = '';
    recalculateLandedDistribution();
  }

  function removePurchaseCharge(idx) {
    purchaseCharges.splice(idx, 1);
    recalculateLandedDistribution();
  }

  function recalculateLandedDistribution() {
    const totalQty = purchaseItems.reduce((s, i) => s + i.qty, 0);
    const totalBase = purchaseItems.reduce((s, i) => s + i.base_amount, 0);
    const itemCount = purchaseItems.length;

    // Reset allocated amounts
    purchaseItems.forEach(it => { it.allocated = 0; });

    if (itemCount > 0) {
      purchaseCharges.forEach(c => {
        const cAmt = parseFloat(c.amount) || 0;
        if (cAmt <= 0) return;
        const method = (c.allocation_method || 'weight').toLowerCase();

        let chargeAllocSum = 0;
        purchaseItems.forEach((it, idx) => {
          let itemAlloc = 0;
          if (idx === itemCount - 1) {
            // Remainder to last item to prevent cent/paisa rounding discrepancy
            itemAlloc = Math.round((cAmt - chargeAllocSum) * 100) / 100;
          } else {
            if (method === 'weight' && totalQty > 0) {
              itemAlloc = Math.round((it.qty / totalQty) * cAmt * 100) / 100;
            } else if (method === 'value' && totalBase > 0) {
              itemAlloc = Math.round((it.base_amount / totalBase) * cAmt * 100) / 100;
            } else if (method === 'equal' && itemCount > 0) {
              itemAlloc = Math.round((cAmt / itemCount) * 100) / 100;
            } else {
              itemAlloc = totalQty > 0 ? Math.round((it.qty / totalQty) * cAmt * 100) / 100 : 0;
            }
            chargeAllocSum += itemAlloc;
          }
          it.allocated += itemAlloc;
        });
      });
    }

    purchaseItems.forEach(it => {
      it.allocated = Math.round(it.allocated * 100) / 100;
      it.landed_cost = Math.round((it.base_amount + it.allocated) * 100) / 100;
      it.landed_rate = it.qty > 0 ? (it.landed_cost / it.qty) : 0;
    });

    // Render items table
    const itemsTbody = document.getElementById('purItemsBody');
    if (!purchaseItems.length) {
      itemsTbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No items added yet. Select product and click "Add Line".</td></tr>';
    } else {
      itemsTbody.innerHTML = purchaseItems.map((it, idx) => {
        const name = it.name || 'Item';
        const qty = parseFloat(it.qty) || 0;
        const unit = it.unit || 'kg';
        const rate = parseFloat(it.unit_rate ?? it.rate) || 0;
        const base = parseFloat(it.base_amount) || (qty * rate);
        const allocated = parseFloat(it.allocated) || 0;
        const landedCost = parseFloat(it.landed_cost) || (base + allocated);
        const landedRate = qty > 0 ? (landedCost / qty) : rate;

        return `
        <tr>
          <td><strong>${name}</strong></td>
          <td>${qty.toLocaleString()} ${unit}</td>
          <td>Rs ${rate.toLocaleString()}</td>
          <td>Rs ${base.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
          <td class="text-primary fw-bold">+ Rs ${allocated.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
          <td class="fw-bold">Rs ${landedCost.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
          <td class="text-success fw-bold">Rs ${landedRate.toFixed(2)}/kg</td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removePurchaseLine(${idx})">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
      `;
      }).join('');
    }

    // Render charges table
    const chargesTbody = document.getElementById('purChargesBody');
    if (!purchaseCharges.length) {
      chargesTbody.innerHTML = '<tr><td colspan="5" class="text-center py-3 text-muted">No additional charges added.</td></tr>';
    } else {
      chargesTbody.innerHTML = purchaseCharges.map((c, idx) => `
        <tr>
          <td><strong>${c.charge_name}</strong></td>
          <td>Rs ${c.amount.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
          <td><span class="badge bg-success">Capitalized to Inventory</span></td>
          <td>
            <select class="form-select form-select-sm" style="max-width: 240px; font-weight: 600;" onchange="updatePurchaseChargeMethod(${idx}, this.value)">
              <option value="weight" ${(c.allocation_method === 'weight') ? 'selected' : ''}>By Weight / Qty (وزن کے حساب سے)</option>
              <option value="value" ${(c.allocation_method === 'value') ? 'selected' : ''}>By Item Value (مالیت / رقم کے حساب سے)</option>
              <option value="equal" ${(c.allocation_method === 'equal') ? 'selected' : ''}>Equally across items (برابر تقسیم)</option>
            </select>
          </td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removePurchaseCharge(${idx})">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
      `).join('');
    }

    calculatePurchaseTotals();
  }

  function updatePurchaseChargeMethod(idx, newMethod) {
    if (purchaseCharges[idx]) {
      purchaseCharges[idx].allocation_method = newMethod;
      const purPayInitEl = document.getElementById('purPayMethod');
  const purAccInitEl = document.getElementById('purAccount');
  if (purPayInitEl && purAccInitEl) {
    filterAccountsByPaymentMethod(purPayInitEl, purAccInitEl);
  }
  recalculateLandedDistribution();
    }
  }

  function calculatePurchaseTotals() {
    const subtotal = purchaseItems.reduce((s, i) => s + i.base_amount, 0);
    const charges = purchaseCharges.reduce((s, c) => s + c.amount, 0);
    const discount = parseFloat(document.getElementById('purDiscount').value) || 0;
    const grand = Math.max(0, (subtotal + charges) - discount);

    document.getElementById('purSubtotalDisplay').innerText = 'Rs ' + subtotal.toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('purChargesDisplay').innerText = '+ Rs ' + charges.toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('purGrandDisplay').innerText = 'Rs ' + grand.toLocaleString('en-US', { minimumFractionDigits: 2 });
  }

  async function submitPurchaseForm() {
    if (!purchaseItems.length) {
      alert('خریداری محفوظ کرنے کے لیے پہلے کم از کم ایک آئٹم شامل کریں۔');
      return;
    }

    const btn = document.getElementById('btnSavePurchase');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Processing Purchase & Landed Cost...';

    const formData = new FormData();
    formData.append('supplier_id', document.getElementById('purSupplier').value);
    formData.append('purchase_date', document.getElementById('purDate').value);
    formData.append('supplier_inv_no', document.getElementById('purInvNo').value);
    formData.append('items', JSON.stringify(purchaseItems));
    formData.append('charges', JSON.stringify(purchaseCharges));
    formData.append('paid_amount', document.getElementById('purPaid').value);
    formData.append('payment_method', document.getElementById('purPayMethod') ? document.getElementById('purPayMethod').value : 'Cash');
    formData.append('payment_account_id', document.getElementById('purAccount').value);
    formData.append('discount', document.getElementById('purDiscount').value);

    try {
          if (EDIT_PURCHASE_ID > 0) {
      formData.append('edit_purchase_id', EDIT_PURCHASE_ID);
    }
    const endpoint = (EDIT_PURCHASE_ID > 0) ? 'ajax/purchases.php?action=update' : 'ajax/purchases.php?action=create';
    const res = await fetch(endpoint, { method: 'POST', body: formData });
      const json = await res.json();
      if (json.success) {
        if (json.data && json.data.id) {
          window.location.href = 'print_purchase.php?id=' + json.data.id;
        } else {
          window.location.href = 'purchases.php';
        }
      } else {
        alert(json.message || 'خریداری محفوظ کرنے میں خرابی ہوئی۔');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save Purchase & Landed Cost';
      }
    } catch (err) {
      alert('سرور سے رابطہ ممکن نہیں ہو سکا۔');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-1"></i> Save Purchase & Landed Cost';
    }
  }
  <?php if ($editPur): ?>
    const purSupEl = document.getElementById('purSupplier');
    if (purSupEl) {
      purSupEl.value = "<?= (int)$editPur['supplier_id'] ?>";
    }
    const purAccEl = document.getElementById('purAccount');
    if (purAccEl && "<?= (int)($editPur['payment_account_id'] ?? 0) ?>" > 0) {
      purAccEl.value = "<?= (int)$editPur['payment_account_id'] ?>";
    }
  <?php endif; ?>
  const purPayInitEl = document.getElementById('purPayMethod');
  const purAccInitEl = document.getElementById('purAccount');
  if (purPayInitEl && purAccInitEl) {
    filterAccountsByPaymentMethod(purPayInitEl, purAccInitEl);
  }
  recalculateLandedDistribution();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
