<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit();
}
$doctorId  = (int) $_SESSION['user_id'];
$patientId = filter_input(INPUT_GET, 'patient_id', FILTER_VALIDATE_INT);

if (!$patientId) {
    header('Location: doctor_analytics.php');
    exit();
}

// Verify this doctor is the assigned doctor for this patient
$checkStmt = $pdo->prepare("
    SELECT U.Name AS PatientName, P.PatientCode
    FROM PATIENT P
    JOIN `USER` U ON U.UserID = P.UserID
    WHERE P.UserID = ? AND P.AssignedDoctorID = ?
");
$checkStmt->execute([$patientId, $doctorId]);
$patient = $checkStmt->fetch();

if (!$patient) {
    http_response_code(403);
    exit('You are not the assigned doctor for this patient.');
}

$docsStmt = $pdo->prepare("
    SELECT DocumentID, FileName, MimeType, UploadedAt
    FROM PATIENT_DOCUMENT
    WHERE PatientID = ?
    ORDER BY UploadedAt DESC
");
$docsStmt->execute([$patientId]);
$documents = $docsStmt->fetchAll();

$typeIcons = [
    'application/pdf'                                                          => '📄',
    'image/jpeg'                                                               => '🖼',
    'image/png'                                                                => '🖼',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '📝',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Patient Documents — PreMed</title>
    <meta name="description" content="View medical documents uploaded by a patient for doctor review.">
    <link rel="stylesheet" href="style.css">
    <style>
        .doc-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 14px;
            margin-top: 16px;
        }
        .doc-card {
            background: rgba(255,255,255,.035);
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            transition: border-color .15s, background .15s;
        }
        .doc-card:hover { border-color: var(--teal); background: rgba(15,200,228,.05); }
        .doc-icon { font-size: 28px; line-height: 1; }
        .doc-name { font-weight: 700; font-size: 14px; color: var(--text); word-break: break-word; }
        .doc-meta { font-size: 12px; color: var(--muted); }
        .doc-download {
            display: inline-flex; align-items: center; gap: 5px;
            margin-top: auto;
            padding: 6px 14px; border-radius: 6px;
            background: rgba(15,200,228,.12); color: var(--teal);
            border: 1px solid rgba(15,200,228,.25);
            font-size: 12px; font-weight: 700;
            text-decoration: none; transition: background .15s;
        }
        .doc-download:hover { background: rgba(15,200,228,.22); }
        .code-badge {
            font-size:11px; font-family:monospace;
            background:rgba(15,200,228,.12); color:var(--teal);
            border:1px solid rgba(15,200,228,.25); border-radius:4px;
            padding:2px 7px; letter-spacing:.04em;
        }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="container" style="max-width:900px;margin:clamp(10px,4vw,36px) auto;">

    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Doctor Panel</p>
            <h2>Patient Documents</h2>
            <p class="page-subtitle">
                Files uploaded by
                <strong><?= htmlspecialchars($patient['PatientName']) ?></strong>
                <span class="code-badge" style="margin-left:6px;"><?= htmlspecialchars($patient['PatientCode']) ?></span>
                for your review.
            </p>
        </div>
        <span class="role-pill role-doctor">Doctor</span>
    </div>

    <?php if ($documents): ?>
    <div class="doc-grid">
        <?php foreach ($documents as $doc):
            $icon = $typeIcons[$doc['MimeType']] ?? '📎';
        ?>
        <div class="doc-card">
            <div class="doc-icon"><?= $icon ?></div>
            <div class="doc-name"><?= htmlspecialchars($doc['FileName']) ?></div>
            <div class="doc-meta">Uploaded <?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['UploadedAt']))) ?></div>
            <a class="doc-download" href="download_document.php?id=<?= (int)$doc['DocumentID'] ?>">
                ↓ Download
            </a>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <div class="empty-state" style="margin-top:24px;">
            <strong>No documents uploaded yet</strong>
            <span>This patient has not uploaded any medical documents for review.</span>
        </div>
    <?php endif; ?>

    <div style="margin-top:28px;">
        <a class="text-link" href="doctor_analytics.php">← Back to Clinical Analytics</a>
    </div>
</div>
<?php include 'footer_nav.php'; ?>
</body>
</html>
