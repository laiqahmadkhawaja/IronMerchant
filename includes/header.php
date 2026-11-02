<?php
// includes/header.php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/functions.php';

requireAuth();

$currentUser = getCurrentUser();
$role = $currentUser['role'] ?? 'Employee';
$companySettings = getCompanySettings();
$companyName = $companySettings['company_name'] ?? 'Zafar Brothers Iron Merchants';
$companyUrdu = (!empty($companySettings['company_urdu']) && strpos($companySettings['company_urdu'], '?') === false) ? $companySettings['company_urdu'] : 'ظفر برادرز آئرن مرچنٹس';
?>
<!DOCTYPE html>
<html lang="ur" dir="ltr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> · <?= htmlspecialchars($companyName) ?></title>
  
  <!-- Bootstrap 5 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- FontAwesome 6 -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  <!-- Custom Design CSS -->
  <link rel="stylesheet" href="assets/css/style.css?v=<?= time() ?>">
</head>
<body>
<script>
  if (localStorage.getItem('sidebar_collapsed') === '1') {
    document.body.classList.add('sidebar-collapsed');
  }
  function toggleSidebarCollapse() {
    if (window.innerWidth < 992) {
      var sb = document.querySelector('.app-sidebar');
      if (sb) sb.classList.toggle('show');
    } else {
      document.body.classList.toggle('sidebar-collapsed');
      localStorage.setItem('sidebar_collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
    }
  }

  /**
   * Universally filters a financial account <select> based on a payment method <select> or string value.
   * Dynamically re-populates options in real-time so incompatible accounts are excluded from the dropdown.
   */
  function filterAccountsByPaymentMethod(methodEl, accountEl) {
    if (!accountEl) return;
    const methodVal = (typeof methodEl === 'string' ? methodEl : (methodEl ? methodEl.value : '')).trim().toLowerCase();

    // Cache master list of options once on accountEl
    if (!accountEl._masterAccountOptions) {
      accountEl._masterAccountOptions = Array.from(accountEl.querySelectorAll('option')).map(opt => ({
        value: opt.value,
        text: opt.text,
        type: (opt.dataset.type || opt.getAttribute('data-type') || '').toLowerCase(),
        name: (opt.dataset.name || opt.getAttribute('data-name') || opt.text || '').toLowerCase(),
        isPlaceholder: !opt.value || opt.value === '0' || opt.value === '',
        dataset: { ...opt.dataset }
      }));
    }

    const currentVal = String(accountEl.value || '');
    const filtered = [];
    let firstMatch = null;
    let currentMatched = false;

    accountEl._masterAccountOptions.forEach(item => {
      if (item.isPlaceholder) {
        filtered.push(item);
        return;
      }

      let matches = false;
      const name = item.name;
      const type = item.type;

      if (methodVal.includes('cash') && !methodVal.includes('jazz')) {
        // Cash: only Cash accounts or name containing cash/safe/drawer/counter/register
        matches = (type === 'cash') || name.includes('cash') || name.includes('safe') || name.includes('drawer') || name.includes('register');
      } else if (methodVal.includes('easypaisa') || methodVal.includes('easy paisa') || methodVal.includes('easy')) {
        // EasyPaisa: only EasyPaisa accounts or mobile wallet not containing jazz
        matches = name.includes('easypaisa') || name.includes('easy paisa') || name.includes('ep') || (type === 'mobile wallet' && !name.includes('jazz'));
      } else if (methodVal.includes('jazzcash') || methodVal.includes('jazz cash') || methodVal.includes('jazz')) {
        // JazzCash: only JazzCash accounts or mobile wallet not containing easy
        matches = name.includes('jazzcash') || name.includes('jazz cash') || name.includes('jazz') || (type === 'mobile wallet' && !name.includes('easy'));
      } else if (methodVal.includes('bank') || methodVal.includes('online') || methodVal.includes('cheque') || methodVal.includes('payorder')) {
        // Bank / Cheque / Online: only Bank accounts (excluding jazzcash/easypaisa)
        matches = (type === 'bank') && !name.includes('jazz') && !name.includes('easy');
      } else if (methodVal.includes('credit')) {
        // Credit: keep all
        matches = true;
      } else {
        matches = true;
      }

      if (matches) {
        filtered.push(item);
        if (!firstMatch) firstMatch = item.value;
        if (String(item.value) === currentVal) currentMatched = true;
      }
    });

    const nonPlaceholders = filtered.filter(x => !x.isPlaceholder);
    accountEl.innerHTML = '';

    if (nonPlaceholders.length === 0) {
      let label = 'Account';
      if (methodVal.includes('jazz')) label = 'JazzCash';
      else if (methodVal.includes('easy')) label = 'EasyPaisa';
      else if (methodVal.includes('cash')) label = 'Cash';
      else if (methodVal.includes('bank')) label = 'Bank';

      const opt = document.createElement('option');
      opt.value = '';
      opt.text = `⚠️ No active ${label} account found (add in Accounts)`;
      opt.disabled = true;
      opt.selected = true;
      accountEl.appendChild(opt);
      accountEl.value = '';
    } else {
      filtered.forEach(item => {
        const opt = document.createElement('option');
        opt.value = item.value;
        opt.text = item.text;
        for (const [k, v] of Object.entries(item.dataset)) {
          opt.dataset[k] = v;
        }
        accountEl.appendChild(opt);
      });

      if (currentMatched) {
        accountEl.value = currentVal;
      } else if (firstMatch) {
        accountEl.value = firstMatch;
      }
    }
  }

  // Auto-bind any data-filter-accounts-for elements on DOM ready
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-filter-accounts-for]').forEach(methodEl => {
      const selector = methodEl.getAttribute('data-filter-accounts-for');
      const accountEl = document.querySelector(selector);
      if (accountEl) {
        const applyFilter = () => filterAccountsByPaymentMethod(methodEl, accountEl);
        methodEl.addEventListener('change', applyFilter);
        applyFilter();
      }
    });
  });
