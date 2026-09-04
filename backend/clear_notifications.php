<?php
/**
 * Asynchronous Notification Dismissal Handler
 * Persists cleared notification IDs to the user's session so they stay dismissed across navigation.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/notifications.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit();
}

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? 'Patient';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

if (!isset($_SESSION['cleared_notifications'])) {
    $_SESSION['cleared_notifications'] = [];
}
if (!isset($_SESSION['cleared_notifications'][$userId])) {
    $_SESSION['cleared_notifications'][$userId] = [];
}

$clearedList = &$_SESSION['cleared_notifications'][$userId];

// 1. If explicit IDs were sent
if (!empty($data['ids']) && is_array($data['ids'])) {
    foreach ($data['ids'] as $id) {
        $cleanId = trim((string)$id);
        if ($cleanId !== '' && !in_array($cleanId, $clearedList, true)) {
            $clearedList[] = $cleanId;
        }
    }
}

// 2. If 'all' was requested, fetch current active IDs and ensure all are marked cleared
if (!empty($data['all']) && isset($pdo)) {
    try {
        $activeNotifs = get_user_notifications($pdo, $userId, $role);
        foreach ($activeNotifs as $an) {
            $anId = $an['id'] ?? '';
            if ($anId !== '' && !in_array($anId, $clearedList, true)) {
                $clearedList[] = $anId;
            }
        }
    } catch (Throwable $e) {
        // Continue
    }
}

echo json_encode([
    'success' => true,
    'cleared_count' => count($clearedList)
]);
exit();
