<?php
// print_purchase.php - Goods Receipt Note (GRN) & Purchase Invoice Print (A4 & Thermal 80mm/58mm)
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
requirePerm('purchases');

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT pr.*, p.name AS supplier_name, p.phone AS supplier_phone, p.address AS supplier_address, 
           p.company_name AS supplier_company, p.current_balance AS supplier_current_balance, 
           u.name AS operator_name
    FROM purchases pr
    JOIN parties p ON pr.supplier_id = p.id
    LEFT JOIN users u ON pr.created_by = u.id
    WHERE pr.id = ?
");
$stmt->execute([$id]);
$purchase = $stmt->fetch();

if (!$purchase) {
    die("<h3>Purchase record not found.</h3><p><a href='purchases.php'>Back to Purchases</a></p>");
}

$itemsStmt = $db->prepare("
    SELECT pi.*, i.name AS item_name, i.unit, i.item_code
    FROM purchase_items pi
    JOIN items i ON pi.item_id = i.id
    WHERE pi.purchase_id = ?
");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll();

$chargesStmt = $db->prepare("SELECT * FROM purchase_charges WHERE purchase_id = ?");
$chargesStmt->execute([$id]);
$charges = $chargesStmt->fetchAll();

$company = getCompanySettings();
$compName = $company['company_name'] ?? 'Zafar Brothers Iron Merchants';
$compUrdu = (!empty($company['company_urdu']) && strpos($company['company_urdu'], '?') === false) ? $company['company_urdu'] : 'ظفر برادرز آئرن مرچنٹس';
$compPhone = $company['company_phone'] ?? '0300-9641483 · 0300-7499499';
$compAddress = $company['company_address'] ?? 'Old Chungi, Hafizabad Road, Gujranwala';

$size = trim((string)($_GET['size'] ?? 'A4'));
if ($size === '') {
    $size = 'A4';
}
$isThermal = ($size === '80' || $size === '58');

// Balance computations
$billDue = (float)$purchase['due_amount'];
$currBalance = (float)$purchase['supplier_current_balance'];
$prevBalance = $currBalance - $billDue;

// Total weight
$totalWeight = 0.0;
$totalBaseAmount = 0.0;
$totalLandedCost = 0.0;
foreach ($items as $it) {
    $totalWeight += (float)$it['qty'];
    $totalBaseAmount += (float)$it['base_amount'];
    $totalLandedCost += (float)$it['landed_cost'];
}
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
  <meta charset="utf-8">
  <title><?= htmlspecialchars($purchase['purchase_no']) ?> · Goods Receipt Note &amp; Purchase Invoice</title>
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
      max-width: 880px;
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
    .note-sheet {
      background: #fff;
      max-width: 880px;
      margin: 0 auto;
      box-shadow: 0 10px 30px rgba(0,0,0,0.25);
      border-radius: 6px;
      padding: 28px;
      min-height: 980px;
    }
    .table-note {
      width: 100%;
      border-collapse: collapse;
      margin: 12px 0;
    }
    .table-note th {
      background: #0f172a;
      color: #fff;
      padding: 8px 10px;
      font-size: 12px;
      font-weight: 700;
      border: 1px solid #0f172a;
    }
    .table-note td {
      padding: 8px 10px;
      font-size: 12.5px;
      border: 1px solid #cbd5e1;
      color: #0f172a;
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

    .thermal-receipt .th-center { text-align: center; }
    .thermal-receipt .th-row {
      display: flex;
      justify-content: space-between;
      align-items: baseline;
      padding: 1.5px 0;
    }
    .thermal-receipt .th-line {
      border-top: 1.5px dashed #000;
      margin: 4px 0;
    }
    .thermal-receipt .th-double {
      border-top: 2px solid #000;
      border-bottom: 2px solid #000;
      margin: 4px 0;
      padding: 3px 0;
    }

    @media print {
      body {
        background: #fff !important;
        padding: 0 !important;
        margin: 0 !important;
        color: #000 !important;
      }
      .action-bar {
        display: none !important;
      }
      .note-sheet {
        box-shadow: none !important;
        padding: 0 !important;
        max-width: 100% !important;
        min-height: auto !important;
      }
      .thermal-receipt {
        box-shadow: none !important;
        margin: 0 auto !important;
        padding: 0 !important;
      }
      .thermal-receipt.size-80 {
        width: 72mm !important;
        max-width: 72mm !important;
      }
      .thermal-receipt.size-58 {
        width: 48mm !important;
        max-width: 48mm !important;
      }
      @page {
        margin: <?= $isThermal ? '0mm' : '10mm' ?>;
        size: <?= ($size === '80') ? '80mm auto' : (($size === '58') ? '58mm auto' : 'A4 portrait') ?>;
      }
    }
  </style>
</head>
<body>

  <!-- Top Toolbar -->
  <div class="action-bar">
    <div class="d-flex align-items-center gap-2">
      <a href="purchases.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa-solid fa-arrow-left me-1"></i> Back to Purchases
      </a>
      <span class="fw-bold" style="color: #0f172a; font-size: 14px;">
        GRN / Purchase: <?= htmlspecialchars($purchase['purchase_no']) ?>
      </span>
    </div>

    <div class="d-flex align-items-center gap-2">
      <div class="btn-group btn-group-sm" role="group">
        <a href="print_purchase.php?id=<?= $id ?>&size=A4" class="btn <?= ($size === 'A4') ? 'btn-dark' : 'btn-outline-dark' ?>">
          <i class="fa-solid fa-file-lines me-1"></i> A4 Sheet
        </a>
        <a href="print_purchase.php?id=<?= $id ?>&size=80" class="btn <?= ($size === '80') ? 'btn-dark' : 'btn-outline-dark' ?>">
          <i class="fa-solid fa-receipt me-1"></i> 80mm Thermal (3")
        </a>
        <a href="print_purchase.php?id=<?= $id ?>&size=58" class="btn <?= ($size === '58') ? 'btn-dark' : 'btn-outline-dark' ?>">
          <i class="fa-solid fa-receipt me-1"></i> 58mm Thermal (2")
        </a>
      </div>
      <button onclick="window.print()" class="btn btn-sm btn-primary">
        <i class="fa-solid fa-print me-1"></i> Print / پرنٹ
      </button>
    </div>
  </div>

  <?php if ($isThermal): ?>
    <!-- ==================== ESC/POS THERMAL RECEIPT (80mm & 58mm) ==================== -->
    <div class="thermal-receipt size-<?= $size ?>">
      <div class="th-center">
        <div class="ur th-company-ur"><?= htmlspecialchars($compUrdu) ?></div>
        <div class="th-company-en"><?= htmlspecialchars($compName) ?></div>
        <div class="th-sub"><?= htmlspecialchars($compAddress) ?></div>
        <div class="th-sub font-num">TEL: <?= htmlspecialchars($compPhone) ?></div>
        <div class="th-double" style="margin-top: 5px;">
          <div style="letter-spacing: 1px;">GOODS RECEIPT NOTE (GRN)</div>
          <div class="ur" style="font-size: 11px;">رسید خریداری مال و سٹاک انوارڈ</div>
        </div>
      </div>

      <!-- Metadata -->
      <div class="th-row th-meta">
        <span>GRN #: <?= htmlspecialchars($purchase['purchase_no']) ?></span>
        <span><?= htmlspecialchars($purchase['purchase_date']) ?></span>
      </div>
      <div class="th-row th-meta">
        <span>MILL / SUPP:</span>
        <span><?= htmlspecialchars($purchase['supplier_name']) ?></span>
      </div>
      <?php if (!empty($purchase['supplier_inv_no'])): ?>
        <div class="th-row th-meta">
          <span>MILL INV / BILTY:</span>
          <span><?= htmlspecialchars($purchase['supplier_inv_no']) ?></span>
        </div>
      <?php endif; ?>
      <div class="th-row th-meta">
        <span>YARD / WAREHOUSE:</span>
        <span><?= htmlspecialchars($purchase['warehouse'] ?? 'Main Yard') ?></span>
      </div>
      <div class="th-row th-meta">
        <span>RECEIVED BY:</span>
        <span><?= htmlspecialchars($purchase['operator_name'] ?? 'Admin') ?></span>
      </div>

      <div class="th-line"></div>

      <!-- Item Table Header -->
      <div class="th-row th-meta">
        <span>ITEM / QTY</span>
        <span>LANDED RATE</span>
        <span>TOTAL (RS)</span>
      </div>
      <div class="th-line"></div>

      <!-- Items List -->
      <?php foreach ($items as $idx => $it): ?>
        <div style="margin-bottom: 4px;">
          <div class="th-item-title"><?= ($idx + 1) ?>. <?= htmlspecialchars($it['item_name']) ?></div>
          <div class="th-row th-item-sub">
            <span><?= formatQty($it['qty'], $it['unit']) ?> @ <?= number_format((float)$it['landed_unit_rate'], 2) ?></span>
            <span class="font-num"><?= number_format((float)$it['landed_cost'], 2) ?></span>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="th-line"></div>

      <!-- Additional Charges if any -->
      <?php if (!empty($charges)): ?>
        <div class="th-center" style="font-size: 10px; margin: 2px 0;">-- LANDED CHARGES BREAKDOWN --</div>
        <?php foreach ($charges as $ch): ?>
          <div class="th-row th-meta">
            <span><?= htmlspecialchars($ch['charge_name']) ?><?= !empty($ch['payee_name']) ? ' (' . htmlspecialchars($ch['payee_name']) . ')' : '' ?>:</span>
            <span class="font-num">+ <?= number_format((float)$ch['amount'], 2) ?></span>
          </div>
        <?php endforeach; ?>
        <div class="th-line"></div>
      <?php endif; ?>

      <!-- Totals -->
      <div class="th-row th-meta">
        <span>Total Steel Weight:</span>
        <span class="font-num"><?= number_format($totalWeight, 2) ?> kg</span>
      </div>
      <div class="th-row th-meta">
        <span>Base Goods Total:</span>
        <span class="font-num"><?= number_format((float)$purchase['subtotal'], 2) ?></span>
      </div>
      <?php if ((float)$purchase['additional_charges'] > 0): ?>
        <div class="th-row th-meta">
          <span>Freight / Handling:</span>
          <span class="font-num">+ <?= number_format((float)$purchase['additional_charges'], 2) ?></span>
        </div>
      <?php endif; ?>
      <?php if ((float)$purchase['discount'] > 0): ?>
        <div class="th-row th-meta">
          <span>Discount:</span>
          <span class="font-num">- <?= number_format((float)$purchase['discount'], 2) ?></span>
        </div>
      <?php endif; ?>

      <div class="th-double th-row th-total">
        <span>TOTAL PAYABLE:</span>
        <span class="font-num">Rs <?= number_format((float)$purchase['grand_total'], 2) ?></span>
      </div>

      <div class="th-row th-due">
        <span>Paid Amount:</span>
        <span class="font-num"><?= number_format((float)$purchase['paid_amount'], 2) ?></span>
      </div>
      <div class="th-row th-due">
        <span>This Bill Due:</span>
        <span class="font-num"><?= number_format((float)$purchase['due_amount'], 2) ?></span>
      </div>

      <!-- Supplier Ledger Balance -->
      <div class="th-line"></div>
      <div class="th-row th-meta">
        <span>Previous Balance:</span>
        <span class="font-num"><?= formatCurrency($prevBalance) ?></span>
      </div>
      <div class="th-row th-khata">
        <span>TOTAL SUPPLIER DUE:</span>
        <span class="font-num"><?= formatCurrency($currBalance) ?></span>
      </div>

      <div class="th-line"></div>

      <!-- Footer -->
      <div class="th-center" style="margin-top: 6px;">
        <div class="ur th-footer">وزن کانٹا اور سٹاک گودام میں درست وصول پایا گیا۔</div>
        <div class="th-footer" style="margin-top: 3px;">*** STOCK INWARD ACKNOWLEDGED ***</div>
        <div class="th-credits" style="margin-top: 2px;">Software by Antigravity POS</div>
        <div style="letter-spacing: 2px; margin-top: 2px;">- - - - - - - - - - - - - -</div>
      </div>
    </div>

  <?php else: ?>
    <!-- ==================== A4 STANDARD PROFESSIONAL GRN & PURCHASE INVOICE ==================== -->
    <div class="note-sheet">

      <!-- Company Letterhead -->
      <div style="border-bottom: 3px solid #0f172a; padding-bottom: 12px; margin-bottom: 16px;">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div class="d-flex align-items-center gap-3">
            <img src="assets/images/logo.png" alt="Logo" style="width: 70px; height: 70px; object-fit: contain;">
            <div>
              <div class="ur" style="color: #0f172a; font-size: 26px; font-weight: 700; line-height: 1.8; margin-bottom: 0;">
                <?= htmlspecialchars($compUrdu) ?>
              </div>
              <div style="font-size: 18px; font-weight: 800; color: #0f172a; letter-spacing: 0.5px;">
                <?= htmlspecialchars($compName) ?>
              </div>
              <div style="font-size: 12px; color: #64748b; font-weight: 600;">
                <?= htmlspecialchars($compAddress) ?> · Tel: <?= htmlspecialchars($compPhone) ?>
              </div>
            </div>
          </div>
          <div class="text-end">
            <div style="display: inline-block; background: #0f172a; color: #fff; font-size: 13px; font-weight: 800; padding: 6px 14px; border-radius: 4px; letter-spacing: 1px;">
              GOODS RECEIPT NOTE (GRN)
            </div>
            <div class="ur text-muted mt-1" style="font-size: 13px; font-weight: 600;">
              خریداری بل و رسید وصولی مال
            </div>
            <div class="mt-1">
              <span class="badge bg-success" style="font-size: 11px;">Stock Inward (گودام داخل)</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Two Summary Cards: Supplier & Purchase Metadata -->
      <div class="row g-3 mb-3">
        <!-- Supplier Box -->
        <div class="col-6">
          <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px; height: 100%;">
            <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
              Supplier / Mill Details (سپلائر / مل تفصیلات)
            </div>
            <div style="font-size: 16px; font-weight: 700; color: #0f172a; margin-bottom: 2px;">
              <?= htmlspecialchars($purchase['supplier_name']) ?>
            </div>
            <?php if (!empty($purchase['supplier_company'])): ?>
              <div style="font-size: 13px; font-weight: 600; color: #334155;">
                <i class="fa-solid fa-building me-1 text-secondary"></i> <?= htmlspecialchars($purchase['supplier_company']) ?>
              </div>
            <?php endif; ?>
            <div style="font-size: 12.5px; color: #475569;">
              <i class="fa-solid fa-phone me-1 text-secondary"></i> <?= htmlspecialchars($purchase['supplier_phone'] ?? '-') ?>
            </div>
            <div style="font-size: 12px; color: #64748b;">
              <i class="fa-solid fa-location-dot me-1 text-secondary"></i> <?= htmlspecialchars($purchase['supplier_address'] ?? '-') ?>
            </div>
            <?php if (!empty($purchase['supplier_inv_no'])): ?>
              <div class="mt-2 pt-1 border-top" style="font-size: 12px; font-weight: 600; color: #1e293b;">
                Mill Bilty / Inv #: <span class="badge bg-light text-dark border"><?= htmlspecialchars($purchase['supplier_inv_no']) ?></span>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Purchase / GRN Metadata Box -->
        <div class="col-6">
          <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 12px 14px; height: 100%;">
            <div style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
              Purchase Inward Metadata (خریداری و گودام اندراج)
            </div>
            <table style="width: 100%; font-size: 12.5px;">
              <tr>
                <td style="color: #64748b; font-weight: 600; padding: 2px 0;">GRN Document #:</td>
                <td class="text-end font-num fw-bold" style="color: #0f172a;"><?= htmlspecialchars($purchase['purchase_no']) ?></td>
              </tr>
              <tr>
                <td style="color: #64748b; font-weight: 600; padding: 2px 0;">Purchase Date:</td>
                <td class="text-end font-num"><?= htmlspecialchars($purchase['purchase_date']) ?></td>
              </tr>
              <tr>
                <td style="color: #64748b; font-weight: 600; padding: 2px 0;">Warehouse / Yard:</td>
                <td class="text-end fw-semibold"><?= htmlspecialchars($purchase['warehouse'] ?? 'Main Yard') ?></td>
              </tr>
              <tr>
                <td style="color: #64748b; font-weight: 600; padding: 2px 0;">Payment Status:</td>
                <td class="text-end">
                  <?php if ($purchase['payment_status'] === 'Paid'): ?>
                    <span class="badge bg-success" style="font-size: 10.5px;">Paid</span>
                  <?php elseif ($purchase['payment_status'] === 'Partial'): ?>
                    <span class="badge bg-warning text-dark" style="font-size: 10.5px;">Partial</span>
                  <?php else: ?>
                    <span class="badge bg-danger" style="font-size: 10.5px;">Unpaid / Credit</span>
                  <?php endif; ?>
                </td>
              </tr>
              <tr>
                <td style="color: #64748b; font-weight: 600; padding: 2px 0;">Received By:</td>
                <td class="text-end"><?= htmlspecialchars($purchase['operator_name'] ?? 'Admin') ?></td>
              </tr>
            </table>
          </div>
        </div>
      </div>

      <!-- Items Table -->
      <table class="table-note">
        <thead>
          <tr>
            <th class="text-center" style="width: 45px;">#</th>
            <th>Item Description (تفصیل مال / سریا)</th>
            <th class="text-end" style="width: 100px;">Weight (وزن)</th>
            <th class="text-end" style="width: 105px;">Base Rate (Rs/kg)</th>
            <th class="text-end" style="width: 110px;">Base Total</th>
            <th class="text-end" style="width: 110px;">Landed Charges</th>
            <th class="text-end" style="width: 115px;">Total Landed Cost</th>
            <th class="text-end" style="width: 110px;">Net Cost/kg</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $idx => $it): ?>
            <tr>
              <td class="text-center font-num"><?= ($idx + 1) ?></td>
              <td>
                <strong style="color: #0f172a;"><?= htmlspecialchars($it['item_name']) ?></strong>
                <?php if (!empty($it['item_code'])): ?>
                  <span class="text-muted small ms-1">(<?= htmlspecialchars($it['item_code']) ?>)</span>
                <?php endif; ?>
              </td>
              <td class="text-end font-num fw-bold"><?= formatQty($it['qty'], $it['unit']) ?></td>
              <td class="text-end font-num"><?= number_format((float)$it['unit_rate'], 2) ?></td>
              <td class="text-end font-num"><?= number_format((float)$it['base_amount'], 2) ?></td>
              <td class="text-end font-num text-primary">+ <?= number_format((float)$it['allocated_charge'], 2) ?></td>
              <td class="text-end font-num fw-bold" style="color: #0f172a;"><?= number_format((float)$it['landed_cost'], 2) ?></td>
              <td class="text-end font-num fw-bold text-success">Rs <?= number_format((float)$it['landed_unit_rate'], 2) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background: #e2e8f0; font-weight: 700;">
            <td colspan="2" class="text-end">Total Steel Received (کل موصول شدہ وزن):</td>
            <td class="text-end font-num"><?= number_format($totalWeight, 2) ?> kg</td>
            <td></td>
            <td class="text-end font-num"><?= number_format($totalBaseAmount, 2) ?></td>
            <td class="text-end font-num text-primary">+ <?= number_format((float)$purchase['additional_charges'], 2) ?></td>
            <td class="text-end font-num"><?= number_format($totalLandedCost, 2) ?></td>
            <td class="text-end font-num text-success">
              <?= $totalWeight > 0 ? 'Rs ' . number_format($totalLandedCost / $totalWeight, 2) . '/kg' : '-' ?>
            </td>
          </tr>
        </tfoot>
      </table>

      <!-- Two Lower Blocks: Charges Breakdown & Financial Summary -->
      <div class="row g-3 mt-1">
        <!-- Charges Breakdown -->
        <div class="col-6">
          <div style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 12px; height: 100%;">
            <div style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">
              <i class="fa-solid fa-truck-ramp-box me-1 text-primary"></i> Landed Charges Breakdown (کرایہ و اخراجات)
            </div>
            <?php if (empty($charges)): ?>
              <div class="text-muted small py-2">No additional landed charges entered for this purchase.</div>
            <?php else: ?>
              <table style="width: 100%; font-size: 12px;">
                <?php foreach ($charges as $ch): ?>
                  <tr>
                    <td style="padding: 3px 0; color: #334155;">
                      <strong><?= htmlspecialchars($ch['charge_name']) ?></strong>
                      <?php if (!empty($ch['payee_name'])): ?>
                        <span class="text-muted small">(Paid to: <?= htmlspecialchars($ch['payee_name']) ?>)</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end font-num fw-bold text-primary" style="padding: 3px 0;">
                      + Rs <?= number_format((float)$ch['amount'], 2) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </table>
            <?php endif; ?>

            <?php if (!empty($purchase['notes'])): ?>
              <div class="mt-3 pt-2 border-top">
                <span class="text-muted small fw-bold">Remarks / Notes:</span>
                <div style="font-size: 12px; color: #334155; font-style: italic;"><?= nl2br(htmlspecialchars($purchase['notes'])) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Financial Accounting Summary -->
        <div class="col-6">
          <div style="border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px 14px; background: #f8fafc;">
            <div style="font-size: 11px; font-weight: 700; color: #475569; text-transform: uppercase; margin-bottom: 6px;">
              <i class="fa-solid fa-calculator me-1 text-success"></i> Financial Summary (حساب کتاب کا خلاصہ)
            </div>
            <table style="width: 100%; font-size: 12.5px;">
              <tr>
                <td style="color: #64748b; padding: 2px 0;">Items Base Subtotal:</td>
                <td class="text-end font-num"><?= formatCurrency($purchase['subtotal']) ?></td>
              </tr>
              <?php if ((float)$purchase['additional_charges'] > 0): ?>
                <tr>
                  <td style="color: #64748b; padding: 2px 0;">Landed Additional Charges:</td>
                  <td class="text-end font-num text-primary">+ <?= formatCurrency($purchase['additional_charges']) ?></td>
                </tr>
              <?php endif; ?>
              <?php if ((float)$purchase['discount'] > 0): ?>
                <tr>
                  <td style="color: #64748b; padding: 2px 0;">Discount Received:</td>
                  <td class="text-end font-num text-success">- <?= formatCurrency($purchase['discount']) ?></td>
                </tr>
              <?php endif; ?>
              <tr style="border-top: 1.5px solid #cbd5e1; border-bottom: 1.5px solid #cbd5e1;">
                <td style="padding: 6px 0; font-weight: 800; color: #0f172a; font-size: 13.5px;">GRAND TOTAL PAYABLE:</td>
                <td class="text-end font-num fw-bold" style="font-size: 14.5px; color: #0f172a;"><?= formatCurrency($purchase['grand_total']) ?></td>
              </tr>
              <tr>
                <td style="color: #64748b; padding: 3px 0;">Paid Amount (ادائیگی):</td>
                <td class="text-end font-num text-success fw-bold"><?= formatCurrency($purchase['paid_amount']) ?></td>
              </tr>
              <tr>
                <td style="color: #64748b; padding: 3px 0;">Bill Due Amount (بقایا بل):</td>
                <td class="text-end font-num fw-bold <?= (float)$purchase['due_amount'] > 0 ? 'text-danger' : 'text-muted' ?>">
                  <?= formatCurrency($purchase['due_amount']) ?>
                </td>
              </tr>

              <!-- Supplier Khata Ledger -->
              <tr style="border-top: 1px dashed #cbd5e1;">
                <td style="color: #64748b; padding: 4px 0;">Supplier Previous Khata:</td>
                <td class="text-end font-num"><?= formatCurrency($prevBalance) ?></td>
              </tr>
              <tr style="background: #f1f5f9;">
                <td style="padding: 5px 6px; font-weight: 800; color: #b91c1c; font-size: 13px;">TOTAL SUPPLIER KHATA DUE:</td>
                <td class="text-end font-num fw-bold" style="padding: 5px 6px; font-size: 13.5px; color: #b91c1c;">
                  <?= formatCurrency($currBalance) ?>
                </td>
              </tr>
            </table>
          </div>
        </div>
      </div>

      <!-- Operational Signatures Section -->
      <div style="margin-top: 48px; border-top: 1.5px solid #cbd5e1; padding-top: 18px;">
        <div class="row text-center" style="font-size: 12px; font-weight: 600; color: #475569;">
          <div class="col-3">
            <div style="border-bottom: 1px dotted #94a3b8; height: 35px; margin-bottom: 6px;"></div>
            <span>Weighbridge / کانٹا انچارج</span>
          </div>
          <div class="col-3">
            <div style="border-bottom: 1px dotted #94a3b8; height: 35px; margin-bottom: 6px;"></div>
            <span>Stock Receiver / گودام انچارج</span>
          </div>
          <div class="col-3">
            <div style="border-bottom: 1px dotted #94a3b8; height: 35px; margin-bottom: 6px;"></div>
            <span>Driver / گاڑی ڈرائیور</span>
          </div>
          <div class="col-3">
            <div style="border-bottom: 1px dotted #94a3b8; height: 35px; margin-bottom: 6px;"></div>
            <span>Authorized Signature / دستخط مجاز</span>
          </div>
        </div>
      </div>

      <!-- Bottom Tagline -->
      <div class="text-center mt-4 text-muted small ur" style="font-size: 11.5px;">
        مال تول کر پورا اور تسلی بخش حالت میں گودام میں داخل کیا گیا — ظفر برادرز آئرن مرچنٹس گوجرانوالہ
      </div>

    </div>
  <?php endif; ?>

</body>
</html>
