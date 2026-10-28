<?php
// includes/auth.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';

function getCurrentUser(): ?array {
    if (empty($_SESSION['user']['id'])) {
        return null;
    }

    // Self-healing: Ensure role & permissions are always complete and fresh from DB
    if (empty($_SESSION['user']['role']) || !isset($_SESSION['user']['permissions']) || !is_array($_SESSION['user']['permissions'])) {
        try {
            $db = getDB();
            $stmt = $db->prepare("SELECT id, username, name, role, permissions FROM users WHERE id = ? AND is_active = 1");
            $stmt->execute([(int)$_SESSION['user']['id']]);
            $u = $stmt->fetch();
            if ($u) {
                $_SESSION['user']['username'] = $u['username'];
                $_SESSION['user']['name'] = $u['name'];
                $_SESSION['user']['role'] = $u['role'];
                $perms = $u['permissions'] ?? [];
                if (is_string($perms)) {
                    $decoded = json_decode($perms, true);
                    $perms = is_array($decoded) ? $decoded : [];
                }
                $_SESSION['user']['permissions'] = is_array($perms) ? $perms : [];
            }
        } catch (\Throwable $e) {
            // Fail gracefully
        }
    }

    return $_SESSION['user'];
}

function isLoggedIn(): bool {
    return !empty($_SESSION['user']['id']);
}

function requireAuth(): void {
    if (!isLoggedIn()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
            exit;
        }
        header('Location: login.php');
        exit;
    }
    $GLOBALS['currentUser'] = getCurrentUser();
}

function loginUser(array $user): void {
    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }
    $perms = $user['permissions'] ?? [];
    if (is_string($perms)) {
        $decoded = json_decode($perms, true);
        $perms = is_array($decoded) ? $decoded : [];
    }
    $_SESSION['user'] = [
        'id' => (int)$user['id'],
        'username' => $user['username'],
        'name' => $user['name'],
        'role' => $user['role'] ?? 'Owner',
        'permissions' => is_array($perms) ? $perms : []
    ];
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            $params['secure'], $params['httponly']
        );
    }
    session_destroy();
}
