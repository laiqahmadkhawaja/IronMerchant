<?php
// includes/sidebar.php - Modular Multi-Page Navigation
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
if (!function_exists('getCompanySettings')) {
    require_once __DIR__ . '/functions.php';
}

$companySettings     = $companySettings ?? getCompanySettings();
$sidebarBrandName    = $companyName ?? $companySettings['company_name'] ?? (defined('APP_NAME') ? APP_NAME : 'Iron Merchants');
$sidebarBrandTagline = $companySettings['company_tagline'] ?? (defined('APP_TAGLINE') ? APP_TAGLINE : 'Iron Merchants · ERP');

$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$currentUser = getCurrentUser();
$userRole    = $currentUser['role'] ?? 'Owner';
$userPerms   = $currentUser['permissions'] ?? [];

// Ensure permissions is an array
if (is_string($userPerms)) {
    $decoded = json_decode($userPerms, true);
    $userPerms = is_array($decoded) ? $decoded : [];
}
if (!is_array($userPerms)) {
    $userPerms = [];
}

// Case-insensitive Owner check
$isOwner = empty($userRole) || strcasecmp((string)$userRole, 'Owner') === 0;

// Safe fallback for staff/cashiers so sidebar is never blank
if (!$isOwner && empty($userPerms)) {
    $roleLower = strtolower((string)$userRole);
    if (str_contains($roleLower, 'cashier')) {
        $userPerms = ['dash', 'pos', 'bills', 'customers', 'closing'];
    } elseif (str_contains($roleLower, 'accountant')) {
        $userPerms = ['dash', 'bills', 'purchases', 'inventory', 'customers', 'suppliers', 'expenses', 'accounts', 'vouchers', 'transfers', 'closing', 'reports'];
    } elseif (str_contains($roleLower, 'purchase')) {
        $userPerms = ['dash', 'purchases', 'inventory', 'suppliers'];
    } else {
        $userPerms = ['dash', 'pos', 'bills', 'inventory', 'customers'];
    }
}

$menuItems = [
    'index.php'       => ['icon' => 'fa-chart-pie',          'text' => 'Dashboard',              'perm' => 'dash'],
    'pos.php'         => ['icon' => 'fa-cash-register',      'text' => 'New Bill (فروخت بل)',    'perm' => 'pos'],
    'bills.php'       => ['icon' => 'fa-file-invoice',       'text' => 'Sales Bills (سیل بلز)',  'perm' => 'bills'],
    'returns.php'     => ['icon' => 'fa-rotate-left',        'text' => 'Returns (واپسی مال)',    'perm' => 'returns'],
    'purchases.php'   => ['icon' => 'fa-truck-ramp-box',     'text' => 'Purchases (خریداری)',    'perm' => 'purchases'],
    'inventory.php'   => ['icon' => 'fa-boxes-stacked',      'text' => 'Stock & Valuation',      'perm' => 'inventory'],
    'customers.php'   => ['icon' => 'fa-users',              'text' => 'Customers (کسٹمر کھاتہ)', 'perm' => 'customers'],
    'suppliers.php'   => ['icon' => 'fa-industry',           'text' => 'Suppliers (سپلائرز)',     'perm' => 'suppliers'],
    'expenses.php'    => ['icon' => 'fa-receipt',            'text' => 'Expenses (اخراجات)',      'perm' => 'expenses'],
    'accounts.php'    => ['icon' => 'fa-building-columns',   'text' => 'Cash & Banks (کھاتے)',    'perm' => 'accounts'],
    'transfers.php'   => ['icon' => 'fa-money-bill-transfer', 'text' => 'Fund Transfers',         'perm' => 'transfers'],
    'vouchers.php'    => ['icon' => 'fa-book',               'text' => 'Vouchers & Journals',    'perm' => 'vouchers'],
    'closing.php'     => ['icon' => 'fa-lock',               'text' => 'Daily Closing (کلوزنگ)',  'perm' => 'closing'],
    'reports.php'     => ['icon' => 'fa-chart-line',         'text' => 'Financial Reports',      'perm' => 'reports'],
    'backup.php'      => ['icon' => 'fa-database',           'text' => 'Database Backup',        'perm' => 'backup'],
    'settings.php'    => ['icon' => 'fa-gear',               'text' => 'Settings (سیٹنگز)',      'perm' => 'settings']
];
?>
<aside class="app-sidebar">
  <!-- Dynamic Brand Header -->
  <div class="sidebar-brand">
    <img src="assets/images/logo.png" alt="Logo">
    <div style="min-width: 0; flex: 1;">
      <b title="<?= htmlspecialchars($sidebarBrandName) ?>" style="font-size: 13px; line-height: 1.25; word-break: break-word;"><?= htmlspecialchars($sidebarBrandName) ?></b>
      <span style="display: block; font-size: 10.5px; color: var(--am); font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($sidebarBrandTagline) ?>"><?= htmlspecialchars($sidebarBrandTagline) ?></span>
    </div>
  </div>

  <!-- Smooth Scrollable Navigation Area -->
  <div class="sidebar-scrollable">
    <ul class="sidebar-menu">
      <?php foreach ($menuItems as $url => $item): ?>
        <?php if ($isOwner || hasPermission($item['perm'])): ?>
          <li>
            <a href="<?= $url ?>" class="<?= ($currentPage === $url) ? 'active' : '' ?>">
              <span class="icon"><i class="fa-solid <?= $item['icon'] ?>"></i></span>
              <span><?= $item['text'] ?></span>
            </a>
          </li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ul>
  </div>

  <!-- Fixed Bottom User Actions Footer -->
  <div class="sidebar-footer" style="padding: 8px 12px; display: flex; flex-direction: column; gap: 4px;">
    <a href="#" onclick="openSelfPinModal(); return false;" style="display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.85); font-size: 12.5px; text-decoration: none; padding: 6px 10px; border-radius: 6px; background: rgba(255,255,255,0.05);">
      <span class="icon"><i class="fa-solid fa-key text-warning"></i></span>
      <span>Reset My PIN (پن کوڈ تبدیل)</span>
    </a>
    <a href="logout.php" onclick="return confirm('Are you sure you want to log out?');" style="display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.85); font-size: 12.5px; text-decoration: none; padding: 6px 10px; border-radius: 6px;">
      <span class="icon"><i class="fa-solid fa-right-from-bracket"></i></span>
      <span>Sign Out (لاگ آؤٹ)</span>
    </a>
  </div>
</aside>
