<?php
// pos.php - New Sale Bill / POS
declare(strict_types=1);

$pageTitle = 'New Sale Bill (نیا بل بنائیں)';
require_once __DIR__ . '/includes/header.php';
requirePerm('pos');

$db = getDB();
$items = $db->query("SELECT id, item_code, name, unit, cost_price, sale_price, stock_qty FROM items WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$customers = $db->query("SELECT id, name, phone, current_balance, is_walkin FROM parties WHERE type = 'customer' AND is_active = 1 ORDER BY name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, account_type FROM financial_accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1" style="color:var(--nv);"><i class="fa-solid fa-cash-register me-2 text-warning"></i> New Sale Bill · نیا سیل بل</h3>
    <small class="text-secondary">Weight-based billing for steel rebars, girders, angle, and patti</small>
  </div>
  <div>
    <a href="bills.php" class="btn btn-outline-secondary"><i class="fa-solid fa-file-invoice me-1"></i> View All Bills</a>
  </div>
</div>

<div class="row g-3">
  <!-- Left Column: Item Selection & Cart Table -->
  <div class="col-12 col-xl-7">
    <div class="zb-card mb-3">
      <div class="zb-card-title">
        <span><i class="fa-solid fa-cart-plus me-1 text-primary"></i> 1. Select Steel Product (آئٹم سلیکشن)</span>
      </div>

      <div class="row g-2 align-items-end mb-3">
        <div class="col-12 col-md-5">
          <label class="form-label">Product Name / Saria / Girder</label>
          <select id="itemSelect" class="form-select" onchange="onItemSelectChange()">
            <?php foreach ($items as $it): ?>
              <option value="<?= $it['id'] ?>" data-price="<?= $it['sale_price'] ?>" data-stock="<?= $it['stock_qty'] ?>" data-unit="<?= $it['unit'] ?>">
                <?= htmlspecialchars($it['name']) ?> (Stock: <?= formatQty($it['stock_qty'], $it['unit']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-6 col-md-3">
          <label class="form-label">Weight / Qty (kg)</label>
          <input type="number" id="itemQty" class="form-control" placeholder="e.g. 500" step="any">
        </div>

        <div class="col-6 col-md-2">
          <label class="form-label">Rate (Rs/kg)</label>
          <input type="number" id="itemRate" class="form-control" placeholder="Rate" step="any">
        </div>

        <div class="col-12 col-md-2">
          <button type="button" class="btn btn-zb-gold w-100" onclick="addItemToCart()">
            <i class="fa-solid fa-plus me-1"></i> Add
          </button>
        </div>
      </div>

      <!-- Cart Table -->
      <div class="table-responsive border rounded" style="max-height: 380px;">
        <table class="table table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Product</th>
              <th>Qty (kg)</th>
              <th>Rate (Rs)</th>
              <th class="text-end">Total Amount</th>
              <th class="text-center" style="width: 50px;">Action</th>
            </tr>
          </thead>
          <tbody id="cartTableBody">
            <tr id="emptyCartRow">
              <td colspan="5" class="text-center py-4 text-muted">
                <i class="fa-solid fa-cart-shopping fs-2 mb-2 d-block text-secondary opacity-50"></i>
                No items added yet. Select a product above and click "Add".
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Right Column: Customer Details, Additional Charges & Checkout -->
  <div class="col-12 col-xl-5">
    <div class="zb-card">
      <div class="zb-card-title">
        <span><i class="fa-solid fa-file-invoice-dollar me-1 text-success"></i> 2. Customer &amp; Payment (کھاتہ و بل)</span>
      </div>

      <!-- Customer Selection -->
      <div class="row g-2 mb-2">
        <div class="col-8">
          <label class="form-label">Customer (کسٹمر نام)</label>
          <select id="custSelect" class="form-select" onchange="onCustChange()">
            <?php foreach ($customers as $c): ?>
              <?php $isWalkIn = (!empty($c['is_walkin']) || (int)$c['id'] === 1 || stripos((string)$c['name'], 'walk-in') !== false || stripos((string)$c['name'], 'walk in') !== false); ?>
              <option value="<?= $c['id'] ?>" data-balance="<?= $c['current_balance'] ?>" data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>" data-is-walkin="<?= $isWalkIn ? '1' : '0' ?>">
                <?= htmlspecialchars($c['name']) ?> (<?= formatMoney($c['current_balance']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-4">
          <label class="form-label">Previous Balance</label>
          <input type="text" id="custBalDisplay" class="form-control fw-bold bg-light" readonly value="Rs 0.00">
        </div>
      </div>

      <!-- Optional Customer Name & Phone for Invoice Printing -->
      <div class="row g-2 mb-2">
        <div class="col-7">
          <label class="form-label" style="font-size: 12.5px;">Customer Name on Bill (اختیاری نام) <span class="text-muted small">(Optional)</span></label>
          <input type="text" id="custNameCustom" class="form-control" placeholder="Optional name (e.g. Haji Aslam / Site Manager)">
        </div>
        <div class="col-5">
          <label class="form-label" style="font-size: 12.5px;">Phone / WhatsApp (اختیاری فون) <span class="text-muted small">(Optional)</span></label>
          <input type="text" id="custPhoneCustom" class="form-control" placeholder="Optional 0300-1234567">
        </div>
      </div>

      <!-- Walk-in Customer Credit Warning Banner -->
      <div id="walkInNotice" class="alert alert-info py-2 px-3 mb-3 d-flex align-items-center gap-2" style="font-size: 12.5px; border-radius: 8px;">
        <i class="fa-solid fa-truck-ramp-box text-primary fs-5"></i>
        <div>
          <strong>Walk-in Policy (واک ان گاہک):</strong>
          <span>Advance payment accepted; any remaining balance is payable upon site delivery/unloading (پیشگی وصولی + بقیہ رقم موقع پر مال اتروائی پر واجب الادا). Pure credit is not allowed.</span>
        </div>
      </div>

      <!-- Charges & Discounts -->
      <div class="p-3 bg-light rounded-3 border mb-3">
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label">Labour / Crane Charges</label>
            <div class="input-group">
              <span class="input-group-text">Rs</span>
              <input type="number" id="labourCharge" class="form-control" value="0" step="any" oninput="calculateBillTotals()">
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Delivery / Freight Charges</label>
            <div class="input-group">
              <span class="input-group-text">Rs</span>
              <input type="number" id="deliveryCharge" class="form-control" value="0" step="any" oninput="calculateBillTotals()">
            </div>
          </div>
        </div>

        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label">Special Discount (رعایت)</label>
            <div class="input-group">
              <span class="input-group-text">Rs</span>
              <input type="number" id="discountAmount" class="form-control" value="0" step="any" oninput="calculateBillTotals()">
            </div>
          </div>
          <div class="col-6">
            <label class="form-label">Payment Method</label>
            <select id="paymentMethod" class="form-select">
              <option value="Cash">Cash (نقد)</option>
              <option value="Bank">Bank Deposit</option>
              <option value="EasyPaisa">EasyPaisa</option>
              <option value="JazzCash">JazzCash</option>
              <option value="Credit">Credit (ادھار کھاتہ)</option>
            </select>
          </div>
        </div>

        <div class="row g-2">
          <div class="col-6">
            <label class="form-label">Deposit In Account</label>
            <select id="depositAccount" class="form-select">
              <?php if (empty($accounts)): ?>
                <option value="1">Main Cash Register (Cash)</option>
              <?php else: ?>
                <?php foreach ($accounts as $a): ?>
                  <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?> (<?= $a['account_type'] ?>)</option>
                <?php endforeach; ?>
              <?php endif; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">Amount Paid Now (وصولی)</label>
            <div class="input-group">
              <span class="input-group-text">Rs</span>
              <input type="number" id="paidAmount" class="form-control fw-bold" placeholder="Blank = Full Pay" step="any" oninput="calculateBillTotals()">
            </div>
          </div>
        </div>
      </div>

      <!-- Big Bill Total Display -->
      <div class="p-3 rounded-3 mb-3 text-white" style="background: linear-gradient(135deg, var(--nv2), var(--nv));">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="text-uppercase small" style="opacity:0.85;">Net Total Bill Amount:</span>
          <span class="fs-3 fw-bold text-warning" id="grandTotalDisplay">Rs 0.00</span>
        </div>
        <div class="d-flex justify-content-between align-items-center">
          <span class="small" style="opacity:0.85;" id="dueAmountDisplayLabel">Remaining Balance Due:</span>
          <span class="fs-5 fw-bold" style="color:#ff999c;" id="dueAmountDisplay">Rs 0.00</span>
        </div>
      </div>

      <!-- Submit Button -->
      <button type="button" class="btn btn-zb-primary w-100 py-3 fs-5 fw-bold" id="btnSubmitBill" onclick="submitBill()">
        <i class="fa-solid fa-floppy-disk me-2"></i> Save &amp; Generate Bill (بل محفوظ کریں)
      </button>
    </div>
  </div>
</div>

<script>
  let cart = [];

  function onItemSelectChange() {
    const sel = document.getElementById('itemSelect');
    const opt = sel.options[sel.selectedIndex];
    if (opt) {
      document.getElementById('itemRate').value = opt.getAttribute('data-price') || '';
    }
  }

  function onCustChange() {
    const sel = document.getElementById('custSelect');
    const opt = sel.options[sel.selectedIndex];
    if (opt) {
      const bal = parseFloat(opt.getAttribute('data-balance')) || 0;
      const isWalkIn = (opt.getAttribute('data-is-walkin') === '1');
      const disp = document.getElementById('custBalDisplay');
      disp.value = (bal < 0 ? '- Rs ' : 'Rs ') + Math.abs(bal).toLocaleString('en-US', { minimumFractionDigits: 2 });
      disp.style.color = bal > 0 ? 'var(--rd)' : 'var(--gn)';

      const notice = document.getElementById('walkInNotice');
      const paySelect = document.getElementById('paymentMethod');
      const creditOption = paySelect.querySelector('option[value="Credit"]');
      const paidInput = document.getElementById('paidAmount');

      if (isWalkIn) {
        if (notice) notice.style.display = 'flex';
        if (creditOption) creditOption.disabled = true;
        if (paySelect.value === 'Credit') paySelect.value = 'Cash';
        paidInput.placeholder = 'Blank = Full Pay / Enter Advance (پیشگی)';
      } else {
        if (notice) notice.style.display = 'none';
        if (creditOption) creditOption.disabled = false;
        paidInput.placeholder = 'Blank = Full Pay';
      }
      const ph = opt.getAttribute('data-phone') || '';
      const phoneInput = document.getElementById('custPhoneCustom');
      if (phoneInput && !phoneInput.value && ph) {
        phoneInput.value = ph;
      }
      calculateBillTotals();
    }
  }

  function addItemToCart() {
    const sel = document.getElementById('itemSelect');
    const opt = sel.options[sel.selectedIndex];
    const qtyInput = document.getElementById('itemQty');
    const rateInput = document.getElementById('itemRate');

    const itemId = parseInt(sel.value);
    const itemName = opt.text.split(' (Stock:')[0].trim();
    const unit = opt.getAttribute('data-unit') || 'kg';
    const qty = parseFloat(qtyInput.value) || 0;
    const rate = parseFloat(rateInput.value) || 0;

    if (itemId <= 0 || qty <= 0 || rate <= 0) {
      alert('براہ کرم صحیح مقدار (Weight) اور ریٹ (Rate) درج کریں۔');
      return;
    }

    cart.push({
      item_id: itemId,
      name: itemName,
      unit: unit,
      qty: qty,
      rate: rate,
      unit_rate: rate,
      line_total: qty * rate
    });

    qtyInput.value = '';
    renderCart();
    calculateBillTotals();
  }

  function removeCartItem(index) {
    cart.splice(index, 1);
    renderCart();
    calculateBillTotals();
  }

  function renderCart() {
    const tbody = document.getElementById('cartTableBody');
    if (!cart.length) {
      tbody.innerHTML = `
        <tr id="emptyCartRow">
          <td colspan="5" class="text-center py-4 text-muted">
            <i class="fa-solid fa-cart-shopping fs-2 mb-2 d-block text-secondary opacity-50"></i>
            No items added yet. Select a product above and click "Add".
          </td>
        </tr>
      `;
      return;
    }

    tbody.innerHTML = cart.map((it, idx) => `
      <tr>
        <td><strong>${it.name}</strong></td>
        <td>${it.qty.toLocaleString()} ${it.unit}</td>
        <td>Rs ${it.unit_rate.toLocaleString()}</td>
        <td class="text-end fw-bold">Rs ${(it.line_total).toLocaleString('en-US', { minimumFractionDigits: 2 })}</td>
        <td class="text-center">
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeCartItem(${idx})">
            <i class="fa-solid fa-trash"></i>
          </button>
        </td>
      </tr>
    `).join('');
  }

  function calculateBillTotals() {
    const subtotal = cart.reduce((s, x) => s + x.line_total, 0);
    const labour = parseFloat(document.getElementById('labourCharge').value) || 0;
    const delivery = parseFloat(document.getElementById('deliveryCharge').value) || 0;
    const discount = parseFloat(document.getElementById('discountAmount').value) || 0;

    const grand = Math.max(0, (subtotal + labour + delivery) - discount);
    
    const sel = document.getElementById('custSelect');
    const opt = sel.options[sel.selectedIndex];
    const isWalkIn = opt ? (opt.getAttribute('data-is-walkin') === '1') : false;
    const paidInput = document.getElementById('paidAmount');
    
    let paid = 0;
    let due = 0;

    paidInput.classList.remove('is-invalid');
    paid = (paidInput.value === '' || paidInput.value === null) ? grand : parseFloat(paidInput.value) || 0;
    due = Math.max(0, grand - paid);

    const dueLabel = document.getElementById('dueAmountDisplayLabel');
    if (dueLabel) {
      dueLabel.innerText = (isWalkIn && due > 0) ? 'Remaining on Delivery (مال اتروائی پر بقیہ):' : 'Remaining Balance Due:';
    }

    document.getElementById('grandTotalDisplay').innerText = 'Rs ' + grand.toLocaleString('en-US', { minimumFractionDigits: 2 });
    document.getElementById('dueAmountDisplay').innerText = 'Rs ' + due.toLocaleString('en-US', { minimumFractionDigits: 2 });
  }

  async function submitBill() {
    if (!cart.length) {
      alert('بل بنانے کے لیے پہلے کم از کم ایک آئٹم شامل کریں۔');
      return;
    }

    const sel = document.getElementById('custSelect');
    const opt = sel.options[sel.selectedIndex];
    const isWalkIn = opt ? (opt.getAttribute('data-is-walkin') === '1') : false;
    const payMethod = document.getElementById('paymentMethod').value;
    const paidVal = document.getElementById('paidAmount').value;

    const subtotal = cart.reduce((s, x) => s + x.line_total, 0);
    const labour = parseFloat(document.getElementById('labourCharge').value) || 0;
    const delivery = parseFloat(document.getElementById('deliveryCharge').value) || 0;
    const discount = parseFloat(document.getElementById('discountAmount').value) || 0;
    const grand = Math.max(0, (subtotal + labour + delivery) - discount);

    if (isWalkIn) {
      if (payMethod === 'Credit' && (!paidVal || parseFloat(paidVal) <= 0)) {
        alert('واک ان گاہک کے لیے 100% ادھار کی اجازت نہیں ہے۔ برائے مہربانی کم از کم پیشگی رقم درج کریں، بقیہ رقم مال اتروائی پر وصول کی جائے گی۔');
        return;
      }
    }

    const btn = document.getElementById('btnSubmitBill');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i> Saving Bill...';

    const formData = new FormData();
    formData.append('customer_id', document.getElementById('custSelect').value);
    formData.append('items', JSON.stringify(cart));
    formData.append('discount', document.getElementById('discountAmount').value);
    formData.append('labour_charge', document.getElementById('labourCharge').value);
    formData.append('delivery_charge', document.getElementById('deliveryCharge').value);
    formData.append('payment_method', document.getElementById('paymentMethod').value);
    formData.append('payment_account_id', document.getElementById('depositAccount').value);
    formData.append('paid_amount', document.getElementById('paidAmount').value);
    formData.append('customer_name_custom', document.getElementById('custNameCustom').value.trim());
    formData.append('customer_phone_custom', document.getElementById('custPhoneCustom').value.trim());

    try {
      const res = await fetch('ajax/sales.php?action=create', { method: 'POST', body: formData });
      const json = await res.json();
      if (json.success) {
        window.location.href = `print_bill.php?id=${json.data.id}`;
      } else {
        alert(json.message || 'بل محفوظ کرنے میں غلطی واقع ہوئی۔');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save & Generate Bill (بل محفوظ کریں)';
      }
    } catch (err) {
      alert('سرور سے رابطہ ممکن نہیں ہو سکا۔');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save & Generate Bill (بل محفوظ کریں)';
    }
  }

  // Init on load
  onItemSelectChange();
  onCustChange();
  calculateBillTotals();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
