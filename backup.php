<?php
// backup.php - Database Backup & Recovery Management
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/csrf.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/audit.php';

requireAuth();
requirePerm('backup');

$pdo = getPDO();
$pageTitle = 'Database Backup (ڈیٹابیس بیک اپ)';
$errors = [];
$backupDir = __DIR__ . '/storage/backups';

if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

// Handle Download
if (!empty($_GET['download'])) {
    $filename = basename($_GET['download']);
    $filepath = $backupDir . '/' . $filename;
    if (file_exists($filepath) && str_ends_with($filename, '.sql')) {
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($filepath));
        readfile($filepath);
        exit;
    } else {
        setFlash('danger', 'Backup file not found.');
        header('Location: backup.php');
        exit;
    }
}

// Handle Create Backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_backup') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        try {
            $tables = [];
            $stT = $pdo->query("SHOW TABLES");
            while ($row = $stT->fetch(PDO::FETCH_NUM)) {
                $tables[] = $row[0];
            }

            $sqlDump = "-- ==========================================================\n";
            $sqlDump .= "-- ZAFAR BROTHERS IRON MERCHANTS - DATABASE BACKUP\n";
            $sqlDump .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
            $sqlDump .= "-- MySQL Host: " . DB_HOST . " | Database: " . DB_NAME . "\n";
            $sqlDump .= "-- ==========================================================\n\n";
            $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\nSTART TRANSACTION;\n\n";

            foreach ($tables as $t) {
                // Table structure
                $stCreate = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
                $sqlDump .= "-- --------------------------------------------------------\n";
                $sqlDump .= "-- Table structure for `$t`\n";
                $sqlDump .= "-- --------------------------------------------------------\n";
                $sqlDump .= "DROP TABLE IF EXISTS `$t`;\n";
                $sqlDump .= $stCreate[1] . ";\n\n";

                // Table data
                $stData = $pdo->query("SELECT * FROM `$t`");
                $rows = $stData->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    $sqlDump .= "-- Dumping data for table `$t`\n";
                    foreach ($rows as $r) {
                        $cols = array_map(fn($c) => "`" . str_replace("`", "``", $c) . "`", array_keys($r));
                        $vals = array_map(function($v) use ($pdo) {
                            if ($v === null) return "NULL";
                            return $pdo->quote((string)$v);
                        }, array_values($r));

                        $sqlDump .= "INSERT INTO `$t` (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
                    }
                    $sqlDump .= "\n";
                }
            }

            $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\nCOMMIT;\n";

            $backupFilename = 'zafar_pos_backup_' . date('Y_m_d_His') . '.sql';
            $backupFilepath = $backupDir . '/' . $backupFilename;
            file_put_contents($backupFilepath, $sqlDump);

            logAudit('create', 'backups', 0, "Created full database backup: $backupFilename");
            setFlash('success', "Database backup created successfully: $backupFilename (" . round(strlen($sqlDump) / 1024, 1) . " KB)");
            header('Location: backup.php');
            exit;
        } catch (Exception $e) {
            $errors[] = 'Backup failed: ' . $e->getMessage();
        }
    }
}

