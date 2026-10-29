<?php
// print_return.php - Dedicated Printable Credit / Debit Note for Sales and Purchase Returns
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
require_once __DIR__ . '/includes/permissions.php';
if (!hasPermission('returns')) {
    http_response_code(403);
    die("<div style='font-family:sans-serif;padding:30px;color:#c00;'><h3>403 · Access Denied (رسائی ممنوع ہے)</h3><p>You do not have permission to view or print returns.</p></div>");
}

$id = (int)($_GET['id'] ?? 0);
$pdo = getPDO();

$stmt = $pdo->prepare("
    SELECT r.*, p.name AS party_name, p.phone AS party_phone, p.address AS party_address, 
           p.current_balance AS party_current_balance, u.name AS cashier_name
    FROM returns r
    JOIN parties p ON r.party_id = p.id
    LEFT JOIN users u ON r.created_by = u.id
    WHERE r.id = ?
");
$stmt->execute([$id]);
$ret = $stmt->fetch();

if (!$ret) {
    die("<h3>Return record not found.</h3><p><a href='returns.php'>Back to Returns</a></p>");
}

$itemsStmt = $pdo->prepare("
    SELECT ri.*, i.name AS item_name, i.unit, i.item_code
    FROM return_items ri
    JOIN items i ON ri.item_id = i.id
    WHERE ri.return_id = ?
");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$company = getCompanySettings();
$compName = $company['company_name'] ?? 'Zafar Brothers Iron Merchants';
$compUrdu = (!empty($company['company_urdu']) && strpos($company['company_urdu'], '?') === false) ? $company['company_urdu'] : 'ظفر برادرز آئرن مرچنٹس';
$compPhone = $company['company_phone'] ?? '0300-9641483 · 0300-7499499';
$compAddress = $company['company_address'] ?? 'Old Chungi, Hafizabad Road, Gujranwala';

$isSaleReturn = ($ret['return_type'] === 'sale_return');
$docTitle = $isSaleReturn ? 'CREDIT NOTE (کریڈٹ نوٹ برائے گاہک)' : 'DEBIT NOTE (ڈیبٹ نوٹ برائے سپلائر)';
$docBadge = $isSaleReturn ? 'Sales Return / واپسی مال فروخت' : 'Purchase Return / واپسی خریداری';

$size = trim((string)($_GET['size'] ?? 'A4'));
$isThermal = ($size === '80' || $size === '58');
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($ret['return_no']) ?> · <?= htmlspecialchars($docTitle) ?></title>
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
      color: #0f172a;
      font-family: 'Sora', 'Alvi Nastaleeq', 'Jameel Noori Nastaleeq', 'Noto Nastaliq Urdu', system-ui, sans-serif;
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
    }
    .note-sheet {
      background: #fff;
      max-width: 860px;
      margin: 0 auto;
      box-shadow: 0 10px 30px rgba(0,0,0,0.25);
      border-radius: 6px;
      padding: 28px;
      min-height: 980px;
    }
    .table-note {
      width: 100%;
      border-collapse: collapse;
      margin-top: 14px;
      font-size: 12px;
    }
    .table-note th {
      background: #14307a;
      color: #fff;
      padding: 6px 10px;
      border: 1px solid #0f245c;
      font-weight: 700;
      vertical-align: middle;
      font-size: 11.5px;
    }
    .table-note td {
      padding: 6px 10px;
      border: 1px solid #cbd5e1;
      vertical-align: middle;
      font-size: 11.5px;
    }
    .table-note tr:nth-child(even) td {
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

    /* Rule 3: Hierarchy via Font Size Only */
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
        .note-sheet {
          box-shadow: none !important;
          padding: 0 !important;
          margin: 0 auto !important;
          width: 100% !important;
          max-width: 100% !important;
        }
        .table-note th {
          background: #e2e8f0 !important;
          color: #000 !important;
          border: 1px solid #000 !important;
        }
        .table-note td {
          border: 1px solid #333 !important;
        }
        @page {
          size: A4 portrait;
          margin: 10mm 8mm 12mm 8mm;
        }
      <?php endif; ?>
    }
  </style>
</head>
<body>

  <!-- Top Action Toolbar -->
  <div class="action-bar no-print">
    <div class="d-flex align-items-center gap-2">
      <a href="returns.php" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-arrow-left me-1"></i> Returns List</a>
      <span class="badge bg-primary fs-6 ms-1">#<?= htmlspecialchars($ret['return_no']) ?></span>
    </div>
    <div class="d-flex align-items-center gap-2">
      <select class="form-select form-select-sm" style="width: auto; font-weight: 600;" onchange="window.location.href='print_return.php?id=<?= $ret['id'] ?>&size='+this.value">
        <option value="A4" <?= $size === 'A4' ? 'selected' : '' ?>>A4 Full Sheet (معیاری رسید)</option>
        <option value="80" <?= $size === '80' ? 'selected' : '' ?>>Thermal 80mm · 3" (72mm ڈتھ)</option>
        <option value="58" <?= $size === '58' ? 'selected' : '' ?>>Thermal 58mm · 2" (48mm ڈتھ)</option>
      </select>
      <button onclick="window.print()" class="btn btn-sm btn-primary px-3">
        <i class="fa-solid fa-print me-1"></i> Print (پرنٹ)
      </button>
      <button onclick="window.close()" class="btn btn-sm btn-outline-secondary">Close</button>
    </div>
  </div>

  <?php if ($isThermal): ?>
    <!-- ==================== DEDICATED THERMAL RECEIPT (58mm / 80mm) ==================== -->
    <div class="thermal-receipt size-<?= $size ?>">
      <div class="th-center">
        <!-- Brand Urdu Header -->
        <div class="ur th-company-ur">
          <?= htmlspecialchars($compUrdu) ?>
        </div>
        <!-- English Name -->
        <div class="th-company-en" style="letter-spacing: 0.5px; margin-top: 2px;">
          <?= strtoupper(htmlspecialchars($compName)) ?>
        </div>
        <div class="th-sub" style="margin-top: 2px; text-transform: uppercase;">
          *** <?= htmlspecialchars($docTitle) ?> ***
        </div>
        <div class="th-sub" style="margin-top: 2px;">
          <i class="fa-solid fa-phone"></i> <?= htmlspecialchars($compPhone) ?>
        </div>
      </div>

      <div class="th-line"></div>

      <!-- Voucher Metadata -->
      <div class="th-row th-meta">
        <span>VOUCHER #: <?= htmlspecialchars($ret['return_no']) ?></span>
        <span><?= date('d/m/Y', strtotime($ret['return_date'])) ?></span>
      </div>
      <div class="th-row th-meta">
        <span>TYPE: <?= strtoupper(str_replace('_', ' ', $ret['return_type'])) ?></span>
        <span>OP: <?= htmlspecialchars($ret['cashier_name'] ?? 'Admin') ?></span>
      </div>
      <div class="th-meta">PARTY: <?= htmlspecialchars($ret['party_name']) ?></div>
      <?php if (!empty($ret['party_phone'])): ?>
        <div class="th-meta">PHONE: <?= htmlspecialchars($ret['party_phone']) ?></div>
      <?php endif; ?>
      <?php if (!empty($ret['reference_no'])): ?>
        <div class="th-meta">REF BILL #: <?= htmlspecialchars($ret['reference_no']) ?></div>
      <?php endif; ?>

      <div class="th-line"></div>

      <!-- Itemized Header -->
      <div class="th-row th-meta">
        <span>RETURNED ITEM / QTY</span>
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

      <!-- Subtotals -->
      <div class="th-row th-meta">
        <span>Total Returned Qty:</span>
        <span class="font-num"><?= number_format((float)$ret['total_qty'], 2) ?> kg</span>
      </div>
      <div class="th-row th-meta">
        <span>Goods Value:</span>
        <span class="font-num"><?= number_format((float)$ret['subtotal'], 2) ?></span>
      </div>
      <?php if ((float)$ret['deduction_charges'] > 0): ?>
        <div class="th-row th-meta">
          <span>Deduction / Cutting:</span>
          <span class="font-num">− <?= number_format((float)$ret['deduction_charges'], 2) ?></span>
        </div>
      <?php endif; ?>

      <div class="th-double th-row th-total">
        <span>NET CREDIT/REFUND:</span>
        <span class="font-num">Rs <?= number_format((float)$ret['net_amount'], 2) ?></span>
      </div>

      <div class="th-row th-due">
        <span>Refund Mode:</span>
        <span class="font-num"><?= strtoupper(str_replace('_', ' ', $ret['refund_type'])) ?></span>
      </div>

      <!-- Updated Khata Balance -->
      <div class="th-line"></div>
      <div class="th-row th-khata">
        <span>UPDATED KHATA DUE:</span>
        <span class="font-num"><?= formatCurrency((float)$ret['party_current_balance']) ?></span>
      </div>

      <div class="th-line"></div>

      <!-- Footer Message -->
      <div class="th-center" style="margin-top: 6px;">
        <div class="ur th-footer">
          واپسی مال موصول و کھاتے میں درج کر لیا گیا۔
        </div>
        <div class="th-credits" style="margin-top: 3px;">Software by Antigravity POS</div>
        <div style="letter-spacing: 2px; margin-top: 3px;">- - - - - - - - - - - - - -</div>
      </div>
    </div>

  <?php else: ?>
    <!-- Note Printable Sheet (A4) -->
    <div class="note-sheet">

    <!-- Letterhead -->
    <div style="border-bottom: 3px solid #14307a; padding-bottom: 12px; margin-bottom: 14px;">
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3">
          <img src="assets/images/logo.png" alt="Logo" style="width: 65px; height: 65px; object-fit: contain;">
          <div>
            <div class="ur" dir="rtl" style="color: #14307a; font-size: 24px; font-weight: 700; line-height: 1.8; margin-bottom: 2px;">
              <?= htmlspecialchars($compUrdu) ?>
            </div>
            <h4 class="mb-0" style="font-weight: 800; color: #14307a; letter-spacing: 0.5px;">
              <?= htmlspecialchars($compName) ?>
            </h4>
            <div style="font-size: 11.5px; color: #64748b; font-weight: 600; margin-top: 2px;">
              Gujranwala Steel Group of Industry · Prime Re-Rolled Steel Bars &amp; Structural Materials
            </div>
          </div>
        </div>
        <div class="text-end" style="font-size: 11.5px; line-height: 1.6;">
          <div><i class="fa-solid fa-phone me-1 text-primary"></i> <strong><?= htmlspecialchars($compPhone) ?></strong></div>
          <div><i class="fa-solid fa-location-dot me-1 text-primary"></i> <?= htmlspecialchars($compAddress) ?></div>
          <div class="badge bg-danger text-uppercase mt-1" style="font-size: 10.5px; letter-spacing: 0.5px; padding: 4px 8px;">
            <?= htmlspecialchars($docBadge) ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Metadata Row -->
    <div class="row g-2 mb-3">
      <div class="col-7">
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; height: 100%;">
          <div class="d-flex justify-content-between align-items-center" style="font-size: 10.5px; color: #64748b; font-weight: 700;">
            <span><?= $isSaleReturn ? 'CUSTOMER NAME & DETAILS' : 'SUPPLIER / MILL DETAILS' ?></span>
            <span class="ur" dir="rtl" style="font-size: 11px; line-height: 1.4;">تفصیل کھاتہ دار</span>
          </div>
          <div style="font-size: 16px; font-weight: 800; color: #0f172a; margin-top: 2px;">
            <?= htmlspecialchars($ret['party_name']) ?>
          </div>
          <div style="font-size: 11.5px; color: #475569; margin-top: 3px;">
            <?php if (!empty($ret['party_phone'])): ?>
              <div><i class="fa-solid fa-phone me-1"></i> Phone: <strong><?= htmlspecialchars($ret['party_phone']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($ret['party_address'])): ?>
              <div><i class="fa-solid fa-map-marker-alt me-1"></i> Address: <?= htmlspecialchars($ret['party_address']) ?></div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="col-5">
        <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; height: 100%;">
          <div class="d-flex justify-content-between align-items-center">
            <span style="font-size: 10.5px; color: #64748b; font-weight: 700;">RETURN VOUCHER #</span>
            <strong style="font-size: 15px; color: #b91c1c;">#<?= htmlspecialchars($ret['return_no']) ?></strong>
          </div>
          <div class="d-flex justify-content-between mt-1" style="font-size: 11.5px;">
            <span class="text-muted">Return Date:</span>
            <strong><?= date('d-M-Y', strtotime($ret['return_date'])) ?></strong>
          </div>
          <?php if (!empty($ret['reference_no'])): ?>
            <div class="d-flex justify-content-between mt-1" style="font-size: 11.5px;">
              <span class="text-muted">Original Ref #:</span>
              <strong class="text-primary"><?= htmlspecialchars($ret['reference_no']) ?></strong>
            </div>
          <?php endif; ?>
          <div class="d-flex justify-content-between mt-1" style="font-size: 11.5px;">
            <span class="text-muted">Refund Destination:</span>
            <span class="badge bg-secondary"><?= htmlspecialchars($ret['refund_type']) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Items Table -->
    <table class="table-note">
      <thead>
        <tr>
          <th style="width: 6%; text-align: center;">
            <div>Sr #</div>
            <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">نمبر</div>
          </th>
          <th style="width: 44%;">
            <div>Returned Item Description</div>
            <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">تفصیل مال و سریا</div>
          </th>
          <th style="width: 14%; text-align: center;">
            <div>Unit</div>
            <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">یونٹ</div>
          </th>
          <th style="width: 16%; text-align: center;">
            <div>Returned Qty (وزن)</div>
            <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">وزن کلوگرام</div>
          </th>
          <th style="width: 10%; text-align: right;">
            <div>Rate</div>
            <div class="ur" dir="rtl" style="font-size: 10px; line-height: 1.3;">ریٹ فی کلو</div>
          </th>
          <th style="width: 10%; text-align: right;">
            <div>Line Total</div>
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
              Rs <?= number_format((float)$it['unit_rate'], 2) ?>
            </td>
            <td style="text-align: right; font-weight: 700;" class="font-num">
              <?= number_format((float)$it['line_total'], 2) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="background: #f1f5f9; font-weight: 700;">
          <td colspan="3" class="text-end">Total Weight Returned:</td>
          <td class="text-center font-num" style="color: #14307a; font-size: 13px;">
            <?= number_format((float)$ret['total_qty'], 2) ?> kg
          </td>
          <td class="text-end">Subtotal:</td>
          <td class="text-end font-num" style="color: #14307a; font-size: 13px;">
            <?= number_format((float)$ret['subtotal'], 2) ?>
          </td>
        </tr>
      </tfoot>
    </table>

    <!-- Totals & Khata Adjustment Summary -->
    <div class="row g-2 align-items-start mt-2">
      <div class="col-7">
        <?php if (!empty($ret['reason']) || !empty($ret['notes'])): ?>
          <div style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; background: #fafafa; font-size: 11.5px;">
            <strong>Reason for Return (وجہ واپسی):</strong>
            <p class="mb-1 text-muted"><?= htmlspecialchars($ret['reason'] ?: 'Standard Customer Return') ?></p>
            <?php if (!empty($ret['notes'])): ?>
              <small class="text-muted">Notes: <?= htmlspecialchars($ret['notes']) ?></small>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Ledger Adjustment Box -->
        <div class="mt-2 p-2 border rounded" style="background: #f8fafc; font-size: 11.5px;">
          <div class="d-flex justify-content-between align-items-center py-1">
            <span>Adjustment Target:</span>
            <strong><?= $ret['refund_type'] === 'credit_to_khata' ? 'Adjusted into Party Ledger Khata' : 'Cash / Bank Paid' ?></strong>
          </div>
          <div class="d-flex justify-content-between align-items-center pt-1 border-top border-dark fw-bold" style="color: #14307a;">
            <span>Current Live Khata Balance:</span>
            <span class="font-num fs-6" style="color: #b91c1c;"><?= formatCurrency((float)$ret['party_current_balance']) ?></span>
          </div>
        </div>
      </div>

      <div class="col-5">
        <div style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; background: #fff; line-height: 1.8; font-size: 12px;">
          <div class="d-flex justify-content-between align-items-center py-1">
            <span>Return Gross Value:</span>
            <strong class="font-num"><?= formatMoney($ret['subtotal']) ?></strong>
          </div>

          <?php if ((float)$ret['deduction_charges'] > 0): ?>
            <div class="d-flex justify-content-between align-items-center py-1 text-danger">
              <span>Damage / Restocking Deductions:</span>
              <span class="font-num fw-bold">− <?= formatMoney($ret['deduction_charges']) ?></span>
            </div>
          <?php endif; ?>

          <div class="d-flex justify-content-between align-items-center py-1 border-top border-bottom border-2 border-dark fw-bold" style="color: #14307a; font-size: 14px;">
            <span>Net Refund / Credit Value:</span>
            <span class="font-num fs-5" style="color: #b91c1c;"><?= formatMoney($ret['net_amount']) ?></span>
          </div>
        </div>
      </div>
    </div>

    <!-- Signatures Footer -->
    <div style="margin-top: 50px; display: flex; justify-content: space-between; text-align: center; font-size: 11.5px; color: #475569;">
      <div style="width: 30%; border-top: 1px solid #94a3b8; padding-top: 6px;">
        <strong>Authorized Signatory (منجانب ظفر برادرز)</strong><br>
        <small class="text-muted"><?= htmlspecialchars($ret['cashier_name'] ?? 'Authorized Officer') ?></small>
      </div>
      <div style="width: 30%; border-top: 1px solid #94a3b8; padding-top: 6px;">
        <strong>Inventory Store Keeper (انچارج گودام)</strong><br>
        <small class="text-muted">Goods Received in Store</small>
      </div>
      <div style="width: 30%; border-top: 1px solid #94a3b8; padding-top: 6px;">
        <strong>Party Signature (دستخط وصول کنندہ / پارٹی)</strong><br>
        <small class="text-muted"><?= htmlspecialchars($ret['party_name']) ?></small>
      </div>
    </div>

  </div>
  <?php endif; ?>

</body>
</html>