</script>

<div class="app-wrapper">
  <!-- Modular Sidebar Navigation -->
  <?php require_once __DIR__ . '/sidebar.php'; ?>

  <!-- Main Content Layout -->
  <div class="app-main">
    <!-- Executive Header Banner -->
    <header class="header-banner">
      <!-- Left: Logo & Brand Information -->
      <div class="header-brand-left">
        <button type="button" class="btn btn-sm btn-outline-light me-2" onclick="toggleSidebarCollapse()" title="Toggle Sidebar">
          <i class="fa-solid fa-bars"></i>
        </button>

        <div class="header-logo-wrapper">
          <img src="assets/images/logo.png" alt="Zafar Brothers Logo">
        </div>

        <div class="header-brand-text">
          <div class="header-company-title"><?= htmlspecialchars($companyName) ?></div>
          <div class="header-company-subtitle">
            <span>Gujranwala Steel Group</span>
            <span class="badge">EST. 1988</span>
          </div>
        </div>
      </div>

      <!-- Center: Urdu Calligraphy & Motto -->
      <div class="header-center-crest d-none d-md-flex">
        <div class="header-urdu-title"><?= htmlspecialchars($companyUrdu) ?></div>
        <div class="header-urdu-motto">آپ کے اعتماد کا شکریہ — معیاری سریا، ایماندار وزن</div>
      </div>

      <!-- Right: User Identity & Digital Clock -->
      <div class="header-right-tools">
        <div class="header-user-pill d-none d-xl-flex align-items-center">
          <div class="header-user-avatar">
            <i class="fa-solid fa-user-tie"></i>
          </div>
          <div class="header-user-info me-2">
            <span class="user-name"><?= htmlspecialchars($currentUser['name'] ?? 'User') ?></span>
            <span class="user-role"><?= htmlspecialchars($role) ?></span>
          </div>
          <button type="button" class="btn btn-sm p-1 ms-1 border-0" onclick="openSelfPinModal()" title="Reset My PIN (اپنا پن تبدیل کریں)" style="background: rgba(0,0,0,0.06); border-radius: 6px; font-size: 11px; height: 26px; line-height: 1; color: var(--nv); font-weight: 600;">
            <i class="fa-solid fa-key text-warning me-1"></i> <span class="text-light">PIN</span>
          </button>
        </div>

        <?php
          $shiftHeader = function_exists('getActiveShift') ? getActiveShift() : null;
        ?>
        <a href="closing.php" class="text-decoration-none d-none d-lg-flex align-items-center gap-2 px-3 py-1 rounded-pill shadow-sm border <?= $shiftHeader ? 'bg-success text-white border-success' : 'bg-danger text-white border-danger' ?>" style="font-size: 11.5px; font-weight: 700; height: 32px;" title="<?= $shiftHeader ? 'Active Day Shift Open' : 'Click to Open Day Shift in Closing' ?>">
          <i class="fa-solid <?= $shiftHeader ? 'fa-circle-check' : 'fa-lock' ?>"></i>
          <span><?= $shiftHeader ? 'Shift: ' . htmlspecialchars($shiftHeader['closing_no']) : 'Shift Closed (شفٹ بند ہے)' ?></span>
        </a>

        <div class="header-clock-card" id="liveClockCard">
          <div class="clock-icon">
            <i class="fa-regular fa-clock"></i>
          </div>
          <div class="clock-details" id="liveClock">
            <div class="clock-time" id="liveClockTime"><?= date('h:i:s A') ?></div>
            <div class="clock-date" id="liveClockDate"><?= date('D, d M Y') ?></div>
          </div>
        </div>
      </div>
    </header>

    <!-- Page Body Container -->
    <main class="page-container">
      <?php 
        $flash = getFlash();
        if ($flash): 
      ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 8px;">
          <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-success' : 'fa-circle-exclamation text-danger' ?> me-2"></i>
          <strong><?= htmlspecialchars($flash['message']) ?></strong>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php elseif (!empty($_SESSION['flash_msg'])): ?>
        <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'success') ?> alert-dismissible fade show mb-4" role="alert" style="border-radius: 8px;">
          <strong><?= htmlspecialchars($_SESSION['flash_msg']) ?></strong>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php unset($_SESSION['flash_msg'], $_SESSION['flash_type']); ?>
      <?php endif; ?>
