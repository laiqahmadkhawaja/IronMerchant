<?php
// print_bill.php - High-Definition Invoice Printing & ESC/POS 80mm Thermal Receipt
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT s.*, p.name AS customer_name, p.phone AS customer_phone, p.address AS customer_address, 
           p.current_balance AS customer_current_balance, u.name AS cashier_name
    FROM sales s
    JOIN parties p ON s.customer_id = p.id
    LEFT JOIN users u ON s.created_by = u.id
    WHERE s.id = ?
");
$stmt->execute([$id]);
$sale = $stmt->fetch();

if (!$sale) {
    die("<h3>Bill not found.</h3><p><a href='bills.php'>Back to Bills</a></p>");
}

$itemsStmt = $db->prepare("
    SELECT si.*, i.name AS item_name, i.unit
    FROM sale_items si
    JOIN items i ON si.item_id = i.id
    WHERE si.sale_id = ?
");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$chargesStmt = $db->prepare("SELECT * FROM sale_charges WHERE sale_id = ?");
$chargesStmt->execute([$id]);
$charges = $chargesStmt->fetchAll();

// Extract labour & delivery charges separately
$labourAmount = (float)($sale['labour_charge'] ?? 0);
$deliveryAmount = (float)($sale['delivery_charge'] ?? 0);
if ($labourAmount <= 0 && $deliveryAmount <= 0 && !empty($charges)) {
    foreach ($charges as $ch) {
        $chName = strtolower((string)$ch['charge_name']);
        if (str_contains($chName, 'labour') || str_contains($chName, 'crane')) {
            $labourAmount += (float)$ch['amount'];
        } elseif (str_contains($chName, 'delivery') || str_contains($chName, 'freight')) {
            $deliveryAmount += (float)$ch['amount'];
        }
    }
}

$displayCustomerName = !empty($sale['customer_name_custom']) ? $sale['customer_name_custom'] : $sale['customer_name'];
$displayCustomerPhone = !empty($sale['customer_phone_custom']) ? $sale['customer_phone_custom'] : ($sale['customer_phone'] ?? '');

$company = getCompanySettings();
$compName = $company['company_name'] ?? 'Zafar Brothers Iron Merchants';
$compUrdu = (!empty($company['company_urdu']) && strpos($company['company_urdu'], '?') === false) ? $company['company_urdu'] : 'ظفر برادرز آئرن مرچنٹس';
$compPhone = $company['company_phone'] ?? '0300-9641483 · 0300-7499499';
$compAddress = $company['company_address'] ?? 'Old Chungi, Hafizabad Road, Gujranwala';

$size = trim((string)($_GET['size'] ?? ''));
if ($size === '') {
    $defaultFormat = $company['receipt_default_size'] ?? 'A4';
    if ($defaultFormat === '80' || $defaultFormat === 'thermal') {
        $size = '80';
    } elseif ($defaultFormat === '58') {
        $size = '58';
    } elseif (strcasecmp($defaultFormat, 'a5') === 0) {
        $size = 'A5';
    } else {
        $size = 'A4';
    }
}
$isThermal = ($size === '80' || $size === '58');

// Balance computations
$billDue = (float)$sale['due_amount'];
$currBalance = (float)$sale['customer_current_balance'];
$prevBalance = $currBalance - $billDue;

// Total weight
$totalWeight = 0.0;
foreach ($items as $it) {
    $totalWeight += (float)$it['qty'];
}
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
  <meta charset="utf-8">
  <title>Invoice #<?= htmlspecialchars($sale['invoice_no']) ?> · <?= htmlspecialchars($compName) ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;600;700&family=Sora:wght@400;600;700;800&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <style>
    @font-face {
      font-family: 'Alvi Nastaleeq';
      src: local('Alvi Nastaleeq'),
           local('Alvi-Nastaleeq'),
           url('assets/fonts/Alvi-Nastaleeq.ttf') format('truetype');
      font-weight: normal;
      font-style: normal;
      font-display: swap;
    }
    @font-face {
      font-family: 'Agency FB';
      src: url('assets/fonts/Agency-FB-Regular.ttf') format('truetype'),
           url('assets/fonts/Agency%20FB%20Regular.ttf') format('truetype');
      font-weight: normal;
      font-style: normal;
      font-display: swap;
    }
    body {
      background: #718096;
      color: #1a202c;
      font-family: 'Sora', 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', system-ui, -apple-system, sans-serif;
      margin: 0;
      padding: 16px 8px;
    }
    .ur {
      font-family: 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', serif;
      line-height: 2.0;
      direction: rtl;
    }
    .font-num {
      font-family: 'Consolas', 'JetBrains Mono', 'Courier New', monospace;
      white-space: nowrap;
    }

    /* Fixed Spacious Toolbar (Never squished) */
    .action-bar {
      max-width: 860px;
      width: 100%;
      margin: 0 auto 16px;
      background: #fff;
      padding: 10px 18px;
      border-radius: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      box-shadow: 0 4px 14px rgba(0,0,0,0.18);
      flex-wrap: wrap;
      gap: 12px;
    }

    /* ---------------- A4 / A5 Standard Invoice Styling ---------------- */
    .invoice-card {
      background: #fff;
      margin: 0 auto;
      box-shadow: 0 10px 30px rgba(0,0,0,0.25);
      border-radius: 6px;
      overflow: hidden;
      padding: 28px;
    }
    .invoice-card.size-A4 {
      max-width: 860px;
      min-height: 1020px;
      font-size: 12px;
    }
    .invoice-card.size-A5 {
      max-width: 650px;
      padding: 18px 22px;
      font-size: 11px;
    }
    .invoice-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 12px;
      margin-bottom: 12px;
    }
    .invoice-table th {
      background: #14307a;
      color: #fff;
      padding: 6px 10px;
      font-size: 11.5px;
      border: 1px solid #0f245c;
      font-weight: 700;
      vertical-align: middle;
    }
    .invoice-table td {
      padding: 6px 10px;
      border: 1px solid #cbd5e1;
      font-size: 11.5px;
      vertical-align: middle;
    }
    .invoice-table tr:nth-child(even) td {
      background: #f8fafc;
    }

    /* ---------------- Strict Thermal Optimization Rules (58mm & 80mm) ---------------- */
    /* Rule 1: Monoline Sans-Serif Fonts */
    /* Rule 4: Pure #000 Black (No colors, no grays, no dot dithering) */
    /* Rule 5: Disable Gray Fringe Anti-Aliasing */
    .thermal-receipt {
      background: #fff !important;
      color: #000 !important;
      margin: 14px auto;
      box-shadow: 0 4px 18px rgba(0,0,0,0.22);
      box-sizing: border-box;
      font-family: Arial, "Helvetica Neue", Helvetica, "Segoe UI", Tahoma, sans-serif !important;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      text-rendering: geometricPrecision;
      line-height: 1.35;
    }

    /* Rule 2: Enforce Uniform Bold Weight (700 !important) on all thermal receipt elements */
    .thermal-receipt,
    .thermal-receipt * {
      font-family: Arial, "Helvetica Neue", Helvetica, "Segoe UI", Tahoma, sans-serif !important;
      font-weight: 700 !important;
      color: #000 !important;
      border-color: #000 !important;
      background: transparent !important;
    }

    .thermal-receipt .ur {
      font-family: 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', Arial, "Helvetica Neue", Helvetica, sans-serif !important;
      font-weight: 700 !important;
      line-height: 2.0 !important;
      direction: rtl;
    }

    /* Rule 6: Support Both 58mm (48mm printable) and 80mm (72mm printable) */
    .thermal-receipt.size-80 {
      width: 72mm;
      max-width: 72mm;
      padding: 3mm 1mm;
      font-size: 11px;
    }

    .thermal-receipt.size-58 {
      width: 48mm;
      max-width: 48mm;
      padding: 2mm 0.5mm;
      font-size: 9.5px;
      line-height: 1.25;
    }

    /* Rule 3: Hierarchy via Font Size Only (Uniform bold weight throughout) */
    .thermal-receipt.size-80 .th-company-ur { font-size: 19px; line-height: 1.9; }
    .thermal-receipt.size-80 .th-company-en { font-size: 13.5px; }
    .thermal-receipt.size-80 .th-sub        { font-size: 10px; }
    .thermal-receipt.size-80 .th-meta       { font-size: 11px; }
    .thermal-receipt.size-80 .th-item-title { font-size: 11.5px; word-break: break-word; }
    .thermal-receipt.size-80 .th-item-sub   { font-size: 10.5px; }
    .thermal-receipt.size-80 .th-total      { font-size: 15px; }
    .thermal-receipt.size-80 .th-due        { font-size: 12px; }
    .thermal-receipt.size-80 .th-khata      { font-size: 13px; }
    .thermal-receipt.size-80 .th-footer     { font-size: 10px; }
    .thermal-receipt.size-80 .th-credits    { font-size: 9px; }

    .thermal-receipt.size-58 .th-company-ur { font-size: 15px; line-height: 1.7; }
    .thermal-receipt.size-58 .th-company-en { font-size: 11px; }
    .thermal-receipt.size-58 .th-sub        { font-size: 8.5px; }
    .thermal-receipt.size-58 .th-meta       { font-size: 9px; }
    .thermal-receipt.size-58 .th-item-title { font-size: 9.5px; word-break: break-word; }
    .thermal-receipt.size-58 .th-item-sub   { font-size: 9px; }
    .thermal-receipt.size-58 .th-total      { font-size: 12.5px; }
    .thermal-receipt.size-58 .th-due        { font-size: 10px; }
    .thermal-receipt.size-58 .th-khata      { font-size: 10.5px; }
    .thermal-receipt.size-58 .th-footer     { font-size: 8.5px; }
    .thermal-receipt.size-58 .th-credits    { font-size: 8px; }

    /* Thermal line elements */
    .thermal-receipt .th-center { text-align: center; }
    .thermal-receipt .th-right  { text-align: right; }
    .thermal-receipt .th-line   { border-top: 1px dashed #000 !important; margin: 5px 0; }
    .thermal-receipt .th-double { border-top: 2px solid #000 !important; border-bottom: 2px solid #000 !important; padding: 4px 0; margin: 5px 0; }
    .thermal-receipt .th-row    { display: flex; justify-content: space-between; align-items: flex-start; }

    @media print {
      body {
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #000 !important;
      }
      .no-print {
        display: none !important;
      }

      /* Rule 6: Thermal Specific Page Setup */
      <?php if ($size === '58'): ?>
        @page {
          size: 58mm auto;
          margin: 0;
        }
        html, body {
          width: 58mm !important;
          margin: 0 !important;
          padding: 0 !important;
        }
        .thermal-receipt {
          width: 48mm !important;
          max-width: 48mm !important;
          margin: 0 auto !important;
          padding: 1.5mm 0 !important;
          box-shadow: none !important;
        }
      <?php elseif ($size === '80'): ?>
        @page {
          size: 80mm auto;
          margin: 0;
        }
        html, body {
          width: 80mm !important;
          margin: 0 !important;
          padding: 0 !important;
        }
        .thermal-receipt {
          width: 72mm !important;
          max-width: 72mm !important;
          margin: 0 auto !important;
          padding: 2mm 0 !important;
          box-shadow: none !important;
        }
      <?php else: ?>
        .invoice-card {
          box-shadow: none !important;
          padding: 0 !important;
          margin: 0 !important;
          width: 100% !important;
          max-width: 100% !important;
          border-radius: 0 !important;
        }
        .invoice-table th {
          background: #e2e8f0 !important;
          color: #000 !important;
          border: 1px solid #000 !important;
        }
        .invoice-table td {
          border: 1px solid #333 !important;
        }
        @page {
          size: <?= $size === 'A5' ? 'A5 landscape' : 'A4 portrait' ?>;
          margin: 8mm 8mm 10mm 8mm;
        }
      <?php endif; ?>
    }
  </style>
</head>
<body>

  <!-- Top Action Toolbar -->
  <div class="action-bar no-print">
    <div class="d-flex align-items-center gap-2">
      <a href="pos.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-cart-plus me-1"></i> New Bill</a>
      <a href="bills.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-list me-1"></i> Bills List</a>
      <span class="badge bg-primary fs-6 ms-1">#<?= htmlspecialchars($sale['invoice_no']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <select class="form-select form-select-sm" style="width: auto; font-weight: 600;" onchange="window.location.href='print_bill.php?id=<?= $sale['id'] ?>&size='+this.value">
        <option value="A4" <?= $size === 'A4' ? 'selected' : '' ?>>A4 Full Sheet (معیاری بل)</option>
        <option value="A5" <?= $size === 'A5' ? 'selected' : '' ?>>A5 Half Sheet (ہاف شیٹ)</option>
        <option value="80" <?= $size === '80' ? 'selected' : '' ?>>Thermal 80mm · 3" (72mm ڈتھ)</option>
        <option value="58" <?= $size === '58' ? 'selected' : '' ?>>Thermal 58mm · 2" (48mm ڈتھ)</option>
      </select>
      <button onclick="window.print()" class="btn btn-sm btn-primary px-3">
        <i class="fa-solid fa-print me-1"></i> Print (پرنٹ)
      </button>
      <?php if (!empty($sale['customer_phone'])): ?>
        <a href="https://wa.me/<?= formatWaPhone($sale['customer_phone']) ?>?text=<?= urlencode("السلام علیکم {$sale['customer_name']} صاحب!\nظفر برادرز آئرن مرچنٹس گوجرانوالہ کی طرف سے آپ کا بل #{$sale['invoice_no']} تیار ہے۔\nکل رقم: " . formatMoney($sale['grand_total']) . "\nوصول شدہ: " . formatMoney($sale['paid_amount']) . "\nبقایا بل: " . formatMoney($sale['due_amount']) . "\nکل کھاتہ بقایا: " . formatMoney($currBalance) . "\nشکریہ!") ?>" target="_blank" class="btn btn-sm btn-success">
          <i class="fa-brands fa-whatsapp me-1"></i> WhatsApp
        </a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($isThermal): ?>
    <!-- ==================== DEDICATED THERMAL RECEIPT (58mm / 80mm) ==================== -->
    <div class="thermal-receipt size-<?= $size ?>">
      <div class="th-center">
        <!-- Separate Urdu block with adequate vertical line-height -->
        <div class="ur th-company-ur">
          <?= htmlspecialchars($compUrdu) ?>
        </div>
        <!-- English Name with top clearance -->
        <div class="th-company-en" style="letter-spacing: 0.5px; margin-top: 2px;">
          <?= strtoupper(htmlspecialchars($compName)) ?>
        </div>
        <div class="th-sub" style="margin-top: 2px;">Gujranwala Steel Group of Industry</div>
        <div class="th-sub">Deals in: Grade 40/60 Steel &amp; G.I Wire</div>
        <div class="th-sub" style="margin-top: 3px;">
          <i class="fa-solid fa-phone"></i> <?= htmlspecialchars($compPhone) ?>
        </div>
        <div class="th-sub"><?= htmlspecialchars($compAddress) ?></div>
      </div>

      <div class="th-line"></div>

      <!-- Bill Metadata -->
      <div class="th-row th-meta">
        <span>BILL #: <?= htmlspecialchars($sale['invoice_no']) ?></span>
        <span><?= date('d/m/Y', strtotime($sale['sale_date'])) ?></span>
      </div>
      <div class="th-row th-meta">
        <span>TIME: <?= date('h:i A', strtotime($sale['sale_time'])) ?></span>
        <span>OP: <?= htmlspecialchars($sale['cashier_name'] ?? 'Admin') ?></span>
      </div>
      <div class="th-meta">CUSTOMER: <?= htmlspecialchars($displayCustomerName) ?></div>
      <?php if (!empty($displayCustomerPhone)): ?>
        <div class="th-meta">PHONE: <?= htmlspecialchars($displayCustomerPhone) ?></div>
      <?php endif; ?>
      <div class="th-meta">PAYMENT: <?= htmlspecialchars($sale['payment_method']) ?></div>

      <div class="th-line"></div>

      <!-- Itemized Table Header -->
      <div class="th-row th-meta">
        <span>ITEM / QTY</span>
        <span>RATE</span>
        <span>TOTAL (RS)</span>
      </div>
      <div class="th-line"></div>

      <!-- Items List -->
      <?php foreach ($items as $idx => $it): ?>
        <div style="margin-bottom: 5px;">
          <div class="th-item-title"><?= ($idx + 1) ?>. <?= htmlspecialchars($it['item_name']) ?></div>
          <div class="th-row th-item-sub">
            <span><?= formatQty($it['qty'], $it['unit']) ?> @ <?= number_format((float)$it['unit_rate'], 2) ?></span>
            <span class="font-num"><?= number_format((float)$it['line_total'], 2) ?></span>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="th-line"></div>

      <!-- Subtotals & Charges -->
      <div class="th-row th-meta">
        <span>Total Weight:</span>
        <span class="font-num"><?= number_format($totalWeight, 2) ?> kg</span>
      </div>
      <div class="th-row th-meta">
        <span>Items Subtotal:</span>
        <span class="font-num"><?= number_format((float)$sale['subtotal'], 2) ?></span>
      </div>
      <?php if ($labourAmount > 0): ?>
        <div class="th-row th-meta">
          <span>Labour / Crane (مزدوری):</span>
          <span class="font-num">+ <?= number_format($labourAmount, 2) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($deliveryAmount > 0): ?>
        <div class="th-row th-meta">
          <span>Delivery / Freight (کرایہ):</span>
          <span class="font-num">+ <?= number_format($deliveryAmount, 2) ?></span>
        </div>
      <?php endif; ?>
      <?php if ($labourAmount <= 0 && $deliveryAmount <= 0 && (float)$sale['additional_charges'] > 0): ?>
        <div class="th-row th-meta">
          <span>Labour / Delivery:</span>
          <span class="font-num">+ <?= number_format((float)$sale['additional_charges'], 2) ?></span>
        </div>
      <?php endif; ?>
      <?php if ((float)$sale['discount'] > 0): ?>
        <div class="th-row th-meta">
          <span>Discount:</span>
          <span class="font-num">− <?= number_format((float)$sale['discount'], 2) ?></span>
        </div>
      <?php endif; ?>

      <div class="th-double th-row th-total">
        <span>TOTAL PAYABLE:</span>
        <span class="font-num">Rs <?= number_format((float)$sale['grand_total'], 2) ?></span>
      </div>

      <div class="th-row th-due">
        <span>Paid Amount / Advance:</span>
        <span class="font-num"><?= number_format((float)$sale['paid_amount'], 2) ?></span>
      </div>
      <div class="th-row th-due" style="<?= (float)$sale['due_amount'] > 0 ? 'color: #991b1b; font-weight: bold;' : '' ?>">
        <span><?= ((float)$sale['due_amount'] > 0) ? 'Due on Delivery (بقیہ مال اتروائی پر):' : 'This Bill Due:' ?></span>
        <span class="font-num"><?= number_format((float)$sale['due_amount'], 2) ?></span>
      </div>

      <!-- Khata Balance Section -->
      <div class="th-line"></div>
      <div class="th-row th-meta">
        <span>Previous Balance:</span>
        <span class="font-num"><?= formatCurrency($prevBalance) ?></span>
      </div>
      <div class="th-row th-khata">
        <span>TOTAL KHATA DUE:</span>
        <span class="font-num"><?= formatCurrency($currBalance) ?></span>
      </div>

      <div class="th-line"></div>

      <!-- Footer Message -->
      <div class="th-center" style="margin-top: 6px;">
        <div class="ur th-footer">
          مال تول کر پورا وصول پایا گیا۔
        </div>
        <div class="ur th-footer" style="margin-bottom: 3px;">
          خریدا ہوا مال واپس یا تبدیل نہیں ہوگا۔
        </div>
        <div class="th-footer" style="margin-top: 4px;">
          *** THANK YOU FOR YOUR BUSINESS ***
        </div>
        <div class="th-credits" style="margin-top: 3px;">Software by Antigravity POS</div>
        <div style="letter-spacing: 2px; margin-top: 3px;">- - - - - - - - - - - - - -</div>
      </div>
    </div>

  <?php else: ?>
    <!-- ==================== A4 / A5 STANDARD PROFESSIONAL INVOICE ==================== -->
    <div class="invoice-card size-<?= $size ?>">

      <!-- Letterhead -->
      <div style="border-bottom: 3px solid #14307a; padding-bottom: 12px; margin-bottom: 14px;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-3">
            <img src="assets/images/logo.png" alt="Logo" style="width: 65px; height: 65px; object-fit: contain;">
            <div>
              <div class="ur" style="color: #14307a; font-size: 24px; font-weight: 700; line-height: 1.8; margin-bottom: 2px;">
                <?= htmlspecialchars($compUrdu) ?>
              </div>
              <h4 class="mb-0" style="font-weight: 800; color: #14307a; letter-spacing: 0.5px;">
                <?= htmlspecialchars($compName) ?>
              </h4>
              <div style="font-size: 11.5px; color: #64748b; font-weight: 600; margin-top: 2px;">
                Gujranwala Steel Group of Industry · Prime Re-Rolled Steel Bars, G.I Wire &amp; Structural Steel
              </div>
            </div>
          </div>
          <div class="text-end" style="font-size: 11.5px; line-height: 1.6;">
            <div><i class="fa-solid fa-phone me-1 text-primary"></i> <strong><?= htmlspecialchars($compPhone) ?></strong></div>
            <div><i class="fa-solid fa-location-dot me-1 text-primary"></i> <?= htmlspecialchars($compAddress) ?></div>
            <div class="badge bg-primary text-uppercase mt-1" style="font-size: 10.5px; letter-spacing: 0.5px; padding: 4px 8px;">
              Sales Invoice / بل فروخت
            </div>
          </div>
        </div>
      </div>

      <!-- Customer & Bill Meta Boxes -->
      <div class="row g-2 mb-3">
        <!-- Customer Box -->
        <div class="col-7">
          <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; height: 100%;">
            <div class="d-flex justify-content-between align-items-center" style="font-size: 10.5px; color: #64748b; font-weight: 700;">
              <span>BILLED TO / CUSTOMER INFORMATION</span>
              <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.4;">نام خریدار</span>
            </div>
            <div style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">
              <?= htmlspecialchars($displayCustomerName) ?>
              <?php if (!empty($sale['customer_name_custom']) && $sale['customer_name'] !== $sale['customer_name_custom']): ?>
                <span class="badge bg-light text-secondary border ms-1" style="font-size: 11px; font-weight: normal;"><?= htmlspecialchars($sale['customer_name']) ?></span>
              <?php endif; ?>
            </div>
            <div style="font-size: 11.5px; color: #475569; margin-top: 3px;">
              <?php if (!empty($displayCustomerPhone)): ?>
                <div><i class="fa-solid fa-phone me-1"></i> Phone: <strong><?= htmlspecialchars($displayCustomerPhone) ?></strong></div>
              <?php endif; ?>
              <?php if (!empty($sale['customer_address'])): ?>
                <div><i class="fa-solid fa-map-marker-alt me-1"></i> Address: <?= htmlspecialchars($sale['customer_address']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Invoice Meta Box -->
        <div class="col-5">
          <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; height: 100%;">
            <div class="d-flex justify-content-between align-items-center">
              <span style="font-size: 10.5px; color: #64748b; font-weight: 700;">INVOICE #</span>
              <strong style="font-size: 15px; color: #14307a;">#<?= htmlspecialchars($sale['invoice_no']) ?></strong>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size: 11.5px;">
              <span class="text-muted">Issue Date:</span>
              <strong><?= date('d-M-Y', strtotime($sale['sale_date'])) ?></strong>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size: 11.5px;">
              <span class="text-muted">Issue Time:</span>
              <span><?= date('h:i A', strtotime($sale['sale_time'])) ?></span>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size: 11.5px;">
              <span class="text-muted">Payment:</span>
              <span class="badge bg-secondary"><?= htmlspecialchars($sale['payment_method']) ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Itemized Table with Stacked Bilingual Headers -->
      <table class="invoice-table">
        <thead>
          <tr>
            <th style="width: 6%; text-align: center;">
              <div>Sr #</div>
              <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">نمبر</div>
            </th>
            <th style="width: 44%;">
              <div>Item Description</div>
              <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">تفصیل مال و سریا</div>
            </th>
            <th style="width: 13%; text-align: center;">
              <div>Unit</div>
              <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">یونٹ</div>
            </th>
            <th style="width: 17%; text-align: center;">
              <div>Weight / Qty</div>
              <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">وزن کلوگرام</div>
            </th>
            <th style="width: 10%; text-align: right;">
              <div>Rate</div>
              <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">ریٹ</div>
            </th>
            <th style="width: 10%; text-align: right;">
              <div>Amount</div>
              <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">رقم</div>
            </th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $idx => $it): ?>
            <tr>
              <td style="text-align: center;"><?= ($idx + 1) ?></td>
              <td>
                <strong><?= htmlspecialchars($it['item_name']) ?></strong>
              </td>
              <td style="text-align: center; text-transform: uppercase;">
                <?= htmlspecialchars($it['unit']) ?>
              </td>
              <td style="text-align: center; font-weight: 700;">
                <?= formatQty($it['qty'], $it['unit']) ?>
              </td>
              <td style="text-align: right;" class="font-num">
                <?= number_format((float)$it['unit_rate'], 2) ?>
              </td>
              <td style="text-align: right; font-weight: 700;" class="font-num">
                <?= number_format((float)$it['line_total'], 2) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background: #f1f5f9; font-weight: 700;">
            <td colspan="3" class="text-end">
              <span>Total Weight</span>
              <span class="ur text-muted ms-1" dir="rtl" style="font-size: 11px;">(کل وزن)</span>:
            </td>
            <td class="text-center font-num" style="color: #14307a; font-size: 13px;">
              <?= number_format($totalWeight, 2) ?> kg
            </td>
            <td class="text-end">
              <span>Subtotal</span>:
            </td>
            <td class="text-end font-num" style="color: #14307a; font-size: 13px;">
              <?= number_format((float)$sale['subtotal'], 2) ?>
            </td>
          </tr>
        </tfoot>
      </table>

      <!-- Bottom Layout: Terms & Totals Breakdown (Balanced Widths) -->
      <div class="row g-2 align-items-start mt-1">
        <!-- Terms and Notes -->
        <div class="col-<?= $size === 'A5' ? '6' : '7' ?>">
          <div style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; background: #fafafa;">
            <div class="ur" dir="rtl" style="color: #14307a; font-weight: 700; font-size: 13px; line-height: 1.6; margin-bottom: 2px;">
              شرائط و ضوابط برائے خریدار:
            </div>
            <ol class="ur mb-1" dir="rtl" style="font-size: 11px; padding-right: 16px; color: #334155; line-height: 1.9;">
              <li>مال گودام یا گاڑی سے نکلنے کے بعد وزن کی ذمہ داری خریدار پر ہوگی۔</li>
              <li>خریدا ہوا مال واپس یا تبدیل نہیں کیا جائے گا۔</li>
            </ol>
            <div style="font-size: 10px; color: #64748b;">
              Goods once sold and loaded cannot be returned or exchanged.
            </div>
          </div>

          <!-- Customer Khata Ledger Impact Box (Isolated Elements) -->
          <div class="mt-2 p-2 border rounded" style="background: #f8fafc; font-size: 11.5px;">
            <div class="d-flex justify-content-between align-items-center py-1">
              <div class="d-flex align-items-center gap-1">
                <span>Previous Khata</span>
                <span class="ur text-muted" dir="rtl" style="font-size: 11px; line-height: 1.4;">سابقہ بقایا</span>
              </div>
              <span class="font-num fw-bold"><?= formatCurrency($prevBalance) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center py-1">
              <div class="d-flex align-items-center gap-1">
                <span>This Bill Due</span>
                <span class="ur text-muted" dir="rtl" style="font-size: 11px; line-height: 1.4;">موجودہ بل بقایا</span>
              </div>
              <span class="font-num fw-bold text-danger">+ <?= formatCurrency($billDue) ?></span>
            </div>
            <div class="d-flex justify-content-between align-items-center pt-1 border-top border-dark fw-bold" style="color: #14307a;">
              <div class="d-flex align-items-center gap-1">
                <span style="font-size: 12px;">Net Khata Due</span>
                <span class="ur" dir="rtl" style="font-size: 11.5px; line-height: 1.4;">کل واجب الادا کھاتہ</span>
              </div>
              <span class="font-num fs-6" style="color: #b91c1c;"><?= formatCurrency($currBalance) ?></span>
            </div>
          </div>
        </div>

        <!-- Numerical Totals Box (Isolated Elements, Non-wrapping amounts) -->
        <div class="col-<?= $size === 'A5' ? '6' : '5' ?>">
          <div style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; background: #fff; line-height: 1.8; font-size: 12px;">
            <div class="d-flex justify-content-between align-items-center py-1">
              <div class="d-flex align-items-center gap-1">
                <span>Subtotal</span>
                <span class="ur text-muted" dir="rtl" style="font-size: 11px; line-height: 1.4;">مال کی مالیت</span>
              </div>
              <span class="font-num fw-bold"><?= formatMoney($sale['subtotal']) ?></span>
            </div>

            <?php if ($labourAmount > 0): ?>
              <div class="d-flex justify-content-between align-items-center py-1 text-primary">
                <div class="d-flex align-items-center gap-1">
                  <span>Labour / Crane Charges</span>
                  <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.4;">مزدوری چارجز</span>
                </div>
                <span class="font-num fw-bold">+ <?= formatMoney($labourAmount) ?></span>
              </div>
            <?php endif; ?>

            <?php if ($deliveryAmount > 0): ?>
              <div class="d-flex justify-content-between align-items-center py-1 text-primary">
                <div class="d-flex align-items-center gap-1">
                  <span>Delivery / Freight Charges</span>
                  <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.4;">گاڑی کرایہ / فریٹ چارجز</span>
                </div>
                <span class="font-num fw-bold">+ <?= formatMoney($deliveryAmount) ?></span>
              </div>
            <?php endif; ?>

            <?php if ($labourAmount <= 0 && $deliveryAmount <= 0 && (float)$sale['additional_charges'] > 0): ?>
              <div class="d-flex justify-content-between align-items-center py-1 text-primary">
                <div class="d-flex align-items-center gap-1">
                  <span>Labour / Freight</span>
                  <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.4;">مزدوری و کرایہ</span>
                </div>
                <span class="font-num fw-bold">+ <?= formatMoney($sale['additional_charges']) ?></span>
              </div>
            <?php endif; ?>

            <?php if ((float)$sale['discount'] > 0): ?>
              <div class="d-flex justify-content-between align-items-center py-1 text-danger">
                <div class="d-flex align-items-center gap-1">
                  <span>Special Discount</span>
                  <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.4;">رعایت</span>
                </div>
                <span class="font-num fw-bold">− <?= formatMoney($sale['discount']) ?></span>
              </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center py-1 border-top border-bottom border-2 border-dark fw-bold" style="color: #14307a; font-size: 14px;">
              <div class="d-flex align-items-center gap-1">
                <span>Grand Total</span>
                <span class="ur" dir="rtl" style="font-size: 13px; line-height: 1.4;">کل بل</span>
              </div>
              <span class="font-num fs-5"><?= formatMoney($sale['grand_total']) ?></span>
            </div>

            <div class="d-flex justify-content-between align-items-center py-1 mt-1">
              <div class="d-flex align-items-center gap-1">
                <span>Paid Amount / Advance</span>
                <span class="ur text-muted" dir="rtl" style="font-size: 11px; line-height: 1.4;">ادا شدہ رقم / پیشگی</span>
              </div>
              <span class="font-num fw-bold text-success"><?= formatMoney($sale['paid_amount']) ?></span>
            </div>

            <div class="d-flex justify-content-between align-items-center py-1 fw-bold <?= (float)$sale['due_amount'] > 0 ? 'text-danger' : 'text-success' ?>" <?= (float)$sale['due_amount'] > 0 ? 'style="background: #fef2f2; padding: 4px 6px; border-radius: 4px;"' : '' ?>>
              <div class="d-flex align-items-center gap-1">
                <span><?= ((float)$sale['due_amount'] > 0) ? 'Due on Site Delivery / Unloading' : 'Balance Due' ?></span>
                <span class="ur" dir="rtl" style="font-size: 11.5px; line-height: 1.4;"><?= ((float)$sale['due_amount'] > 0) ? '(مال اتروائی پر بقیہ وصولی)' : 'اس بل کا بقایا' ?></span>
              </div>
              <span class="font-num fs-6"><?= formatMoney($sale['due_amount']) ?></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Signatures Footer -->
      <div style="margin-top: 45px; display: flex; justify-content: space-between; text-align: center; font-size: 11.5px; color: #475569;">
        <div style="width: 30%; border-top: 1px solid #94a3b8; padding-top: 6px;">
          <strong>Prepared By (بل کلرک)</strong><br>
          <small class="text-muted"><?= htmlspecialchars($sale['cashier_name'] ?? 'Authorized Staff') ?></small>
        </div>
        <div style="width: 30%; border-top: 1px solid #94a3b8; padding-top: 6px;">
          <strong>Manager / Stamp (تصدیق)</strong><br>
          <small class="text-muted">Zafar Brothers</small>
        </div>
        <div style="width: 30%; border-top: 1px solid #94a3b8; padding-top: 6px;">
          <strong>Receiver Signature (وصول کنندہ)</strong><br>
          <small class="text-muted"><?= htmlspecialchars($sale['customer_name']) ?></small>
        </div>
      </div>

      <!-- Brand Footer Motto -->
      <div style="margin-top: 24px; border-top: 2px solid #f5c518; background: #f8fafc; padding: 8px; text-align: center; border-radius: 0 0 6px 6px;">
        <div class="ur" dir="rtl" style="color: #b91c1c; font-size: 14px; font-weight: 700; line-height: 1.8;">
          آپ کے اعتماد کا شکریہ — معیاری سریا، ایماندار وزن، بااعتماد نام
        </div>
        <small class="text-muted fst-italic" style="font-size: 10.5px;">Quality Steel &amp; Accurate Weighbridge Weight Guaranteed.</small>
      </div>

    </div>
  <?php endif; ?>

</body>
</html>