// Handle Database Reset / Empty
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_database') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } elseif ((getCurrentUser()['role'] ?? '') !== 'Owner') {
        $errors[] = 'Only the Owner can reset or clear the database.';
    } else {
        $confirmPin = trim((string)($_POST['confirm_pin'] ?? ''));
        $resetMode = trim((string)($_POST['reset_mode'] ?? 'fresh_start'));

        $owner = $pdo->query("SELECT pin FROM users WHERE role = 'Owner' LIMIT 1")->fetch();
        if (!$owner || $owner['pin'] !== $confirmPin) {
            $errors[] = 'Incorrect Owner PIN. Database reset aborted.';
        } else {
            try {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

                $transactionTables = [
                    'sale_items', 'sale_charges', 'sales',
                    'purchase_items', 'purchase_charges', 'purchases',
                    'return_items', 'returns',
                    'stock_movements', 'stock_adjustments',
                    'vouchers', 'journal_entry_lines', 'journal_entries',
                    'daily_closings', 'inter_account_transfers',
                    'expenses', 'audit_logs'
                ];
                foreach ($transactionTables as $tbl) {
                    $pdo->exec("TRUNCATE TABLE `$tbl`");
                }

                $pdo->exec("UPDATE financial_accounts SET current_balance = 0.00, opening_balance = 0.00");

                if ($resetMode === 'fresh_start') {
                    $pdo->exec("TRUNCATE TABLE `items`");
                    $pdo->exec("DELETE FROM `parties` WHERE id > 1");
                    $pdo->exec("UPDATE `parties` SET current_balance = 0.00, opening_balance = 0.00, is_walkin = 1 WHERE id = 1");
                    $pdo->exec("ALTER TABLE `items` AUTO_INCREMENT = 1");
                    $pdo->exec("ALTER TABLE `parties` AUTO_INCREMENT = 2");
                } else {
                    $pdo->exec("UPDATE `items` SET stock_qty = 0.00");
                    $pdo->exec("UPDATE `parties` SET current_balance = 0.00, opening_balance = 0.00");
                }

                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

                logAudit('reset_database', 'system', 0, "Database reset performed ($resetMode)");
                setFlash('success', 'Database successfully cleared and emptied! Ready for production usage.');
                header('Location: backup.php');
                exit;
            } catch (Throwable $e) {
                $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
                $errors[] = 'Reset failed: ' . $e->getMessage();
            }
        }
    }
}

