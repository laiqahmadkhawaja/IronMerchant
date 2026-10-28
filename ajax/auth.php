<?php
// ajax/auth.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

if ($action === 'list_users') {
    // For fast PIN login dropdown (like original UI)
    $stmt = $db->query("SELECT id, username, name, role FROM `users` WHERE is_active = 1 ORDER BY id ASC");
    $users = $stmt->fetchAll();
    jsonSuccess('Users retrieved', $users);
}

if ($action === 'login') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $pin = trim((string)($_POST['pin'] ?? ''));

    if (!$userId) {
        jsonError('Please select a user.');
    }

    $stmt = $db->prepare("SELECT * FROM `users` WHERE id = ? AND is_active = 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonError('User not found or inactive.');
    }

    // Check PIN or Password
    $isValid = false;
    if ($user['pin'] !== null && $user['pin'] !== '') {
        $isValid = ($user['pin'] === $pin || $user['pin'] === '-');
    }
    if (!$isValid && !empty($user['password_hash'])) {
        $isValid = password_verify($pin, $user['password_hash']);
    }

    if (!$isValid) {
        logAudit('LOGIN_FAILED', 'users', (string)$userId, "Invalid PIN/Password attempt for {$user['name']}");
        jsonError('Incorrect PIN or password.');
    }

    loginUser($user);
    logAudit('LOGIN_SUCCESS', 'users', (string)$userId, "User {$user['name']} logged in.");
    
    jsonSuccess('Login successful', [
        'id' => $user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'role' => $user['role'],
        'permissions' => !empty($user['permissions']) ? json_decode($user['permissions'], true) : []
    ]);
}

if ($action === 'logout') {
    $user = getCurrentUser();
    if ($user) {
        logAudit('LOGOUT', 'users', (string)$user['id'], "User logged out.");
    }
    logoutUser();
    jsonSuccess('Logged out successfully.');
}

if ($action === 'check') {
    $user = getCurrentUser();
    if ($user) {
        jsonSuccess('Authenticated', $user);
    } else {
        jsonError('Unauthenticated', null, 401);
    }
}

jsonError('Invalid action requested.');
