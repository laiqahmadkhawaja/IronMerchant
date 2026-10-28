<?php
// includes/permissions.php - Granular Page & Module Access Control
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Authoritative Catalog of All Modules / Pages in the System.
 */
function getAvailableModules(): array {
    return [
        'core' => [
            'title' => 'Main & Overview (مرکزی)',
            'icon'  => 'fa-house',
            'modules' => [
                'dash' => [
                    'title'    => 'Dashboard',
                    'title_ur' => 'کاروباری جائزہ',
                    'url'      => 'index.php',
                    'icon'     => 'fa-chart-pie',
                    'desc'     => 'Business KPI metrics, daily cash position, sales and revenue charts'
                ],
            ]
        ],
        'sales' => [
            'title' => 'Sales Counter & Invoicing (سیل اور بلنگ)',
            'icon'  => 'fa-cash-register',
            'modules' => [
                'pos' => [
                    'title'    => 'New Sale Bill / POS',
                    'title_ur' => 'نیا بل بنائیں',
                    'url'      => 'pos.php',
                    'icon'     => 'fa-cash-register',
                    'desc'     => 'Counter point of sale, weight-based steel billing, discount & labour entry'
                ],
                'bills' => [
                    'title'    => 'Sales Bills History',
                    'title_ur' => 'سیل بلز ریکارڈ',
                    'url'      => 'bills.php',
                    'icon'     => 'fa-file-invoice',
                    'desc'     => 'Search and view past customer sales bills, print thermal slips & A4 invoices'
                ],
                'returns' => [
                    'title'    => 'Returns & Credit Notes',
                    'title_ur' => 'واپسی مال / کریڈٹ نوٹ',
                    'url'      => 'returns.php',
                    'icon'     => 'fa-rotate-left',
                    'desc'     => 'Customer sales returns and supplier purchase returns with weighted-average cost adjustment'
                ],
            ]
        ],
        'inventory' => [
            'title' => 'Purchases & Inventory (خریداری و اسٹاک)',
            'icon'  => 'fa-boxes-stacked',
            'modules' => [
                'purchases' => [
                    'title'    => 'Purchases & Landed Cost',
                    'title_ur' => 'خریداری و لینڈڈ لاگت',
                    'url'      => 'purchases.php',
                    'icon'     => 'fa-truck-ramp-box',
                    'desc'     => 'Mill steel purchases, freight & labour landed cost allocation'
                ],
                'inventory' => [
                    'title'    => 'Stock & Valuation',
                    'title_ur' => 'اسٹاک و مالیت',
                    'url'      => 'inventory.php',
                    'icon'     => 'fa-boxes-stacked',
                    'desc'     => 'Real-time rebar/girder warehouse balances and moving weighted-average cost'
                ],
            ]
        ],
        'parties' => [
            'title' => 'Parties & Ledgers (کھاتے اور ڈیلرز)',
            'icon'  => 'fa-users',
            'modules' => [
                'customers' => [
                    'title'    => 'Customer Directory & Khata',
                    'title_ur' => 'گاہک کھاتہ',
                    'url'      => 'customers.php',
                    'icon'     => 'fa-users',
                    'desc'     => 'Customer credit balances, detailed ledger reports, WhatsApp receipts'
                ],
                'suppliers' => [
                    'title'    => 'Supplier Directory & Khata',
                    'title_ur' => 'سپلائرز کھاتہ',
                    'url'      => 'suppliers.php',
                    'icon'     => 'fa-industry',
                    'desc'     => 'Steel mills & distributors payable accounts and payment history'
                ],
            ]
        ],
        'accounts' => [
            'title' => 'Accounts & Financials (مالیات اور اکاؤنٹس)',
            'icon'  => 'fa-building-columns',
            'modules' => [
                'expenses' => [
                    'title'    => 'Operating Expenses',
                    'title_ur' => 'اخراجات',
                    'url'      => 'expenses.php',
                    'icon'     => 'fa-receipt',
                    'desc'     => 'Yard rent, electricity, labour, crane repairs, and office tea expenses'
                ],
                'accounts' => [
                    'title'    => 'Cash & Bank Accounts',
                    'title_ur' => 'کیش اور بینک اکاؤنٹس',
                    'url'      => 'accounts.php',
                    'icon'     => 'fa-building-columns',
                    'desc'     => 'Manage cash tills, bank accounts, EasyPaisa/JazzCash merchant wallets'
                ],
                'transfers' => [
                    'title'    => 'Fund Transfers',
                    'title_ur' => 'بینک ٹرانسفر',
                    'url'      => 'transfers.php',
                    'icon'     => 'fa-money-bill-transfer',
                    'desc'     => 'Transfer money between cash in hand and company bank accounts'
                ],
                'vouchers' => [
                    'title'    => 'Vouchers & Journals',
                    'title_ur' => 'واؤچرز و جرنل',
                    'url'      => 'vouchers.php',
                    'icon'     => 'fa-book',
                    'desc'     => 'Manual double-entry journal vouchers, debit/credit voucher entries'
                ],
                'closing' => [
                    'title'    => 'Daily Cash Closing',
                    'title_ur' => 'روزانہ کیش کلوزنگ',
                    'url'      => 'closing.php',
                    'icon'     => 'fa-lock',
                    'desc'     => 'Daily counter cash drawer count, shortage/excess calculation, shift freeze'
                ],
                'reports' => [
                    'title'    => 'Financial Reports',
                    'title_ur' => 'مالیاتی رپورٹس',
                    'url'      => 'reports.php',
                    'icon'     => 'fa-chart-line',
                    'desc'     => 'Profit & Loss statements, Balance Sheet, Trial Balance, Stock Movement ledger'
                ],
            ]
        ],
        'admin' => [
            'title' => 'System & Administration (سسٹم اور سیٹنگز)',
            'icon'  => 'fa-gear',
            'modules' => [
                'backup' => [
                    'title'    => 'Database Backup',
                    'title_ur' => 'ڈیٹا بیس بیک اپ',
                    'url'      => 'backup.php',
                    'icon'     => 'fa-database',
                    'desc'     => 'Create and download immediate SQL database backups'
                ],
                'settings' => [
                    'title'    => 'System & User Settings',
                    'title_ur' => 'سسٹم سیٹنگز و پرمشنز',
                    'url'      => 'settings.php',
                    'icon'     => 'fa-gear',
                    'desc'     => 'Company profile, invoice format, user PINs, and page access control'
                ],
            ]
        ]
    ];
}

