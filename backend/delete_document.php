<?php
require_once __DIR__ . '/db.php';
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    http_response_code(403);
    exit('Access denied.');
}

require_csrf();

$docId  = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT);
$userId = (int) $_SESSION['user_id'];

if (!$docId) {
    header('Location: ../frontend/patient_records.php?del_error=1');
    exit();
}

// Verify ownership
$stmt = $pdo->prepare("SELECT StoredName FROM PATIENT_DOCUMENT WHERE DocumentID = ? AND PatientID = ?");
$stmt->execute([$docId, $userId]);
$doc = $stmt->fetch();

if (!$doc) {
    header('Location: ../frontend/patient_records.php?del_error=1');
    exit();
}

// Delete physical file
$filePath = dirname(__DIR__) . '/resources/uploads/' . basename($doc['StoredName']);
if (file_exists($filePath) && is_file($filePath)) {
    @unlink($filePath);
}

// Delete DB record
$del = $pdo->prepare("DELETE FROM PATIENT_DOCUMENT WHERE DocumentID = ? AND PatientID = ?");
$del->execute([$docId, $userId]);

header('Location: ../frontend/patient_records.php?del_ok=1');
exit();
