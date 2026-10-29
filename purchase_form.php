<?php
// purchase_form.php - Dedicated Purchase Entry Form with Landed Cost
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';

requireAuth();
requirePerm('purchases');

$pageTitle = 'New Purchase Entry (خریداری اندراج)';
require_once __DIR__ . '/includes/header.php';

$db = getDB();
$suppliers = $db->query("SELECT id, name, current_balance FROM parties WHERE type = 'supplier' AND is_active = 1 ORDER BY name ASC")->fetchAll();
$items = $db->query("SELECT id, name, unit, cost_price FROM items WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, account_type FROM financial_accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
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

<div class="zb-card mb-4">
  <div class="row g-3 mb-3">
    <div class="col-12 col-md-5">
      <label class="form-label">Supplier / Steel Mill (سپلائر مل کا نام)</label>
      <select id="purSupplier" class="form-select">
        <?php foreach ($suppliers as $s): ?>
          <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?> (Payable: <?= formatMoney($s['current_balance']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label">Purchase Date (تاریخ)</label>
      <input type="date" id="purDate" class="form-control" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="col-6 col-md-4">
      <label class="form-label">Supplier Bilty / Invoice # (بلٹی / انوائس نمبر)</label>
      <input type="text" id="purInvNo" class="form-control" placeholder="e.g. MS-9872">
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
            <option value="<?= $it['id'] ?>" data-unit="<?= $it['unit'] ?>" data-cost="<?= $it['cost_price'] ?>">
              <?= htmlspecialchars($it['name']) ?> (Current Cost: Rs <?= number_format((float)$it['cost_price'], 2) ?>/kg)
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
        <label class="form-label">Charge Name / Description</label>
        <input type="text" id="chargeName" class="form-control" placeholder="e.g. Labour Unloading / Crane / Freight">
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
      <div class="col-12 col-md-4">
        <label class="form-label">Amount Paid to Supplier Now (Rs)</label>
        <div class="input-group">
          <span class="input-group-text">Rs</span>
          <input type="number" id="purPaid" class="form-control fw-bold" placeholder="0 if full credit purchase" step="any" oninput="calculatePurchaseTotals()">
        </div>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Payment Source Account</label>
        <select id="purAccount" class="form-select">
          <?php foreach ($accounts as $a): ?>
            <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (<?= $a['account_type'] ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 col-md-4">
        <label class="form-label">Purchase Discount (Rs)</label>
        <div class="input-group">
          <span class="input-group-text">Rs</span>
          <input type="number" id="purDiscount" class="form-control" value="0" step="any" oninput="calculatePurchaseTotals()">
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
    <button type="button" class="btn btn-zb-primary px-4 py-2 fs-5 fw-bold" id="btnSavePurchase" onclick="submitPurchaseForm()">
      <i class="fa-solid fa-floppy-disk me-1"></i> Save Purchase &amp; Landed Cost (خریداری محفوظ کریں)
    </button>
  </div>
</div>

<script>
  let purchaseItems = [];
  let purchaseCharges = [];

  function addPurchaseLine() {
    const sel = document.getElementById('purItemSelect');
    const opt = sel.options[sel.selectedIndex];
    const qtyInput = document.getElementById('purItemQty');
    const rateInput = document.getElementById('purItemRate');

    const itemId = parseInt(sel.value);
    const itemName = opt.text.split(' (Current Cost:')[0].trim();
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

    nameInput.value = '';
    amtInput.value = '';
    recalculateLandedDistribution();
  }

  function removePurchaseCharge(idx) {
    purchaseCharges.splice(idx, 1);
    recalculateLandedDistribution();
  }

  function recalculateLandedDistribution() {
    const totalCharges = purchaseCharges.reduce((s, c) => s + c.amount, 0);
    const totalQty = purchaseItems.reduce((s, i) => s + i.qty, 0);

    let allocatedSum = 0;
    purchaseItems.forEach((it, idx) => {
      let alloc = 0;
      if (totalCharges > 0 && totalQty > 0) {
        if (idx === purchaseItems.length - 1) {
          alloc = totalCharges - allocatedSum;
        } else {
          alloc = Math.round((it.qty / totalQty) * totalCharges * 100) / 100;
          allocatedSum += alloc;
        }
      }
      it.allocated = alloc;
      it.landed_cost = it.base_amount + alloc;
      it.landed_rate = it.qty > 0 ? (it.landed_cost / it.qty) : 0;
    });

    // Render items table
    const itemsTbody = document.getElementById('purItemsBody');
    if (!purchaseItems.length) {
      itemsTbody.innerHTML = '<tr><td colspan="8" class="text-center py-4 text-muted">No items added yet. Select product and click "Add Line".</td></tr>';
    } else {
      itemsTbody.innerHTML = purchaseItems.map((it, idx) => `
        <tr>
          <td><strong>${it.name}</strong></td>
          <td>${it.qty.toLocaleString()} ${it.unit}</td>
          <td>Rs ${it.unit_rate.toLocaleString()}</td>
          <td>Rs ${it.base_amount.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
          <td class="text-primary fw-bold">+ Rs ${it.allocated.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
          <td class="fw-bold">Rs ${it.landed_cost.toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
          <td class="text-success fw-bold">Rs ${it.landed_rate.toFixed(2)}/kg</td>
          <td class="text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removePurchaseLine(${idx})">
              <i class="fa-solid fa-trash"></i>
            </button>
          </td>
        </tr>
      `).join('');
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
          <td>By Weight (وزن)</td>
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
    formData.append('payment_account_id', document.getElementById('purAccount').value);
    formData.append('discount', document.getElementById('purDiscount').value);

    try {
      const res = await fetch('ajax/purchases.php?action=create', { method: 'POST', body: formData });
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
