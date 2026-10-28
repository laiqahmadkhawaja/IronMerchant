<?php
// includes/audit.php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/auth.php';

function logAudit(string $action, string $entityType, string|int|null $entityId = null, mixed $details = null): void {
    try {
        $db = getDB();
        $user = getCurrentUser();
        $userId = $user['id'] ?? null;
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $detailsJson = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE);
        $entityIdStr = ($entityId !== null) ? (string)$entityId : null;

        $stmt = $db->prepare("
            INSERT INTO `audit_logs` (`user_id`, `action`, `entity_type`, `entity_id`, `details`, `ip_address`, `created_at`)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$userId, $action, $entityType, $entityIdStr, $detailsJson, $ip]);
    } catch (Exception $e) {
        // Fallback silently without breaking main transaction
        error_log("Audit log failure: " . $e->getMessage());
    }
}
