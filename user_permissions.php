<?php
// user_permissions.php - Granular Page & Module Access Control (Multi-Page)
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

$currentUser = getCurrentUser();
$isOwner = strcasecmp((string)($currentUser['role'] ?? ''), 'Owner') === 0;

if (!$isOwner) {
    setFlash('danger', 'Access restricted. Only the Super Admin (Owner) can manage user page permissions.');
    header('Location: settings.php');
    exit;
}

$pdo = getPDO();
$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);

if ($userId <= 0) {
    setFlash('danger', 'Invalid user selected.');
    header('Location: settings.php');
    exit;
}

$stUser = $pdo->prepare("SELECT id, username, name, role, permissions, is_active FROM users WHERE id = ?");
$stUser->execute([$userId]);
$targetUser = $stUser->fetch(PDO::FETCH_ASSOC);

if (!$targetUser) {
    setFlash('danger', 'User not found.');
    header('Location: settings.php');
    exit;
}

$allModuleGroups = getAvailableModules();
$validKeys = getAllModuleKeys();
$errors = [];

// Handle Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'save_permissions') {
    if (!validateCSRF($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $selectedPerms = $_POST['perms'] ?? [];
        if (!is_array($selectedPerms)) {
            $selectedPerms = [];
        }
        
        // Filter only valid system keys
        $cleanPerms = [];
        foreach ($selectedPerms as $k) {
            $k = trim((string)$k);
            if (in_array($k, $validKeys, true)) {
                $cleanPerms[] = $k;
            }
        }
        
        $jsonPerms = json_encode(array_values(array_unique($cleanPerms)), JSON_UNESCAPED_UNICODE);
        
        try {
            $upStmt = $pdo->prepare("UPDATE users SET permissions = ? WHERE id = ?");
            $upStmt->execute([$jsonPerms, $userId]);
            
            logAudit('UPDATE_PERMISSIONS', 'users', (string)$userId, "Updated allowed pages for {$targetUser['username']}: " . implode(', ', $cleanPerms));
            
            // If editing own session, refresh permissions in session
            if ($userId === (int)($currentUser['id'] ?? 0)) {
                $_SESSION['user']['permissions'] = $cleanPerms;
            }
            
            setFlash('success', "Page access permissions for {$targetUser['name']} updated successfully! (صفحات کی رسائی محفوظ ہو گئی)");
            header("Location: user_permissions.php?id={$userId}");
            exit;
        } catch (Exception $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

// Decode current user permissions
$currentPerms = $targetUser['permissions'] ?? [];
if (is_string($currentPerms)) {
    $decoded = json_decode($currentPerms, true);
    $currentPerms = is_array($decoded) ? $decoded : [];
}
if (!is_array($currentPerms)) {
    $currentPerms = [];
}

$pageTitle = "Page Access Control · {$targetUser['name']}";
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
  <div>
    <nav aria-label="breadcrumb">
      <ol class="breadcrumb mb-1" style="font-size: 13px;">
        <li class="breadcrumb-item"><a href="settings.php" class="text-decoration-none">Settings (سیٹنگز)</a></li>
        <li class="breadcrumb-item active" aria-current="page">User Page Permissions</li>
      </ol>
    </nav>
    <h3 class="mb-0 fw-bold" style="color: var(--nv);">
      <i class="fa-solid fa-user-shield me-2 text-warning"></i> User Page Access Control · صفحات کی رسائی کا کنٹرول
    </h3>
    <small class="text-secondary">Super Admin can manually grant or restrict page access for this user</small>
  </div>
  <div>
    <a href="settings.php" class="btn btn-outline-secondary">
      <i class="fa-solid fa-arrow-left me-1"></i> Back to Settings (واپس سیٹنگز)
    </a>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger shadow-sm">
    <ul class="mb-0">
      <?php foreach ($errors as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- Target User Header Info Card -->
<div class="card mb-4 shadow-sm" style="border: 1px solid var(--bd); border-radius: 12px; background: #fff;">
  <div class="card-body p-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div class="d-flex align-items-center gap-3">
        <div style="width: 58px; height: 58px; border-radius: 12px; background: linear-gradient(135deg, var(--nv2), var(--nv)); color: var(--am); display: flex; align-items: center; justify-content: center; font-size: 26px; box-shadow: 0 4px 10px rgba(0,0,0,0.15);">
          <i class="fa-solid fa-user-lock"></i>
        </div>
        <div>
          <h4 class="mb-1 fw-bold" style="color: var(--nv);"><?= htmlspecialchars($targetUser['name']) ?></h4>
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="badge bg-light text-dark border">
              <i class="fa-solid fa-at me-1 text-muted"></i><?= htmlspecialchars($targetUser['username']) ?>
            </span>
            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
              <i class="fa-solid fa-id-badge me-1"></i>Role: <?= htmlspecialchars($targetUser['role']) ?>
            </span>
            <?php if ((int)$targetUser['is_active'] === 1): ?>
              <span class="badge bg-success-subtle text-success border border-success-subtle">
                <i class="fa-solid fa-circle-check me-1"></i>Active Account
              </span>
            <?php else: ?>
              <span class="badge bg-danger-subtle text-danger border border-danger-subtle">
                <i class="fa-solid fa-circle-xmark me-1"></i>Suspended
              </span>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <div class="text-end">
        <div class="text-secondary" style="font-size: 12px;">Active Permissions Count</div>
        <div class="fs-4 fw-bold text-zb-primary">
          <span id="activePermCount"><?= count($currentPerms) ?></span> / <?= count($validKeys) ?> Pages Allowed
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Quick Preset Actions Toolbar -->
<div class="card mb-4 shadow-sm" style="border: 1px solid var(--bd); border-radius: 12px; background: #f8fafc;">
  <div class="card-body p-3">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="fw-bold text-dark" style="font-size: 13px;">
          <i class="fa-solid fa-wand-magic-sparkles me-1 text-warning"></i> Quick Presets:
        </span>
        <button type="button" class="btn btn-sm btn-outline-primary" onclick="selectAll()">
          <i class="fa-solid fa-check-double me-1"></i> Select All (تمام منتخب کریں)
        </button>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deselectAll()">
          <i class="fa-solid fa-xmark me-1"></i> Deselect All (تمام ختم کریں)
        </button>
      </div>

      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="text-muted" style="font-size: 12px;">Apply Role Template:</span>
        <button type="button" class="btn btn-sm btn-light border" onclick="applyPreset(['dash', 'pos', 'bills', 'returns', 'customers', 'closing'])">
          <i class="fa-solid fa-cash-register me-1 text-primary"></i> Cashier Standard
        </button>
        <button type="button" class="btn btn-sm btn-light border" onclick="applyPreset(['dash', 'bills', 'returns', 'purchases', 'inventory', 'customers', 'suppliers', 'expenses', 'accounts', 'transfers', 'vouchers', 'closing', 'reports'])">
          <i class="fa-solid fa-calculator me-1 text-success"></i> Accountant Standard
        </button>
        <button type="button" class="btn btn-sm btn-light border" onclick="applyPreset(['dash', 'purchases', 'inventory', 'suppliers'])">
          <i class="fa-solid fa-truck-ramp-box me-1 text-warning"></i> Purchaser Standard
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Permissions Form Grid -->
<form method="POST" action="user_permissions.php" id="permsForm">
  <?= getCSRFInput() ?>
  <input type="hidden" name="action" value="save_permissions">
  <input type="hidden" name="user_id" value="<?= $userId ?>">

  <div class="row g-4 mb-4">
    <?php foreach ($allModuleGroups as $groupKey => $group): ?>
      <div class="col-12 col-xl-6">
        <div class="card h-100 shadow-sm" style="border: 1px solid var(--bd); border-radius: 12px; overflow: hidden;">
          <div class="card-header py-3 d-flex align-items-center justify-content-between" style="background: linear-gradient(90deg, #f8fafc, #edf2f7); border-bottom: 2px solid var(--bd);">
            <div class="d-flex align-items-center gap-2">
              <i class="fa-solid <?= $group['icon'] ?> text-zb-primary"></i>
              <h6 class="mb-0 fw-bold" style="color: var(--nv);"><?= htmlspecialchars($group['title']) ?></h6>
            </div>
            <button type="button" class="btn btn-sm btn-link text-decoration-none p-0 text-secondary" style="font-size: 11.5px;" onclick="toggleGroup('<?= $groupKey ?>')">
              Toggle Group
            </button>
          </div>

          <div class="card-body p-3">
            <div class="d-flex flex-column gap-2 group-container" data-group="<?= $groupKey ?>">
              <?php foreach ($group['modules'] as $modKey => $mod): ?>
                <?php $isChecked = in_array($modKey, $currentPerms, true); ?>
                <label class="perm-card p-3 d-flex align-items-start gap-3 rounded-3 border <?= $isChecked ? 'border-primary-subtle bg-primary-subtle' : 'bg-white' ?>" style="cursor: pointer; transition: all .15s ease;" id="label_<?= $modKey ?>">
                  <div class="pt-1">
                    <input type="checkbox" name="perms[]" value="<?= $modKey ?>" class="form-check-input perm-checkbox" style="width: 20px; height: 20px; cursor: pointer;" <?= $isChecked ? 'checked' : '' ?> onchange="onPermChange(this, '<?= $modKey ?>')">
                  </div>
                  <div class="flex-grow-1">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-1">
                      <div class="d-flex align-items-center gap-2">
                        <i class="fa-solid <?= $mod['icon'] ?> text-muted" style="width: 18px;"></i>
                        <span class="fw-bold" style="color: var(--nv); font-size: 14px;"><?= htmlspecialchars($mod['title']) ?></span>
                        <span class="badge bg-secondary-subtle text-secondary" style="font-family: monospace; font-size: 11px;"><?= htmlspecialchars($mod['url']) ?></span>
                      </div>
                      <span class="ur text-muted" dir="rtl" style="font-size: 15px; font-weight: 600;"><?= htmlspecialchars($mod['title_ur']) ?></span>
                    </div>
                    <div class="text-secondary" style="font-size: 12px; line-height: 1.4;">
                      <?= htmlspecialchars($mod['desc']) ?>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Sticky / Prominent Save Bar -->
  <div class="card shadow-lg sticky-bottom mb-4" style="border: 2px solid var(--am); border-radius: 12px; background: #fff;">
    <div class="card-body p-3 d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <i class="fa-solid fa-shield-check text-success fs-5 me-2"></i>
        <span class="fw-bold text-dark">Save changes to apply access limits immediately across navigation and URLs.</span>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a href="settings.php" class="btn btn-outline-secondary px-4">
          Cancel (منسوخ)
        </a>
        <button type="submit" class="btn btn-zb-primary btn-lg px-4 shadow">
          <i class="fa-solid fa-floppy-disk me-1"></i> Save Page Access Permissions (محفوظ کریں)
        </button>
      </div>
    </div>
  </div>
</form>

<script>
function updateCount() {
  const checked = document.querySelectorAll('.perm-checkbox:checked').length;
  document.getElementById('activePermCount').innerText = checked;
}

function onPermChange(checkbox, key) {
  const card = document.getElementById('label_' + key);
  if (checkbox.checked) {
    card.classList.add('border-primary-subtle', 'bg-primary-subtle');
    card.classList.remove('bg-white');
  } else {
    card.classList.remove('border-primary-subtle', 'bg-primary-subtle');
    card.classList.add('bg-white');
  }
  updateCount();
}

function selectAll() {
  document.querySelectorAll('.perm-checkbox').forEach(cb => {
    cb.checked = true;
    onPermChange(cb, cb.value);
  });
}

function deselectAll() {
  document.querySelectorAll('.perm-checkbox').forEach(cb => {
    cb.checked = false;
    onPermChange(cb, cb.value);
  });
}

function applyPreset(allowedKeys) {
  document.querySelectorAll('.perm-checkbox').forEach(cb => {
    const shouldCheck = allowedKeys.includes(cb.value);
    cb.checked = shouldCheck;
    onPermChange(cb, cb.value);
  });
}

function toggleGroup(groupKey) {
  const container = document.querySelector(`.group-container[data-group="${groupKey}"]`);
  if (!container) return;
  const checkboxes = container.querySelectorAll('.perm-checkbox');
  const allChecked = Array.from(checkboxes).every(cb => cb.checked);
  checkboxes.forEach(cb => {
    cb.checked = !allChecked;
    onPermChange(cb, cb.value);
  });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
