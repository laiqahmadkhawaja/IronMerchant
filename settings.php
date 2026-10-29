<?php
// settings.php - Company Profile, Invoice Preferences & Security Settings
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
requirePerm('settings');

$pdo = getPDO();
$pageTitle = 'Settings (سسٹم سیٹنگز)';
$errors = [];

// Handle Company Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token.';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'save_company_settings') {
            $settings = [
                'company_name'         => trim($_POST['company_name'] ?? 'Zafar Brothers Iron Merchants'),
                'company_urdu'         => (!empty($_POST['company_urdu']) && strpos($_POST['company_urdu'], '?') === false) ? trim($_POST['company_urdu']) : 'ظفر برادرز آئرن مرچنٹس',
                'company_tagline'      => trim($_POST['company_tagline'] ?? 'Gujranwala Steel Group of Industry'),
                'company_motto_urdu'   => (!empty($_POST['company_motto_urdu']) && strpos($_POST['company_motto_urdu'], '?') === false) ? trim($_POST['company_motto_urdu']) : 'آپ کے اعتماد کا شکریہ — معیاری سریا، ایماندار وزن، بھروسے کا نام',
                'company_address'      => trim($_POST['company_address'] ?? 'Old Chungi, Hafizabad Road, Gujranwala'),
                'company_phone'        => trim($_POST['company_phone'] ?? '0300-9641483 · 0300-7499499'),
                'company_landline'     => trim($_POST['company_landline'] ?? '055-4213194'),
                'receipt_default_size' => trim($_POST['receipt_default_size'] ?? 'thermal'),
                'low_stock_alert'      => trim($_POST['low_stock_alert'] ?? '5000')
            ];

            try {
                $stUpd = $pdo->prepare("INSERT INTO company_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                foreach ($settings as $k => $v) {
                    $stUpd->execute([$k, $v]);
                }
                logAudit('update', 'company_settings', 0, "Updated company profile settings");
                setFlash('success', 'Company profile and invoice settings saved successfully.');
                header('Location: settings.php');
                exit;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        } elseif ($action === 'update_user_pin') {
            $userId = (int)($_POST['user_id'] ?? 0);
            $newPin = trim($_POST['new_pin'] ?? '');

            if ($userId <= 0 || strlen($newPin) < 4) {
                $errors[] = 'PIN must be at least 4 digits long.';
            } else {
                try {
                    $stPin = $pdo->prepare("UPDATE users SET pin = ? WHERE id = ?");
                    $stPin->execute([$newPin, $userId]);
                    logAudit('update', 'users', $userId, "Updated user PIN");
                    setFlash('success', 'User PIN updated successfully.');
                    header('Location: settings.php');
                    exit;
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
    }
}

// Fetch current settings
$currentSettings = [];
$stSets = $pdo->query("SELECT setting_key, setting_value FROM company_settings");
while ($row = $stSets->fetch(PDO::FETCH_ASSOC)) {
    $currentSettings[$row['setting_key']] = $row['setting_value'];
}

// Fetch users
$users = $pdo->query("SELECT id, username, name, role, pin, permissions, is_active FROM users ORDER BY id ASC")->fetchAll();

require_once __DIR__ . '/includes/header.php';
?>

    <div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
      <div>
        <h4 class="mb-1" style="font-weight: 700; color: var(--nv);">Company & System Settings (سیٹنگز و پروفائل)</h4>
        <span class="text-muted" style="font-size: 13px;">Manage steel yard business identity, Urdu titles, invoice layouts, and staff access PINs</span>
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

    <!-- Company Profile Card -->
    <div class="card mb-4" style="border: 1px solid var(--bd); border-radius: 10px;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <i class="fa-solid fa-building me-2"></i> Company Information & Invoice Headers
        </h6>
      </div>
      <div class="card-body p-4">
        <form method="POST" action="settings.php">
          <?= getCSRFInput() ?>
          <input type="hidden" name="action" value="save_company_settings">

          <div class="row g-3">
            <div class="col-12 col-md-6">
              <label class="form-label">Business Name (English)</label>
              <input type="text" name="company_name" class="form-control" value="<?= htmlspecialchars($currentSettings['company_name'] ?? 'Zafar Brothers Iron Merchants') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Business Name (اردو نام)</label>
              <input type="text" name="company_urdu" class="form-control ur text-end" dir="rtl" style="font-size: 16px;" value="<?= htmlspecialchars($currentSettings['company_urdu'] ?? 'ظفر برادرز آئرن مرچنٹس') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Group / Tagline</label>
              <input type="text" name="company_tagline" class="form-control" value="<?= htmlspecialchars($currentSettings['company_tagline'] ?? 'Gujranwala Steel Group of Industry') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Invoice Footer Slogan (اردو پیغام)</label>
              <input type="text" name="company_motto_urdu" class="form-control ur text-end" dir="rtl" value="<?= htmlspecialchars($currentSettings['company_motto_urdu'] ?? 'آپ کے اعتماد کا شکریہ - معیاری سریا، درست وزن') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Yard / Mill Address</label>
              <input type="text" name="company_address" class="form-control" value="<?= htmlspecialchars($currentSettings['company_address'] ?? 'Old Chungi, Hafizabad Road, Gujranwala') ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Mobile & WhatsApp</label>
              <input type="text" name="company_phone" class="form-control" value="<?= htmlspecialchars($currentSettings['company_phone'] ?? '0300-9641483 · 0300-7499499') ?>">
            </div>

            <div class="col-12 col-md-3">
              <label class="form-label">Landline Telephone</label>
              <input type="text" name="company_landline" class="form-control" value="<?= htmlspecialchars($currentSettings['company_landline'] ?? '055-4213194') ?>">
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Default Invoice Print Format</label>
              <select name="receipt_default_size" class="form-select">
                <option value="80" <?= in_array($currentSettings['receipt_default_size'] ?? '', ['80', 'thermal'], true) ? 'selected' : '' ?>>Thermal 80mm · 3" (72mm پرنٹ ڈتھ)</option>
                <option value="58" <?= (($currentSettings['receipt_default_size'] ?? '') === '58') ? 'selected' : '' ?>>Thermal 58mm · 2" (48mm پرنٹ ڈتھ)</option>
                <option value="a4" <?= (($currentSettings['receipt_default_size'] ?? '') === 'a4') ? 'selected' : '' ?>>A4 Full Page Invoice (مکمل بل پیپر)</option>
                <option value="a5" <?= (($currentSettings['receipt_default_size'] ?? '') === 'a5') ? 'selected' : '' ?>>A5 Half Sheet Invoice (ہاف پیپر)</option>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">Global Low Stock Warning Level (kg)</label>
              <input type="number" name="low_stock_alert" class="form-control" value="<?= htmlspecialchars($currentSettings['low_stock_alert'] ?? '5000') ?>">
            </div>

            <div class="col-12 text-end mt-4">
              <button type="submit" class="btn btn-zb-primary px-4">
                <i class="fa-solid fa-save me-1"></i> Save Company Profile (محفوظ کریں)
              </button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- User PIN & Access Security Card -->
    <div class="card" style="border: 1px solid var(--bd); border-radius: 10px; overflow: hidden;">
      <div class="card-header bg-white py-3">
        <h6 class="mb-0" style="font-weight: 700; color: var(--nv);">
          <i class="fa-solid fa-users-gear me-2"></i> User Access & Quick Login PIN Management
        </h6>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size: 13.5px;">
          <thead style="background: #f8fafc; border-bottom: 2px solid var(--bd);">
            <tr>
              <th style="padding: 12px 14px;">Staff Name</th>
              <th style="padding: 12px 14px;">Username</th>
              <th style="padding: 12px 14px;">Assigned Role</th>
              <th style="padding: 12px 14px;">Current PIN</th>
              <th style="padding: 12px 14px;">Allowed Pages (رسائی کنٹرول)</th>
              <th style="padding: 12px 14px; width: 260px;">Change Quick Login PIN</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): ?>
              <?php 
                $uPerms = $u['permissions'] ?? [];
                if (is_string($uPerms)) {
                    $decoded = json_decode($uPerms, true);
                    $uPerms = is_array($decoded) ? $decoded : [];
                }
                $isSuperAdmin = strcasecmp((string)$u['role'], 'Owner') === 0;
              ?>
              <tr>
                <td style="padding: 12px 14px; font-weight: 600; color: var(--nv);">
                  <?= htmlspecialchars($u['name']) ?>
                </td>
                <td style="padding: 12px 14px;"><?= htmlspecialchars($u['username']) ?></td>
                <td style="padding: 12px 14px;">
                  <span class="badge bg-light text-dark border"><?= htmlspecialchars($u['role']) ?></span>
                </td>
                <td style="padding: 12px 14px; font-family: monospace; font-size: 14px; font-weight: 700; color: #475569;">
                  <?= htmlspecialchars($u['pin']) ?>
                </td>
                <td style="padding: 12px 14px;">
                  <div class="d-flex align-items-center gap-2 flex-wrap">
                    <?php if ($isSuperAdmin): ?>
                      <span class="badge bg-warning text-dark px-2 py-1">
                        <i class="fa-solid fa-crown me-1"></i> Full Access (All Pages)
                      </span>
                    <?php else: ?>
                      <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1">
                        <?= count($uPerms) ?> Pages Allowed
                      </span>
                      <a href="user_permissions.php?id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-primary" style="font-size: 12px; padding: 3px 8px;">
                        <i class="fa-solid fa-user-gear me-1"></i> Edit Permissions (صفحات کی رسائی)
                      </a>
                    <?php endif; ?>
                  </div>
                </td>
                <td style="padding: 12px 14px;">
                  <form method="POST" action="settings.php" class="d-flex gap-2">
                    <?= getCSRFInput() ?>
                    <input type="hidden" name="action" value="update_user_pin">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <input type="password" maxlength="6" name="new_pin" class="form-control form-control-sm" required placeholder="New 4-digit PIN" style="width: 120px;">
                    <button type="submit" class="btn btn-sm btn-zb-primary">Update</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
