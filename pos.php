<?php
// pos.php - New Sale Bill / POS
declare(strict_types=1);

$pageTitle = 'New Sale Bill (نیا بل بنائیں)';
require_once __DIR__ . '/includes/header.php';
requirePerm('pos');

$db = getDB();
$items = $db->query("SELECT id, item_code, name, unit, cost_price, sale_price, stock_qty FROM items WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
$customers = $db->query("SELECT id, name, phone, current_balance, is_walkin FROM parties WHERE type = 'customer' AND is_active = 1 ORDER BY (CASE WHEN is_walkin = 1 OR id = 1 OR name LIKE '%walk%' THEN 0 ELSE 1 END), name ASC")->fetchAll();
$accounts = $db->query("SELECT id, account_name, account_type FROM financial_accounts WHERE is_active = 1 ORDER BY id ASC")->fetchAll();
$companySettings = getCompanySettings();
$allowNegativeStock = ($companySettings['allow_negative_stock'] ?? '0') === '1';
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1" style="color:var(--nv);"><i class="fa-solid fa-cash-register me-2 text-warning"></i> New Sale Bill · نیا سیل بل</h3>
    <small class="text-secondary">Weight-based billing for steel rebars, girders, angle, and patti</small>
  </div>
  <div>
    <a href="bills.php" class="btn btn-outline-secondary"><i class="fa-solid fa-file-invoice me-1"></i> View All Bills</a>
  </div>
</div>

<!-- POS Keyboard Navigation Bar -->
<div class="d-flex align-items-center gap-2 mb-3 flex-wrap p-2 rounded border bg-light shadow-sm" style="font-size: 12px;">
  <span class="text-secondary fw-semibold"><i class="fa-solid fa-keyboard me-1 text-primary"></i> Keyboard Shortcuts (کی بورڈ):</span>
  <span class="badge bg-white text-dark border"><kbd>Enter</kbd> Next / Add Item</span>
  <span class="badge bg-white text-dark border"><kbd>F1</kbd> Item</span>
  <span class="badge bg-white text-dark border"><kbd>F2</kbd> Customer</span>
  <span class="badge bg-white text-dark border"><kbd>F3</kbd> Labour</span>
  <span class="badge bg-white text-dark border"><kbd>F4</kbd> Freight</span>
  <span class="badge bg-white text-dark border"><kbd>F7</kbd> Paid Amount</span>
  <span class="badge bg-success text-white"><kbd>F8</kbd> or <kbd>Ctrl+Enter</kbd> Save &amp; Print</span>
  <span class="badge <?= $allowNegativeStock ? 'bg-warning text-dark' : 'bg-primary text-white' ?> border">
    <i class="fa-solid <?= $allowNegativeStock ? 'fa-triangle-exclamation' : 'fa-shield-halved' ?> me-1"></i>
    Negative Stock: <?= $allowNegativeStock ? 'Allowed (منفی اسٹاک فعال)' : 'Disallowed (منفی اسٹاک بند)' ?>
  </span>
</div>

<!-- Alert Area for Live POS notifications -->
<div id="posAlertArea"></div>

<div class="row g-3">
  <!-- Left Column: Item Selection & Cart Table -->
  <div class="col-12 col-xl-7">
    <div class="zb-card mb-3">
      <div class="zb-card-title d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-cart-plus me-1 text-primary"></i> 1. Select Steel Product (آئٹم سلیکشن)</span>
        <span class="badge bg-light text-secondary border" style="font-size: 11px;">Shortcut: <kbd>F1</kbd></span>
      </div>

      <div class="row g-2 align-items-end mb-3">
        <div class="col-12 col-md-5">
          <label class="form-label">Product Name / Saria / Girder</label>
          <select id="itemSelect" class="form-select" onchange="onItemSelectChange()">
            <?php foreach ($items as $it): 
              $isOut = (float)$it['stock_qty'] <= 0;
            ?>
              <option value="<?= $it['id'] ?>" data-price="<?= $it['sale_price'] ?>" data-stock="<?= $it['stock_qty'] ?>" data-unit="<?= $it['unit'] ?>">
                <?= htmlspecialchars($it['name']) ?> (Stock: <?= formatQty($it['stock_qty'], $it['unit']) ?><?= ($isOut && !$allowNegativeStock) ? ' - Out of Stock' : '' ?>)
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
          <button type="button" class="btn btn-zb-gold w-100 fw-bold" id="addItemBtn" onclick="addItemToCart()">
            <i class="fa-solid fa-plus me-1"></i> Add <span class="badge bg-dark bg-opacity-25 ms-1" style="font-size: 10px;">↵ Enter</span>
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
                No items added yet. Select a product above and press <kbd>Enter</kbd> to add.
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
      <div class="zb-card-title d-flex justify-content-between align-items-center">
        <span><i class="fa-solid fa-file-invoice-dollar me-1 text-success"></i> 2. Customer &amp; Payment (کھاتہ و بل)</span>
        <span class="badge bg-light text-secondary border" style="font-size: 11px;">Shortcut: <kbd>F2</kbd></span>
      </div>

      <!-- Customer Selection -->
      <div class="row g-2 mb-2">
        <div class="col-8">
          <label class="form-label d-flex justify-content-between">
            <span>Customer (کسٹمر نام)</span>
            <span class="badge bg-light text-secondary border" style="font-size: 10px;">[F2]</span>
          </label>
          <select id="custSelect" class="form-select" onchange="onCustChange()">
            <?php 
            $walkInFound = false;
            foreach ($customers as $c): 
              $isWalkIn = (!empty($c['is_walkin']) || (int)$c['id'] === 1 || stripos((string)$c['name'], 'walk-in') !== false || stripos((string)$c['name'], 'walk in') !== false);
              $selectedAttr = '';
              if ($isWalkIn && !$walkInFound) {
                $selectedAttr = 'selected';
                $walkInFound = true;
              }
            ?>
              <option value="<?= $c['id'] ?>" data-name="<?= htmlspecialchars($c['name']) ?>" data-balance="<?= $c['current_balance'] ?>" data-phone="<?= htmlspecialchars($c['phone'] ?? '') ?>" data-is-walkin="<?= $isWalkIn ? '1' : '0' ?>" <?= $selectedAttr ?>>
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
          <label class="form-label" style="font-size: 12.5px;">Customer Name on Bill (اختیاری نام) </label>
          <input type="text" id="custNameCustom" class="form-control" placeholder="Optional name (e.g. Haji Aslam / Site Manager)">
        </div>
        <div class="col-5">
          <label class="form-label" style="font-size: 12.5px;">Phone / WhatsApp (اختیاری فون) </label>
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
            <label class="form-label d-flex justify-content-between">
              <span>Labour / Crane Charges</span>
              <span class="badge bg-white text-secondary border" style="font-size: 9.5px;">[F3]</span>
            </label>
            <div class="input-group">
              <span class="input-group-text">Rs</span>
              <input type="number" id="labourCharge" class="form-control" value="0" step="any" oninput="calculateBillTotals()">
            </div>
          </div>
          <div class="col-6">
            <label class="form-label d-flex justify-content-between">
              <span>Delivery / Freight Charges</span>
              <span class="badge bg-white text-secondary border" style="font-size: 9.5px;">[F4]</span>
            </label>
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
            <label class="form-label d-flex justify-content-between">
              <span>Amount Paid Now (وصولی)</span>
              <span class="badge bg-white text-secondary border" style="font-size: 9.5px;">[F7]</span>
            </label>
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
        <div class="small fw-normal opacity-75 mt-1" style="font-size: 12px;">
          <!-- <i class="fa-solid fa-keyboard me-1"></i> Press <strong>F8</strong> or <strong>Ctrl + Enter</strong> to Save -->
        </div>
      </button>
    </div>
  </div>
</div>

<script>
  const ALLOW_NEGATIVE_STOCK = <?= $allowNegativeStock ? 'true' : 'false' ?>;
  let cart = [];

  function onItemSelectChange() {
    const sel = document.getElementById('itemSelect');
    if (!sel || sel.selectedIndex < 0) return;
    const opt = sel.options[sel.selectedIndex];
    if (opt) {
      document.getElementById('itemRate').value = opt.getAttribute('data-price') || '';
    }
  }

  function onCustChange() {
    const sel = document.getElementById('custSelect');
    if (!sel || sel.selectedIndex < 0) return;
    const opt = sel.options[sel.selectedIndex];
    if (opt) {
      const bal = parseFloat(opt.getAttribute('data-balance')) || 0;
      const isWalkIn = (opt.getAttribute('data-is-walkin') === '1');
      const custCleanName = opt.getAttribute('data-name') || opt.text.split(' (Rs')[0].trim();
      const ph = opt.getAttribute('data-phone') || '';

      const disp = document.getElementById('custBalDisplay');
      disp.value = (bal < 0 ? '- Rs ' : 'Rs ') + Math.abs(bal).toLocaleString('en-US', { minimumFractionDigits: 2 });
      disp.style.color = bal > 0 ? 'var(--rd)' : 'var(--gn)';

      const notice = document.getElementById('walkInNotice');
      const paySelect = document.getElementById('paymentMethod');
      const creditOption = paySelect.querySelector('option[value="Credit"]');
      const paidInput = document.getElementById('paidAmount');
      const nameInput = document.getElementById('custNameCustom');
      const phoneInput = document.getElementById('custPhoneCustom');

      if (isWalkIn) {
        if (notice) notice.style.display = 'flex';
        if (creditOption) creditOption.disabled = true;
        if (paySelect.value === 'Credit') paySelect.value = 'Cash';
        paidInput.placeholder = 'Blank = Full Pay / Enter Advance (پیشگی)';
        nameInput.value = '';
        phoneInput.value = '';
      } else {
        if (notice) notice.style.display = 'none';
        if (creditOption) creditOption.disabled = false;
        paidInput.placeholder = 'Blank = Full Pay';
        nameInput.value = custCleanName;
        phoneInput.value = ph;
      }
      calculateBillTotals();
    }
  }

  function addItemToCart() {
    const sel = document.getElementById('itemSelect');
    if (!sel || sel.selectedIndex < 0) return;
    const opt = sel.options[sel.selectedIndex];
    const qtyInput = document.getElementById('itemQty');
    const rateInput = document.getElementById('itemRate');

    if (!opt) {
      alert('براہ کرم پہلے پروڈکٹ منتخب کریں۔');
      return;
    }

    const itemId = parseInt(sel.value);
    const itemName = opt.text.split(' (Stock:')[0].trim();
    const unit = opt.getAttribute('data-unit') || 'kg';
    const qty = parseFloat(qtyInput.value) || 0;
    const rate = parseFloat(rateInput.value) || 0;

    if (itemId <= 0 || qty <= 0 || rate <= 0) {
      alert('براہ کرم صحیح مقدار (Weight) اور ریٹ (Rate) درج کریں۔');
      if (qty <= 0) qtyInput.focus();
      else rateInput.focus();
      return;
    }

    const currentStock = parseFloat(opt.getAttribute('data-stock')) || 0;
    if (!ALLOW_NEGATIVE_STOCK) {
      const existingInCart = cart.filter(c => c.item_id === itemId).reduce((sum, c) => sum + c.qty, 0);
      const totalRequested = existingInCart + qty;
      if (totalRequested > currentStock) {
        alert(`اسٹاک ناکافی ہے!\n\nپروڈکٹ: ${itemName}\nدستیاب اسٹاک: ${currentStock} ${unit}\nبل میں کل مقدار: ${totalRequested} ${unit}\n\nسسٹم سیٹنگز کے مطابق منفی اسٹاک فروخت کی ممانعت ہے۔ براہ کرم پہلے خریداری درج کریں۔`);
        qtyInput.focus();
        qtyInput.select();
        return;
      }
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

    // Refocus item select so cashier can immediately select next product
    sel.focus();
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
            No items added yet. Select a product above and press <kbd>Enter</kbd> to add.
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
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeCartItem(${idx})" title="Remove Item">
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
    const opt = (sel && sel.selectedIndex >= 0) ? sel.options[sel.selectedIndex] : null;
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
      const itemSel = document.getElementById('itemSelect');
      if (itemSel) itemSel.focus();
      return;
    }

    const sel = document.getElementById('custSelect');
    const opt = (sel && sel.selectedIndex >= 0) ? sel.options[sel.selectedIndex] : null;
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
        const paidInput = document.getElementById('paidAmount');
        if (paidInput) { paidInput.focus(); paidInput.select(); }
        return;
      }
    }

    const custTitle = opt ? opt.text.split(' (Rs')[0].trim() : 'Customer';
    const netFormatted = 'Rs ' + grand.toLocaleString('en-US', { minimumFractionDigits: 2 });
    const paidFormatted = (paidVal !== '' && paidVal !== null) ? ('Rs ' + parseFloat(paidVal).toLocaleString('en-US', { minimumFractionDigits: 2 })) : netFormatted;

    const confirmMsg = `کیا آپ یہ سیل بل محفوظ کر کے پرنٹ کھولنا چاہتے ہیں؟\n` +
      `-----------------------------------------\n` +
      `گاہک (Customer): ${custTitle}\n` +
      `کل رقم (Total Bill): ${netFormatted}\n` +
      `وصولی (Paid Amount): ${paidFormatted}\n` +
      `-----------------------------------------\n` +
      `Confirm: Save sale and open print bill in a new tab?`;

    if (!confirm(confirmMsg)) {
      return;
    }

    const btn = document.getElementById('btnSubmitBill');
    if (!ALLOW_NEGATIVE_STOCK) {
      const sel = document.getElementById('itemSelect');
      const itemTotals = {};
      for (const it of cart) {
        itemTotals[it.item_id] = (itemTotals[it.item_id] || 0) + it.qty;
      }
      for (const it of cart) {
        const opt = sel ? sel.querySelector(`option[value="${it.item_id}"]`) : null;
        if (opt) {
          const availStock = parseFloat(opt.getAttribute('data-stock')) || 0;
          if (itemTotals[it.item_id] > availStock) {
            alert(`اسٹاک ناکافی ہے!\n\nپروڈکٹ: ${it.name}\nدستیاب اسٹاک: ${availStock} ${it.unit}\nبل میں کل مقدار: ${itemTotals[it.item_id]} ${it.unit}\n\nسسٹم سیٹنگز کے مطابق منفی اسٹاک فروخت کی اجازت نہیں ہے۔`);
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save &amp; Generate Bill (بل محفوظ کریں)<div class="small fw-normal opacity-75 mt-1" style="font-size: 12px;"><i class="fa-solid fa-keyboard me-1"></i> Press <strong>F8</strong> or <strong>Ctrl + Enter</strong> to Save</div>';
            return;
          }
        }
      }
    }

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
        const printUrl = `print_bill.php?id=${json.data.id}`;
        // Open bill print in new tab
        const win = window.open(printUrl, '_blank');

        const invoiceNo = json.data.invoice_no || `ID #${json.data.id}`;
        let alertMsg = `<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">` +
          `<div><i class="fa-solid fa-circle-check text-success fs-5 me-2"></i>` +
          `<strong>کامیابی!</strong> سیل بل <strong>${invoiceNo}</strong> کامیابی سے محفوظ ہو گیا اور نئے ٹیب میں پرنٹ کے لیے کھول دیا گیا ہے۔</div>` +
          `<div><a href="${printUrl}" target="_blank" class="btn btn-sm btn-success fw-bold"><i class="fa-solid fa-print me-1"></i> دوبارہ پرنٹ کھولیں (Re-open Print)</a></div>` +
          `</div>`;
        showPosAlert('success', alertMsg);

        resetPosForm();
      } else {
        alert(json.message || 'بل محفوظ کرنے میں غلطی واقع ہوئی۔');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save &amp; Generate Bill (بل محفوظ کریں)<div class="small fw-normal opacity-75 mt-1" style="font-size: 12px;"><i class="fa-solid fa-keyboard me-1"></i> Press <strong>F8</strong> or <strong>Ctrl + Enter</strong> to Save</div>';
      }
    } catch (err) {
      alert('سرور سے رابطہ ممکن نہیں ہو سکا۔');
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save &amp; Generate Bill (بل محفوظ کریں)<div class="small fw-normal opacity-75 mt-1" style="font-size: 12px;"><i class="fa-solid fa-keyboard me-1"></i> Press <strong>F8</strong> or <strong>Ctrl + Enter</strong> to Save</div>';
    }
  }

  function showPosAlert(type, html) {
    const area = document.getElementById('posAlertArea');
    if (area) {
      area.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show shadow-sm mb-3" role="alert">
          ${html}
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      `;
      area.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  }

  function resetPosForm() {
    cart = [];
    renderCart();
    document.getElementById('discountAmount').value = '0';
    document.getElementById('labourCharge').value = '0';
    document.getElementById('deliveryCharge').value = '0';
    document.getElementById('paidAmount').value = '';
    document.getElementById('paymentMethod').value = 'Cash';

    // Reset customer back to walk-in
    const custSel = document.getElementById('custSelect');
    if (custSel) {
      custSel.selectedIndex = 0;
      onCustChange();
    }
    calculateBillTotals();

    const btn = document.getElementById('btnSubmitBill');
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i> Save &amp; Generate Bill (بل محفوظ کریں)<div class="small fw-normal opacity-75 mt-1" style="font-size: 12px;"><i class="fa-solid fa-keyboard me-1"></i> Press <strong>F8</strong> or <strong>Ctrl + Enter</strong> to Save</div>';

    // Auto-focus on item select for the next bill
    const itemSel = document.getElementById('itemSelect');
    if (itemSel) itemSel.focus();
  }

  // Keyboard Navigation & Shortcuts Setup
  document.addEventListener('DOMContentLoaded', () => {
    const itemSel = document.getElementById('itemSelect');
    const itemQty = document.getElementById('itemQty');
    const itemRate = document.getElementById('itemRate');
    const custSel = document.getElementById('custSelect');
    const paidInput = document.getElementById('paidAmount');
    const labourInput = document.getElementById('labourCharge');
    const deliveryInput = document.getElementById('deliveryCharge');

    // Auto-focus on item select when page loads
    if (itemSel) {
      itemSel.focus();
    }

    // Step 1: In Item Select, pressing Enter moves focus to Qty
    if (itemSel) {
      itemSel.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (itemQty) {
            itemQty.focus();
            itemQty.select();
          }
        }
      });
    }

    // Step 2: In Qty, pressing Enter moves focus to Rate
    if (itemQty) {
      itemQty.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (itemRate) {
            itemRate.focus();
            itemRate.select();
          }
        }
      });
    }

    // Step 3: In Rate, pressing Enter adds item to cart and resets focus to Item Select
    if (itemRate) {
      itemRate.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          addItemToCart();
        }
      });
    }

    // Step 4: In Paid Amount, pressing Enter submits the bill
    if (paidInput) {
      paidInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          submitBill();
        }
      });
    }

    // Global Hotkeys for rapid cashier operation without mouse
    window.addEventListener('keydown', (e) => {
      // F1 or Alt+I: Focus Item Selection
      if (e.key === 'F1' || (e.altKey && (e.key === 'i' || e.key === 'I'))) {
        e.preventDefault();
        if (itemSel) itemSel.focus();
      }
      // F2 or Alt+C: Focus Customer Selection
      else if (e.key === 'F2' || (e.altKey && (e.key === 'c' || e.key === 'C'))) {
        e.preventDefault();
        if (custSel) custSel.focus();
      }
      // F3 or Alt+L: Focus Labour Charges
      else if (e.key === 'F3' || (e.altKey && (e.key === 'l' || e.key === 'L'))) {
        e.preventDefault();
        if (labourInput) { labourInput.focus(); labourInput.select(); }
      }
      // F4 or Alt+D: Focus Delivery Charges
      else if (e.key === 'F4' || (e.altKey && (e.key === 'd' || e.key === 'D'))) {
        e.preventDefault();
        if (deliveryInput) { deliveryInput.focus(); deliveryInput.select(); }
      }
      // F7 or Alt+P: Focus Amount Paid Now
      else if (e.key === 'F7' || (e.altKey && (e.key === 'p' || e.key === 'P'))) {
        e.preventDefault();
        if (paidInput) { paidInput.focus(); paidInput.select(); }
      }
      // F8 or Ctrl+Enter or Alt+S: Save & Generate Bill
      else if (e.key === 'F8' || (e.ctrlKey && e.key === 'Enter') || (e.altKey && (e.key === 's' || e.key === 'S'))) {
        e.preventDefault();
        submitBill();
      }
      // Alt+X: Remove last cart item
      else if (e.altKey && (e.key === 'x' || e.key === 'X')) {
        e.preventDefault();
        if (cart.length > 0) {
          removeCartItem(cart.length - 1);
        }
      }
    });
  });

  // Init on load
  onItemSelectChange();
  onCustChange();
  calculateBillTotals();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
