<?php
// bills.php - Sales Bills List (Multi-Page)
declare(strict_types=1);

$pageTitle = 'Sales Bills (سیل بلز)';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/csrf.php';
requirePerm('bills');

$db = getDB();
$search = trim((string)($_GET['search'] ?? ''));
$fromDate = trim((string)($_GET['from_date'] ?? ''));
$toDate = trim((string)($_GET['to_date'] ?? ''));

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(s.invoice_no LIKE ? OR p.name LIKE ? OR p.phone LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($fromDate !== '') {
    $where[] = "s.sale_date >= ?";
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $where[] = "s.sale_date <= ?";
    $params[] = $toDate;
}

$whereSql = implode(' AND ', $where);

// Total counts & stats
$statsStmt = $db->prepare("
    SELECT COUNT(s.id), COALESCE(SUM(s.grand_total), 0), COALESCE(SUM(s.paid_amount), 0), COALESCE(SUM(s.due_amount), 0)
    FROM sales s
    JOIN parties p ON s.customer_id = p.id
    WHERE {$whereSql}
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch(PDO::FETCH_NUM);
$totalCount = (int)$stats[0];
$totalGrand = (float)$stats[1];
$totalPaid = (float)$stats[2];
$totalDue = (float)$stats[3];

// Bills list
$stmt = $db->prepare("
    SELECT 
        s.id, s.invoice_no, s.sale_date, s.sale_time, s.customer_id, s.customer_name_custom, s.customer_phone_custom,
        p.name AS customer_name, p.phone AS customer_phone,
        s.subtotal, s.labour_charge, s.delivery_charge, s.additional_charges, s.discount, s.grand_total, s.paid_amount, s.due_amount,
        s.payment_status, s.payment_method, (s.grand_total - s.total_cogs) AS profit, s.status
    FROM sales s
    JOIN parties p ON s.customer_id = p.id
    WHERE {$whereSql}
    ORDER BY s.id DESC
    LIMIT 100
");
$stmt->execute($params);
$bills = $stmt->fetchAll();
$isOwner = ($role === 'Owner');

$finAccounts = $db->query("
    SELECT id, account_name, account_type, bank_name, account_number, current_balance 
    FROM financial_accounts 
    WHERE is_active = 1 
    ORDER BY account_type ASC, id ASC
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div>
    <h3 class="fw-bold mb-1" style="color:var(--nv);"><i class="fa-solid fa-file-invoice me-2 text-primary"></i> Sales Bills List · فروخت شدہ بلز</h3>
    <small class="text-secondary">Total <?= $totalCount ?> bills found · Total: <?= formatMoney($totalGrand) ?> · Due: <span class="text-danger fw-bold"><?= formatMoney($totalDue) ?></span></small>
  </div>
  <div>
    <a href="pos.php" class="btn btn-zb-gold"><i class="fa-solid fa-plus me-1"></i> New Bill (نیا بل بنائیں)</a>
  </div>
</div>

<div class="zb-card">
  <!-- Search & Filter Form -->
  <form method="GET" action="bills.php" class="row g-2 align-items-end mb-3">
    <div class="col-12 col-md-5">
      <label class="form-label">Search Invoice #, Customer or Phone</label>
      <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="e.g. BILL-2026 or Haji Munir">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label">From Date</label>
      <input type="date" name="from_date" class="form-control" value="<?= htmlspecialchars($fromDate) ?>">
    </div>
    <div class="col-6 col-md-3">
      <label class="form-label">To Date</label>
      <input type="date" name="to_date" class="form-control" value="<?= htmlspecialchars($toDate) ?>">
    </div>
    <div class="col-12 col-md-1">
      <button type="submit" class="btn btn-zb-primary w-100"><i class="fa-solid fa-magnifying-glass"></i></button>
    </div>
  </form>

  <!-- Table -->
  <div class="table-responsive">
    <table class="table table-hover align-middle">
      <thead>
        <tr>
          <th>Invoice #</th>
          <th>Date &amp; Time</th>
          <th>Customer</th>
          <th>Grand Total</th>
          <th>Paid</th>
          <th>Due</th>
          <th>Status</th>
          <?php if ($isOwner): ?><th>Profit</th><?php endif; ?>
          <th class="text-end">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($bills)): ?>
          <tr><td colspan="<?= $isOwner ? 9 : 8 ?>" class="text-center py-4 text-muted">No sales bills found.</td></tr>
        <?php else: ?>
          <?php foreach ($bills as $b): ?>
            <tr>
              <td><strong><?= htmlspecialchars($b['invoice_no']) ?></strong></td>
              <td><?= $b['sale_date'] ?> <small class="text-muted"><?= $b['sale_time'] ?></small></td>
              <td>
                <?php if (!empty($b['customer_name_custom'])): ?>
                  <strong><?= htmlspecialchars($b['customer_name_custom']) ?></strong>
                  <br><small class="text-muted"><?= htmlspecialchars($b['customer_name']) ?></small>
                <?php else: ?>
                  <strong><?= htmlspecialchars($b['customer_name']) ?></strong>
                <?php endif; ?>
                <?php 
                  $dispP = !empty($b['customer_phone_custom']) ? $b['customer_phone_custom'] : ($b['customer_phone'] ?? '');
                  if (!empty($dispP)): 
                ?>
                  <br><small class="text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($dispP) ?></small>
                <?php endif; ?>
              </td>
              <td class="fw-bold"><?= formatMoney($b['grand_total']) ?></td>
              <td><?= formatMoney($b['paid_amount']) ?></td>
              <td class="<?= (float)$b['due_amount'] > 0 ? 'text-danger fw-bold' : 'text-muted' ?>"><?= formatMoney($b['due_amount']) ?></td>
              <td>
                <?php if ($b['status'] === 'cancelled'): ?>
                  <span class="badge bg-secondary">Cancelled</span>
                <?php elseif ($b['payment_status'] === 'Paid'): ?>
                  <span class="badge-paid">Paid</span>
                <?php elseif ($b['payment_status'] === 'Partial'): ?>
                  <span class="badge-partial">Partial</span>
                <?php else: ?>
                  <span class="badge-unpaid">Unpaid</span>
                <?php endif; ?>
              </td>
              <?php if ($isOwner): ?>
                <td class="text-success fw-bold"><?= formatMoney($b['profit']) ?></td>
              <?php endif; ?>
              <td class="text-end text-nowrap">
                <?php if ($b['status'] !== 'cancelled' && (float)$b['due_amount'] > 0.009): ?>
                  <button type="button" 
                          class="btn btn-sm btn-success me-1 btn-pay-bill" 
                          data-id="<?= (int)$b['id'] ?>"
                          data-invoice="<?= htmlspecialchars($b['invoice_no'], ENT_QUOTES) ?>"
                          data-customer="<?= htmlspecialchars(!empty($b['customer_name_custom']) ? $b['customer_name_custom'] : $b['customer_name'], ENT_QUOTES) ?>"
                          data-grand="<?= (float)$b['grand_total'] ?>"
                          data-paid="<?= (float)$b['paid_amount'] ?>"
                          data-due="<?= (float)$b['due_amount'] ?>"
                          data-bs-toggle="modal" 
                          data-bs-target="#billPaymentModal"
                          onclick="openPayModalDirect(this)"
                          title="Receive Payment for this Bill">
                    <i class="fa-solid fa-hand-holding-dollar me-1"></i> Pay / وصولی
                  </button>
                <?php endif; ?>
                <a href="print_bill.php?id=<?= $b['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1"><i class="fa-solid fa-print me-1"></i> Print</a>
                <?php if ($isOwner && $b['status'] === 'posted'): ?>
                  <button type="button" class="btn btn-sm btn-outline-danger" onclick="cancelSaleBill(<?= $b['id'] ?>, '<?= $b['invoice_no'] ?>')">
                    Cancel
                  </button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>


<!-- Modal: Receive Bill Payment (بل وصولی) -->
<div class="modal fade" id="billPaymentModal" tabindex="-1" aria-labelledby="billPaymentModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius: 12px; overflow: hidden; border: none; box-shadow: 0 10px 25px rgba(0,0,0,0.15);">
      <div class="modal-header text-white" style="background: var(--nv, #14307a);">
        <h5 class="modal-title fw-bold" id="billPaymentModalLabel">
          <i class="fa-solid fa-hand-holding-dollar text-warning me-2"></i> Receive Bill Payment · بل کی وصولی
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="closePayModal()"></button>
      </div>
      <form id="billPaymentForm" onsubmit="submitBillPayment(event)">
        <input type="hidden" name="sale_id" id="pay_sale_id">
        <?= getCSRFInput() ?>

        <div class="modal-body p-4">
          <!-- Bill Summary Banner -->
          <div class="p-3 mb-3 rounded" style="background: #f8fafc; border: 1px solid #cbd5e1;">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="text-muted small">Invoice # (بل نمبر):</span>
              <strong class="font-num text-primary fs-6" id="pay_invoice_no">-</strong>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1">
              <span class="text-muted small">Customer (گاہک):</span>
              <strong id="pay_customer_name">-</strong>
            </div>
            <hr class="my-2">
            <div class="d-flex justify-content-between align-items-center mb-1 small">
              <span class="text-muted">Total Bill (کل بل):</span>
              <span class="font-num fw-bold" id="pay_grand_total">-</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-1 small">
              <span class="text-muted">Already Paid (سابقہ وصولی):</span>
              <span class="font-num text-success fw-bold" id="pay_already_paid">-</span>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-2 border-top">
              <strong class="text-danger">Remaining Due (بقایا بل):</strong>
              <strong class="font-num text-danger fs-5" id="pay_due_badge">-</strong>
            </div>
          </div>

          <!-- Form Fields -->
          <div class="row g-3">
            <!-- Payment Date -->
            <div class="col-6">
              <label class="form-label small fw-bold">Payment Date (تاریخ) <span class="text-danger">*</span></label>
              <input type="date" name="payment_date" id="pay_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
            </div>

            <!-- Amount to Pay -->
            <div class="col-6">
              <div class="d-flex justify-content-between align-items-center">
                <label class="form-label small fw-bold">Amount (وصول رقم) <span class="text-danger">*</span></label>
                <button type="button" class="btn btn-link p-0 text-decoration-none small text-primary fw-bold" onclick="setFullDueAmount()" style="font-size: 11.5px;">
                  Full Due
                </button>
              </div>
              <div class="input-group">
                <span class="input-group-text">Rs</span>
                <input type="number" step="0.01" min="0.01" name="amount" id="pay_amount" class="form-control fw-bold text-success font-num fs-6" required>
              </div>
              <div class="form-text text-muted" style="font-size: 11px;">
                Enter full or partial delivery payment.
              </div>
            </div>

            <!-- Receiving Account -->
            <div class="col-12 col-md-6">
              <label class="form-label small fw-bold">Receiving Account (کھاتہ) <span class="text-danger">*</span></label>
              <select name="account_id" id="pay_account_id" class="form-select" required onchange="onAccountChange(this)">
                <?php foreach ($finAccounts as $fa): ?>
                  <option value="<?= $fa['id'] ?>" data-type="<?= htmlspecialchars($fa['account_type']) ?>">
                    <?= htmlspecialchars($fa['account_name']) ?> (<?= $fa['account_type'] ?>) - Rs <?= number_format((float)$fa['current_balance'], 2) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <!-- Payment Mode -->
            <div class="col-12 col-md-6">
              <label class="form-label small fw-bold">Payment Mode (طریقہ)</label>
              <select name="payment_mode" id="pay_mode" class="form-select">
                <option value="Cash">Cash (نقد)</option>
                <option value="Bank Transfer">Bank Transfer (آن لائن ٹرانسفر)</option>
                <option value="Cheque">Bank Cheque (بینک چیک)</option>
                <option value="EasyPaisa/JazzCash">EasyPaisa / JazzCash</option>
                <option value="Other">Other</option>
              </select>
            </div>

            <!-- Reference / Cheque # -->
            <div class="col-12">
              <label class="form-label small fw-bold">Ref / Cheque # / Trx ID <span class="text-muted fw-normal">(Optional)</span></label>
              <input type="text" name="cheque_no" id="pay_cheque_no" class="form-control" placeholder="e.g. Delivery Cash / Chq # 55102 / Trx 8829">
            </div>

            <!-- Notes / Narration -->
            <div class="col-12">
              <label class="form-label small fw-bold">Remarks / Narration (تفصیلات)</label>
              <input type="text" name="narration" id="pay_narration" class="form-control" placeholder="e.g. Received on delivery by driver">
            </div>
          </div>

          <div id="pay_error_alert" class="alert alert-danger mt-3 d-none mb-0 py-2 small"></div>
        </div>

        <div class="modal-footer bg-light px-4 py-3">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" onclick="closePayModal()">Cancel (منسوخ)</button>
          <button type="submit" id="pay_submit_btn" class="btn btn-success fw-bold px-4">
            <i class="fa-solid fa-check me-1"></i> Confirm Payment (وصولی درج کریں)
          </button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
  let currentDueAmount = 0;
  let payModalInstance = null;

  function openPayModalDirect(btn) {
    if (!btn) return;
    const sale = {
      id: btn.getAttribute('data-id'),
      invoice_no: btn.getAttribute('data-invoice') || '',
      customer_name: btn.getAttribute('data-customer') || '',
      grand_total: parseFloat(btn.getAttribute('data-grand')) || 0,
      paid_amount: parseFloat(btn.getAttribute('data-paid')) || 0,
      due_amount: parseFloat(btn.getAttribute('data-due')) || 0
    };
    openPayModal(sale);
  }

  function openPayModal(sale) {
    if (!sale) return;
    currentDueAmount = parseFloat(sale.due_amount) || 0;
    
    const setVal = (id, val) => { const el = document.getElementById(id); if (el) el.value = val; };
    const setText = (id, txt) => { const el = document.getElementById(id); if (el) el.textContent = txt; };

    setVal('pay_sale_id', sale.id);
    setText('pay_invoice_no', sale.invoice_no);
    setText('pay_customer_name', sale.customer_name);
    setText('pay_grand_total', 'Rs ' + parseFloat(sale.grand_total).toLocaleString('en-US', {minimumFractionDigits: 2}));
    setText('pay_already_paid', 'Rs ' + parseFloat(sale.paid_amount).toLocaleString('en-US', {minimumFractionDigits: 2}));
    setText('pay_due_badge', 'Rs ' + currentDueAmount.toLocaleString('en-US', {minimumFractionDigits: 2}));
    
    const amountInput = document.getElementById('pay_amount');
    if (amountInput) {
      amountInput.value = currentDueAmount.toFixed(2);
      amountInput.max = currentDueAmount.toFixed(2);
    }
    
    setVal('pay_cheque_no', '');
    setVal('pay_narration', `Delivery / bill payment for ${sale.invoice_no}`);
    
    const errorAlert = document.getElementById('pay_error_alert');
    if (errorAlert) {
      errorAlert.classList.add('d-none');
      errorAlert.textContent = '';
    }
    
    const modalEl = document.getElementById('billPaymentModal');
    if (!modalEl) return;

    // 1. Try Bootstrap 5 Modal
    if (window.bootstrap && window.bootstrap.Modal) {
      try {
        if (!payModalInstance) {
          payModalInstance = (bootstrap.Modal.getOrCreateInstance) 
            ? bootstrap.Modal.getOrCreateInstance(modalEl) 
            : new bootstrap.Modal(modalEl);
        }
        payModalInstance.show();
        return;
      } catch (err) {
        console.warn('Bootstrap modal show failed, fallback to native display:', err);
      }
    }

    // 2. Native CSS fallback if bootstrap JS is absent/blocked
    modalEl.classList.add('show');
    modalEl.style.display = 'block';
    modalEl.removeAttribute('aria-hidden');
    modalEl.setAttribute('aria-modal', 'true');
    document.body.classList.add('modal-open');
    if (!document.getElementById('modal-fallback-backdrop')) {
      const backdrop = document.createElement('div');
      backdrop.id = 'modal-fallback-backdrop';
      backdrop.className = 'modal-backdrop fade show';
      backdrop.onclick = closePayModal;
      document.body.appendChild(backdrop);
    }
  }

  function closePayModal() {
    const modalEl = document.getElementById('billPaymentModal');
    if (payModalInstance) {
      try { payModalInstance.hide(); } catch(e) {}
    }
    if (modalEl) {
      modalEl.classList.remove('show');
      modalEl.style.display = 'none';
      modalEl.setAttribute('aria-hidden', 'true');
      modalEl.removeAttribute('aria-modal');
    }
    document.body.classList.remove('modal-open');
    const backdrop = document.getElementById('modal-fallback-backdrop');
    if (backdrop) backdrop.remove();
  }

  function setFullDueAmount() {
    const amountInput = document.getElementById('pay_amount');
    if (amountInput) {
      amountInput.value = currentDueAmount.toFixed(2);
    }
  }

  function onAccountChange(select) {
    const selectedOpt = select.options[select.selectedIndex];
    if (!selectedOpt) return;
    const type = selectedOpt.getAttribute('data-type');
    const modeSelect = document.getElementById('pay_mode');
    if (!modeSelect) return;
    if (type === 'Cash') {
      modeSelect.value = 'Cash';
    } else if (type === 'Bank' && modeSelect.value === 'Cash') {
      modeSelect.value = 'Bank Transfer';
    }
  }

  async function submitBillPayment(e) {
    e.preventDefault();
    const form = document.getElementById('billPaymentForm');
    const errorAlert = document.getElementById('pay_error_alert');
    const submitBtn = document.getElementById('pay_submit_btn');
    
    errorAlert.classList.add('d-none');
    errorAlert.textContent = '';
    
    const amt = parseFloat(document.getElementById('pay_amount').value) || 0;
    if (amt <= 0) {
      errorAlert.textContent = 'Please enter a valid payment amount greater than zero.';
      errorAlert.classList.remove('d-none');
      return;
    }
    if (amt > currentDueAmount + 0.05) {
      errorAlert.textContent = `Amount cannot exceed remaining due (Rs ${currentDueAmount.toFixed(2)}).`;
      errorAlert.classList.remove('d-none');
      return;
    }
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-1"></i> Recording...';
    
    try {
      const fd = new FormData(form);
      const res = await fetch('ajax/sales.php?action=pay_bill', {
        method: 'POST',
        body: fd
      });
      const json = await res.json();
      if (json.success) {
        closePayModal();
        const printUpdated = confirm(`✅ ${json.message}\n\nکیا آپ اپ ڈیٹ شدہ بل پرنٹ کرنا چاہتے ہیں؟\n(Do you want to print the updated bill now?)`);
        if (printUpdated && json.data && json.data.sale_id) {
          window.open(`print_bill.php?id=${json.data.sale_id}`, '_blank');
        }
        window.location.reload();
      } else {
        errorAlert.textContent = json.message || 'Failed to record payment.';
        errorAlert.classList.remove('d-none');
      }
    } catch (err) {
      errorAlert.textContent = 'Server error occurred while communicating with server.';
      errorAlert.classList.remove('d-none');
    } finally {
      submitBtn.disabled = false;
      submitBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i> Confirm Payment (وصولی درج کریں)';
    }
  }

  async function cancelSaleBill(id, no) {
    const reason = prompt(`بل نمبر ${no} منسوخ (Cancel) کرنے کی وجہ لکھیں:`);
    if (!reason) return;

    const fd = new FormData();
    fd.append('id', id);
    fd.append('reason', reason);

    const res = await fetch('ajax/sales.php?action=cancel', { method: 'POST', body: fd });
    const json = await res.json();
    if (json.success) {
      alert(json.message);
      window.location.reload();
    } else {
      alert(json.message || 'بل منسوخ کرنے میں خرابی ہوئی۔');
    }
  }
</script>


<?php require_once __DIR__ . '/includes/footer.php'; ?>