/**
 * Return all valid module keys in a flat array.
 */
function getAllModuleKeys(): array {
    $keys = [];
    foreach (getAvailableModules() as $group) {
        foreach (array_keys($group['modules']) as $key) {
            $keys[] = $key;
        }
    }
    return $keys;
}

/**
 * Check if the active user has access to a specific module or page key.
 */
function hasPermission(string $moduleKey): bool {
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    
    // Super Admin / Owner has unrestricted full access
    if (strcasecmp((string)($user['role'] ?? ''), 'Owner') === 0) {
        return true;
    }
    
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) {
        $decoded = json_decode($perms, true);
        $perms = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($perms)) {
        $perms = [];
    }
    
    // Direct match
    if (in_array($moduleKey, $perms, true)) {
        return true;
    }
    
    // Compatibility alias: returns is accessible if user has returns or bills permission
    if ($moduleKey === 'returns' && in_array('bills', $perms, true)) {
        return true;
    }
    if ($moduleKey === 'bills' && in_array('returns', $perms, true)) {
        return true;
    }
    
    return false;
}

/**
 * Enforce access to a module. Terminates request with 403 if unauthorized.
 */
function requirePermission(string $moduleKey): void {
    if (!hasPermission($moduleKey)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => "Access denied. You do not have permission to access page: {$moduleKey}"
            ]);
            exit;
        }
        
        http_response_code(403);
        $pageTitle = 'Access Denied (رسائی ممنوع ہے)';
        require_once __DIR__ . '/header.php';
        ?>
        <div class="row justify-content-center my-5">
          <div class="col-12 col-md-8 col-lg-6 text-center">
            <div class="card p-5 shadow-sm border-0" style="border-radius: 12px; background: #fff;">
              <div class="mb-3">
                <i class="fa-solid fa-shield-halved text-danger" style="font-size: 56px;"></i>
              </div>
              <h3 class="fw-bold text-danger mb-2">403 · Access Denied</h3>
              <h4 class="ur text-danger mb-3" style="font-size: 22px;">آپ کو اس صفحے تک رسائی کی اجازت نہیں ہے</h4>
              <p class="text-secondary mb-4" style="font-size: 14px;">
                The Super Admin (Owner) has restricted your account from accessing this module (<code><?= htmlspecialchars($moduleKey) ?></code>). Please contact the Owner to request access.
              </p>
              <div>
                <a href="index.php" class="btn btn-zb-primary px-4 py-2">
                  <i class="fa-solid fa-arrow-left me-1"></i> Return to Dashboard (مرکزی صفحہ پر جائیں)
                </a>
              </div>
            </div>
          </div>
        </div>
        <?php
        require_once __DIR__ . '/footer.php';
        exit;
    }
}

// Convenient alias
function requirePerm(string $moduleKey): void {
    requirePermission($moduleKey);
}
