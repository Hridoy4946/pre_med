<?php
require_once __DIR__ . '/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Access denied.');
}

$docId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$docId) {
    http_response_code(400);
    exit('Invalid document ID.');
}

// Fetch document record using procedural MySQLi
$doc = db_fetch_one($conn, "SELECT * FROM PATIENT_DOCUMENT WHERE DocumentID = ?", [$docId]);

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$userId = (int) $_SESSION['user_id'];
$role   = $_SESSION['role'];
$patientId = (int) $doc['PatientID'];

// Access control:
// - Patient: can only download their own files
// - Doctor: can only download files belonging to their assigned patients
// - Guardian: can view their linked patient's documents (read-only is already enforced here)
// - Staff: no access to patient documents
$allowed = false;

if ($role === 'Patient' && $userId === $patientId) {
    $allowed = true;
} elseif ($role === 'Doctor') {
    $check = db_fetch_one($conn, "SELECT 1 FROM PATIENT WHERE UserID = ? AND AssignedDoctorID = ?", [$patientId, $userId]);
    $allowed = (bool) $check;
} elseif ($role === 'Guardian') {
    $check = db_fetch_one($conn, "SELECT 1 FROM GUARDIAN WHERE PatientID = ? AND GuardianUserID = ?", [$patientId, $userId]);
    $allowed = (bool) $check;
}

if (!$allowed) {
    http_response_code(403);
    exit('You do not have permission to access this document.');
}

// Build safe path
$uploadDir = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR;
$storedName = basename($doc['StoredName']); // strip any directory traversal
$filePath   = $uploadDir . $storedName;

if (!file_exists($filePath) || !is_file($filePath)) {
    http_response_code(404);
    exit('File not found on server.');
}

// Stream the file
$mimeType    = $doc['MimeType'];
$displayName = $doc['FileName'];
$isView      = isset($_GET['view']) && $_GET['view'] === '1';
$disposition = $isView ? 'inline' : 'attachment';

header('Content-Type: ' . $mimeType);
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($displayName) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
readfile($filePath);
exit();
