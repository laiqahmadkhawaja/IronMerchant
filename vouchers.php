<?php
// vouchers.php - Master Financial Vouchers & Audit Trail Browser
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/functions.php';

requireAuth();
requirePerm('vouchers');

$pdo = getPDO();
$pageTitle = 'Vouchers & Journals (مالیاتی واؤچرز و روزنامچہ)';

// Filters
$typeFilter = trim($_GET['type'] ?? '');
$search     = trim($_GET['search'] ?? '');
$startDate  = trim($_GET['start_date'] ?? date('Y-m-01'));
$endDate    = trim($_GET['end_date'] ?? date('Y-m-d'));

$sql = "
    SELECT v.*, 
           p.name AS party_name, p.type AS party_type,
           fa.account_name, fa.account_type,
           u.name AS user_name
    FROM vouchers v
    LEFT JOIN parties p ON v.party_id = p.id
    LEFT JOIN financial_accounts fa ON v.account_id = fa.id
    LEFT JOIN users u ON v.created_by = u.id
    WHERE v.voucher_date BETWEEN ? AND ?
";
$params = [$startDate, $endDate];

if ($typeFilter !== '') {
    $sql .= " AND v.voucher_type = ?";
    $params[] = $typeFilter;
}

if ($search !== '') {
    $sql .= " AND (v.voucher_no LIKE ? OR v.cheque_no LIKE ? OR v.narration LIKE ? OR p.name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY v.voucher_date DESC, v.id DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

// KPIs
$totalReceipts = 0.0;
$totalPayments = 0.0;
$totalExpenses = 0.0;
$totalTransfers = 0.0;
$totalJVs = 0.0;

foreach ($vouchers as $v) {
    $amt = (float)$v['amount'];
    match($v['voucher_type']) {
        'RV' => $totalReceipts += $amt,
        'PV' => $totalPayments += $amt,
        'EV' => $totalExpenses += $amt,
        'TV' => $totalTransfers += $amt,
        'JV' => $totalJVs += $amt,
        default => null
    };
}

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Financial Vouchers & Audit Trail (مالیاتی واؤچرز و روزنامچہ)</h4>
        <span class="text-muted" style="font-size: 13px;">Double-entry journal records, cash receipts, disbursements, and inter-account movements</span>
      </div>
      <div>
        <a href="journal_entry.php" class="btn btn-zb-primary">
          <i class="fa-solid fa-plus me-1"></i> New Manual Journal (جے وی درج کریں)
        </a>
      </div>
    </div>

    <!-- KPI Summary Cards -->
    <div class="row g-3 mb-4">
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--gn);">
          <div class="kpi-icon" style="background: rgba(22,163,74,0.1); color: var(--gn);"><i class="fa-solid fa-arrow-down"></i></div>
          <div class="kpi-value" style="font-size: 18px;"><?= formatCurrency($totalReceipts) ?></div>
          <div class="kpi-label">Receipts / RV (کل وصولیاں)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--rd);">
          <div class="kpi-icon" style="background: rgba(224,36,36,0.1); color: var(--rd);"><i class="fa-solid fa-arrow-up"></i></div>
          <div class="kpi-value" style="font-size: 18px;"><?= formatCurrency($totalPayments) ?></div>
          <div class="kpi-label">Payments / PV (ادائیگیاں سپلائرز)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--am);">
          <div class="kpi-icon" style="background: rgba(245,166,35,0.1); color: #b7791f;"><i class="fa-solid fa-receipt"></i></div>
          <div class="kpi-value" style="font-size: 18px;"><?= formatCurrency($totalExpenses) ?></div>
          <div class="kpi-label">Expenses / EV (اخراجات)</div>
        </div>
      </div>
      <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card" style="border-left: 4px solid var(--nv);">
          <div class="kpi-icon" style="background: rgba(20,48,122,0.1); color: var(--nv);"><i class="fa-solid fa-money-bill-transfer"></i></div>
          <div class="kpi-value" style="font-size: 18px;"><?= formatCurrency($totalTransfers) ?></div>
          <div class="kpi-label">Transfers / TV (اندرونی ٹرانسفر)</div>
        </div>
      </div>
    </div>

    <!-- Filter Bar -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-body p-3">
        <form method="GET" action="vouchers.php" class="row g-3 align-items-end">
          <div class="col-12 col-md-3">
            <label class="form-label">Search Voucher / Ref / Party</label>
            <input type="text" name="search" class="form-control" placeholder="Search by voucher #, cheque, party..." value="<?= htmlspecialchars($search) ?>">
          </div>
          <div class="col-12 col-md-3">
            <label class="form-label">Voucher Type</label>
            <select name="type" class="form-select">
              <option value="">All Voucher Types (تمام واؤچرز)</option>
              <option value="RV" <?= ($typeFilter === 'RV') ? 'selected' : '' ?>>Receipt Voucher (RV - وصولی)</option>
              <option value="PV" <?= ($typeFilter === 'PV') ? 'selected' : '' ?>>Payment Voucher (PV - ادائیگی)</option>
              <option value="EV" <?= ($typeFilter === 'EV') ? 'selected' : '' ?>>Expense Voucher (EV - خرچہ)</option>
              <option value="TV" <?= ($typeFilter === 'TV') ? 'selected' : '' ?>>Transfer Voucher (TV - ٹرانسفر)</option>
              <option value="JV" <?= ($typeFilter === 'JV') ? 'selected' : '' ?>>Journal Voucher (JV - جے وی)</option>
            </select>
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">From Date</label>
            <input type="date" name="start_date" class="form-control" value="<?= htmlspecialchars($startDate) ?>">
          </div>
          <div class="col-12 col-md-2">
            <label class="form-label">To Date</label>
            <input type="date" name="end_date" class="form-control" value="<?= htmlspecialchars($endDate) ?>">
          </div>
          <div class="col-12 col-md-2 d-flex gap-2">
            <button type="submit" class="btn btn-zb-primary w-100">
              <i class="fa-solid fa-filter me-1"></i> Filter
            </button>
            <a href="vouchers.php" class="btn btn-outline-secondary" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
          </div>
        </form>
      </div>
    </div>

    <!-- Vouchers List Table -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Voucher Ledger: <?= htmlspecialchars($startDate) ?> to <?= htmlspecialchars($endDate) ?></h6>
        <span class="badge bg-light text-dark border"><?= count($vouchers) ?> vouchers</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Voucher #</th>
              <th style="padding: 12px 14px;">Date</th>
              <th class="text-center" style="padding: 12px 14px;">Type</th>
              <th style="padding: 12px 14px;">Account / Party</th>
              <th class="text-end" style="padding: 12px 14px;">Amount (روپے)</th>
              <th style="padding: 12px 14px;">Mode & Ref</th>
              <th style="padding: 12px 14px;">Narration</th>
              <th style="padding: 12px 14px;">Created By</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($vouchers)): ?>
              <tr>
                <td colspan="8" class="text-center py-4 text-muted">No vouchers found matching your query.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($vouchers as $v): 
                $badgeStyle = match($v['voucher_type']) {
                    'RV' => 'bg-success text-white',
                    'PV' => 'bg-danger text-white',
                    'EV' => 'bg-warning text-dark',
                    'TV' => 'bg-info text-dark',
                    'JV' => 'bg-secondary text-white',
                    default => 'bg-dark text-white'
                };
              ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 700; color: var(--nv);">
                    <?= htmlspecialchars($v['voucher_no']) ?>
                  </td>
                  <td style="padding: 12px 14px;"><?= htmlspecialchars($v['voucher_date']) ?></td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <span class="badge <?= $badgeStyle ?>" style="font-size: 12px; font-weight: 700;">
                      <?= htmlspecialchars($v['voucher_type']) ?>
                    </span>
                  </td>
                  <td style="padding: 12px 14px;">
                    <?php if (!empty($v['party_name'])): ?>
                      <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($v['party_name']) ?></div>
                      <small class="badge bg-light text-secondary border"><?= ucfirst($v['party_type'] ?? 'Party') ?></small>
                    <?php elseif (!empty($v['account_name'])): ?>
                      <div style="font-weight: 600; color: #1e293b;"><?= htmlspecialchars($v['account_name']) ?></div>
                      <small class="badge bg-light text-secondary border"><?= htmlspecialchars($v['account_type']) ?></small>
                    <?php else: ?>
                      <span class="text-muted">General Ledger</span>
                    <?php endif; ?>
                  </td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 700; font-size: 14.5px;">
                    <?= formatCurrency((float)$v['amount']) ?>
                  </td>
                  <td style="padding: 12px 14px;">
                    <div><?= htmlspecialchars($v['payment_mode'] ?? 'Cash') ?></div>
                    <?php if (!empty($v['cheque_no'])): ?>
                      <small class="text-muted">Ref: <?= htmlspecialchars($v['cheque_no']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 12px 14px; color: #475569;">
                    <?= htmlspecialchars($v['narration'] ?? '-') ?>
                  </td>
                  <td style="padding: 12px 14px; color: #64748b; font-size: 12.5px;">
                    <?= htmlspecialchars($v['user_name'] ?? 'System') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