// Scan backups directory
$backupFiles = [];
$scanned = glob($backupDir . '/*.sql') ?: [];
foreach ($scanned as $f) {
    $backupFiles[] = [
        'filename' => basename($f),
        'size'     => filesize($f),
        'time'     => filemtime($f)
    ];
}
usort($backupFiles, fn($a, $b) => $b['time'] <=> $a['time']);

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Database Backup & Recovery (ڈیٹابیس بیک اپ)</h4>
        <span class="text-muted" style="font-size: 13px;">Full SQL schema and ledger transactional data export for offline disaster recovery</span>
      </div>
      <div>
        <form method="POST" action="backup.php" class="d-inline">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="create_backup">
          <button type="submit" class="btn btn-zb-primary" onclick="return confirm('Generate a complete new database backup now?');">
            <i class="fa-solid fa-cloud-arrow-down me-1"></i> Create Backup Now (نیا بیک اپ لیں)
          </button>
        </form>
      </div>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="alert alert-danger">
        <ul class="mb-0">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- Information Banner -->
    <div class="card mb-4" style="border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 10px;">
      <div class="card-body p-4">
        <div class="d-flex align-items-start gap-3">
          <i class="fa-solid fa-shield-halved text-primary fs-3"></i>
          <div>
            <h6 class="fw-bold mb-1 text-primary">Data Protection & Automated Local Security</h6>
            <p class="mb-0 text-muted" style="font-size: 13.5px;">
              All backups contain the full MySQL structure, Chart of Accounts, Customers, Suppliers, Invoices, Purchases, and double-entry General Ledger journals. Backups are stored in <code>storage/backups/</code> with server-level access restriction.
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- Backups List Table -->
    <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">Available Database Backup Archives</h6>
        <span class="badge bg-light text-dark border"><?= count($backupFiles) ?> files</span>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Backup File Name</th>
              <th style="padding: 12px 14px;">Creation Date & Time</th>
              <th class="text-end" style="padding: 12px 14px;">File Size</th>
              <th class="text-center" style="padding: 12px 14px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($backupFiles)): ?>
              <tr>
                <td colspan="4" class="text-center py-4 text-muted">No backups found. Click "Create Backup Now" above to generate your first snapshot.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($backupFiles as $bf): ?>
                <tr>
                  <td style="padding: 12px 14px; font-weight: 600; color: var(--nv);">
                    <i class="fa-solid fa-file-code me-2 text-primary"></i>
                    <?= htmlspecialchars($bf['filename']) ?>
                  </td>
                  <td style="padding: 12px 14px;"><?= date('Y-m-d H:i:s', $bf['time']) ?></td>
                  <td class="text-end" style="padding: 12px 14px; font-weight: 600;">
                    <?= round($bf['size'] / 1024, 1) ?> KB
                  </td>
                  <td class="text-center" style="padding: 12px 14px;">
                    <a href="backup.php?download=<?= urlencode($bf['filename']) ?>" class="btn btn-sm btn-outline-success">
                      <i class="fa-solid fa-download me-1"></i> Download SQL File
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Database Reset / Fresh Start Section (Owner Only) -->
    <?php if ((getCurrentUser()['role'] ?? '') === 'Owner'): ?>
    <div class="card mt-4" style="border: 1px solid #fecaca; background: #fff; border-radius: 10px; overflow: hidden;">
      <div class="card-header py-3" style="background: #fef2f2; border-bottom: 1px solid #fecaca;">
        <div class="d-flex align-items-center gap-2">
          <i class="fa-solid fa-triangle-exclamation text-danger fs-5"></i>
          <div>
            <h6 class="mb-0 fw-bold text-danger">Database Reset & Clean Start (ڈیٹابیس خالی / نیا آغاز کریں)</h6>
            <small class="text-muted">Use this tool to permanently wipe demo data, test sales/purchases, or reset to a completely clean slate.</small>
          </div>
        </div>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="backup.php" onsubmit="return confirm('WARNING: Are you absolutely sure? This will permanently delete transactional data and cannot be undone!');">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="reset_database">

          <div class="row g-3">
            <div class="col-12 col-md-7">
              <label class="form-label fw-bold">Select Reset Mode (ری سیٹ کا طریقہ منتخب کریں):</label>
              <div class="form-check p-3 mb-2 rounded border" style="background: #fafafa;">
                <input class="form-check-input ms-0 me-2" type="radio" name="reset_mode" id="resetFresh" value="fresh_start" checked>
                <label class="form-check-label fw-semibold" for="resetFresh">
                  <strong>Complete Fresh Start (مکمل خالی ڈیٹابیس)</strong>
                  <div class="text-muted small mt-1">
                    Wipes all demo/dummy items, custom customers, suppliers, and all transactions (sales, purchases, vouchers, ledger). Keeps Chart of Accounts, company settings, user accounts, and default Walk-in Customer.
                  </div>
                </label>
              </div>

              <div class="form-check p-3 rounded border" style="background: #fafafa;">
                <input class="form-check-input ms-0 me-2" type="radio" name="reset_mode" id="resetTrans" value="clean_transactions">
                <label class="form-check-label fw-semibold" for="resetTrans">
                  <strong>Wipe Transactions Only (صرف لین دین صاف کریں)</strong>
                  <div class="text-muted small mt-1">
                    Clears sales, purchases, vouchers, and stock movements. Keeps items and customers but resets stock quantities and balances to zero.
                  </div>
                </label>
              </div>
            </div>

            <div class="col-12 col-md-5 d-flex flex-column justify-content-between">
              <div>
                <label class="form-label fw-bold text-danger">Enter Owner PIN to Confirm (تصدیقی پن کوڈ):</label>
                <input type="password" name="confirm_pin" class="form-control form-control-lg text-center fw-bold" placeholder="••••" required autocomplete="off" style="letter-spacing: 4px;">
                <small class="text-muted d-block mt-2">
                  <i class="fa-solid fa-lock me-1"></i> Required to protect against accidental deletion.
                </small>
              </div>

              <div class="mt-3">
                <button type="submit" class="btn btn-danger w-100 py-2 fw-bold">
                  <i class="fa-solid fa-trash-can me-2"></i> Confirm & Reset Database Now (ڈیٹابیس خالی کریں)
                </button>
              </div>
            </div>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
