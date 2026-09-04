<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header('Location: login.php');
    exit();
}
$userId = $_SESSION['user_id'];

$uploadMsg   = '';
$uploadError = '';
$deleteMsg   = '';
$deleteError = '';

// Handle document delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_doc_id'])) {
    require_csrf();
    $delId = filter_input(INPUT_POST, 'delete_doc_id', FILTER_VALIDATE_INT);
    if ($delId) {
        $dStmt = $pdo->prepare("SELECT StoredName FROM PATIENT_DOCUMENT WHERE DocumentID = ? AND PatientID = ?");
        $dStmt->execute([$delId, $userId ?? 0]);
        $dDoc = $dStmt->fetch();
        if ($dDoc) {
            $fp = dirname(__DIR__) . '/resources/uploads/' . basename($dDoc['StoredName']);
            if (file_exists($fp) && is_file($fp)) @unlink($fp);
            $pdo->prepare("DELETE FROM PATIENT_DOCUMENT WHERE DocumentID = ? AND PatientID = ?")->execute([$delId, $userId ?? 0]);
            $deleteMsg = 'Document deleted successfully.';
        } else {
            $deleteError = 'Document not found or access denied.';
        }
    }
}

// Handle document upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['medical_doc'])) {
    require_csrf();
    $file      = $_FILES['medical_doc'];
    $allowedMimes = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    $maxBytes = 5 * 1024 * 1024; // 5 MB

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $uploadError = 'Upload failed. Please try again.';
    } elseif ($file['size'] > $maxBytes) {
        $uploadError = 'File too large. Maximum size is 5 MB.';
    } else {
        // Detect MIME from actual file content (not extension)
        $finfo    = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);
        if (!in_array($mimeType, $allowedMimes, true)) {
            $uploadError = 'Invalid file type. Allowed: PDF, JPG, PNG, DOCX.';
        } else {
            $origName   = basename($file['name']);
            $ext        = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $storedName = bin2hex(random_bytes(16)) . '.' . $ext;
            $destPath   = dirname(__DIR__) . '/resources/uploads/' . $storedName;
            if (move_uploaded_file($file['tmp_name'], $destPath)) {
                $ins = $pdo->prepare("INSERT INTO PATIENT_DOCUMENT (PatientID, FileName, StoredName, MimeType) VALUES (?, ?, ?, ?)");
                $ins->execute([$userId, $origName, $storedName, $mimeType]);
                $uploadMsg = '✓ "' . htmlspecialchars($origName) . '" uploaded successfully.';
            } else {
                $uploadError = 'Could not save the file. Please contact support.';
            }
        }
    }
}

// Fetch patient info including assigned doctor and PatientCode
$patInfoStmt = $pdo->prepare("
    SELECT P.PatientCode,
           DU.Name AS DoctorName, Dep.DeptName
    FROM PATIENT P
    LEFT JOIN DOCTOR D ON D.UserID = P.AssignedDoctorID
    LEFT JOIN `USER` DU ON DU.UserID = D.UserID
    LEFT JOIN DEPARTMENT Dep ON Dep.DeptID = D.DeptID
    WHERE P.UserID = ?
");
$patInfoStmt->execute([$userId]);
$patInfo = $patInfoStmt->fetch();

$appointmentsStmt = $pdo->prepare("
    SELECT A.AppointmentDate, A.DurationMinutes, A.Status,
           U.Name AS DoctorName,
           Dep.DeptName, R.RoomNumber
    FROM APPOINTMENT A
    JOIN DOCTOR D ON A.DoctorID = D.UserID
    JOIN `USER` U ON D.UserID = U.UserID
    JOIN DEPARTMENT Dep ON D.DeptID = Dep.DeptID
    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
    WHERE A.PatientID = ?
    ORDER BY A.AppointmentDate DESC
");
$appointmentsStmt->execute([$userId]);
$appointments = $appointmentsStmt->fetchAll();

$symptomsStmt = $pdo->prepare("
    SELECT SymptomName, SymptomNote, SeverityScore, LoggedAt
    FROM SYMPTOM_LOG WHERE PatientID = ? ORDER BY LoggedAt DESC LIMIT 30
");
$symptomsStmt->execute([$userId]);
$symptoms = $symptomsStmt->fetchAll();

$recordsStmt = $pdo->prepare("
    SELECT V.VisitID, V.AdmissionDate,
           GROUP_CONCAT(DISTINCT D.DiagnosisText SEPARATOR ' | ') AS Diagnoses,
           C.Notes, C.Cost AS ConsultationCost,
           L.Result AS LabResult, L.Cost AS LabCost,
           I.OutOfPocket
    FROM VISIT V
    LEFT JOIN DIAGNOSIS D ON D.VisitID = V.VisitID
    LEFT JOIN CONSULTATION C ON C.VisitID = V.VisitID
    LEFT JOIN LAB_TEST L ON L.VisitID = V.VisitID
    LEFT JOIN INVOICE I ON I.VisitID = V.VisitID
    WHERE V.PatientID = ?
    GROUP BY V.VisitID, V.AdmissionDate, C.Notes, C.Cost, L.Result, L.Cost, I.OutOfPocket
    ORDER BY V.AdmissionDate DESC
");
$recordsStmt->execute([$userId]);
$records = $recordsStmt->fetchAll();

$prescriptionsStmt = $pdo->prepare("
    SELECT PR.PrescribedAt, M.MedicationName, PI.Dosage, PI.Quantity
    FROM PRESCRIPTION PR
    JOIN PRESCRIPTION_ITEM PI ON PI.PrescriptionID = PR.PrescriptionID
    JOIN MEDICATION M ON M.MedicationID = PI.MedicationID
    JOIN VISIT V ON PR.VisitID = V.VisitID
    WHERE V.PatientID = ?
    ORDER BY PR.PrescribedAt DESC
");
$prescriptionsStmt->execute([$userId]);
$prescriptions = $prescriptionsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Health Records — PreMed</title>
    <meta name="description" content="View your appointments, symptom history, visit records, and prescriptions.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
<style>
/* ── Scrollable record table boxes ─────────────────────────── */
.record-box {
    border: 1px solid var(--line);
    border-radius: 10px;
    margin: 12px 0 24px;
    overflow-x: auto;
}
.record-box-scroll {
    max-height: 295px;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--teal) rgba(255,255,255,.05);
}
.record-box-scroll::-webkit-scrollbar { width: 6px; }
.record-box-scroll::-webkit-scrollbar-track { background: rgba(255,255,255,.03); }
.record-box-scroll::-webkit-scrollbar-thumb { background: var(--teal); border-radius: 3px; }
.record-box table { margin: 0; width: 100%; border-collapse: collapse; }
.record-box th { position: sticky; top: 0; background: #0b1f32; z-index: 2; }
.btn-delete-doc {
    background: linear-gradient(135deg, rgba(255,95,91,.15), rgba(255,95,91,.08));
    border: 1px solid rgba(255,95,91,.35);
    color: #ff7e79;
    transition: all .18s ease;
    cursor: pointer;
}
.btn-delete-doc:hover { background: linear-gradient(135deg, rgba(255,95,91,.30), rgba(255,95,91,.16)); box-shadow: 0 6px 18px rgba(255,95,91,.22); filter: brightness(1); transform: translateY(-1px); }

/* ── Document Preview Pop-up Window ───────────────────────── */
.doc-preview-modal-overlay {
    display: none !important;
    position: fixed !important;
    inset: 0 !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    z-index: 999999 !important;
    background: rgba(3, 10, 22, 0.88) !important;
    backdrop-filter: blur(12px) !important;
    -webkit-backdrop-filter: blur(12px) !important;
    align-items: center !important;
    justify-content: center !important;
    padding: clamp(10px, 2.5vw, 24px) !important;
    box-sizing: border-box !important;
}
.doc-preview-modal-overlay.active {
    display: flex !important;
}
.doc-preview-modal-dialog {
    position: relative !important;
    width: min(96vw, 1000px) !important;
    height: min(90vh, 850px) !important;
    max-height: 90vh !important;
    background: #091a2c !important;
    border: 1px solid rgba(15, 200, 228, 0.3) !important;
    border-radius: 16px !important;
    display: flex !important;
    flex-direction: column !important;
    box-shadow: 0 28px 70px rgba(0, 0, 0, 0.85), 0 0 0 1px rgba(255, 255, 255, 0.08) !important;
    overflow: hidden !important;
    box-sizing: border-box !important;
    margin: auto !important;
    animation: premedModalIn 0.22s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}
@keyframes premedModalIn {
    from { transform: scale(0.93) translateY(10px); opacity: 0; }
    to   { transform: scale(1) translateY(0); opacity: 1; }
}
.doc-preview-modal-header {
    display: flex !important;
    align-items: center !important;
    justify-content: space-between !important;
    padding: 14px 20px !important;
    background: #0d2238 !important;
    border-bottom: 1px solid var(--line) !important;
    flex-shrink: 0 !important;
    gap: 12px !important;
    box-sizing: border-box !important;
}
.doc-preview-header-info {
    display: flex !important;
    align-items: center !important;
    gap: 12px !important;
    min-width: 0 !important;
}
.doc-preview-header-icon {
    font-size: 26px !important;
    line-height: 1 !important;
    flex-shrink: 0 !important;
}
.doc-preview-header-text {
    min-width: 0 !important;
}
.doc-preview-title {
    margin: 0 !important;
    font-size: 15px !important;
    font-weight: 700 !important;
    color: #f0f7ff !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    max-width: clamp(180px, 45vw, 600px) !important;
}
.doc-preview-meta {
    font-size: 11px !important;
    color: var(--teal) !important;
    display: block !important;
    margin-top: 2px !important;
    font-weight: 600 !important;
}
.doc-preview-header-actions {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
    flex-shrink: 0 !important;
}
.doc-preview-modal-header .btn-download {
    width: auto !important;
    margin: 0 !important;
    padding: 8px 14px !important;
    font-size: 12px !important;
    font-weight: 700 !important;
    border-radius: 8px !important;
    white-space: nowrap !important;
}
button.doc-preview-close-btn,
.doc-preview-modal-dialog .doc-preview-close-btn {
    width: 34px !important;
    min-width: 34px !important;
    max-width: 34px !important;
    height: 34px !important;
    margin: 0 !important;
    padding: 0 !important;
    border-radius: 8px !important;
    background: rgba(255, 255, 255, 0.08) !important;
    border: 1px solid var(--line) !important;
    color: var(--muted) !important;
    font-size: 22px !important;
    line-height: 1 !important;
    cursor: pointer !important;
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-shadow: none !important;
    transform: none !important;
    transition: all 0.18s ease !important;
}
button.doc-preview-close-btn:hover {
    background: rgba(255, 95, 91, 0.25) !important;
    border-color: rgba(255, 95, 91, 0.5) !important;
    color: #ff7e79 !important;
    filter: brightness(1.2) !important;
}
.doc-preview-modal-body {
    flex: 1 !important;
    position: relative !important;
    background: #040c16 !important;
    overflow: hidden !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    box-sizing: border-box !important;
    height: 100% !important;
    min-height: 300px !important;
}
.doc-preview-iframe {
    width: 100% !important;
    height: 100% !important;
    border: none !important;
    background: #ffffff !important;
    box-sizing: border-box !important;
}
.doc-preview-image {
    max-width: 100% !important;
    max-height: 100% !important;
    object-fit: contain !important;
    padding: 16px !important;
    box-sizing: border-box !important;
}
.doc-preview-fallback {
    text-align: center !important;
    padding: 36px 20px !important;
    color: var(--muted) !important;
}
.doc-preview-fallback-icon {
    font-size: 44px !important;
    margin-bottom: 12px !important;
    display: block !important;
}
</style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="container" style="max-width:1000px;margin:clamp(10px,4vw,36px) auto;">

    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Patient Portal</p>
            <h2>My Health Records</h2>
        </div>
        <span class="role-pill role-patient">Patient</span>
    </div>

    <!-- Info card: PatientCode + Assigned Doctor -->
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:20px;align-items:stretch;">
        <?php if (!empty($patInfo['PatientCode'])): ?>
        <div style="display:flex;align-items:center;gap:8px;background:rgba(15,200,228,.07);border:1px solid rgba(15,200,228,.2);border-radius:8px;padding:8px 16px;">
            <span style="font-size:11px;color:var(--muted);font-weight:700;">PATIENT CODE</span>
            <span style="font-family:monospace;font-size:15px;font-weight:800;color:var(--teal);letter-spacing:.06em;"><?= htmlspecialchars($patInfo['PatientCode']) ?></span>
        </div>
        <?php endif; ?>
        <?php if (!empty($patInfo['DoctorName'])): ?>
        <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:8px;padding:8px 16px;">
            <span style="font-size:11px;color:var(--muted);font-weight:700;">ASSIGNED DOCTOR</span>
            <span style="font-weight:700;"><?= htmlspecialchars($patInfo['DoctorName']) ?></span>
            <?php if (!empty($patInfo['DeptName'])): ?>
            <span style="font-size:12px;color:var(--muted);">— <?= htmlspecialchars($patInfo['DeptName']) ?></span>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div style="font-size:13px;color:var(--muted);font-style:italic;padding:8px 0;">No doctor assigned yet.</div>
        <?php endif; ?>
    </div>

    <!-- Appointments -->
    <h3>Appointments</h3>
    <?php
    $statusStyles = [
        'Scheduled' => 'background:rgba(15,200,228,.12);color:#0fc8e4;border:1px solid rgba(15,200,228,.3);',
        'Confirmed' => 'background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3);',
        'Completed' => 'background:rgba(122,153,176,.10);color:#7a99b0;border:1px solid rgba(122,153,176,.25);',
        'Cancelled' => 'background:rgba(255,95,91,.10);color:#ff5f5b;border:1px solid rgba(255,95,91,.3);',
    ];
    $statusIcons = ['Scheduled'=>'🕐','Confirmed'=>'✅','Completed'=>'✔','Cancelled'=>'✕'];
    ?>
    <?php if ($appointments): ?>
        <div class="record-box">
        <div class="record-box-inner">
        <table>
            <thead><tr>
                <th>Date &amp; Time</th><th>Doctor</th><th>Department</th><th>Room</th><th>Duration</th><th>Status</th>
            </tr></thead>
            <tbody>
            <?php foreach ($appointments as $appt):
                $st = $appt['Status'];
                $ss = $statusStyles[$st] ?? $statusStyles['Scheduled'];
                $si = $statusIcons[$st] ?? '';
            ?>
            <tr>
                <td><strong><?= htmlspecialchars(date('M j, Y', strtotime($appt['AppointmentDate']))) ?></strong><br>
                    <small style="color:var(--muted)"><?= htmlspecialchars(date('g:i A', strtotime($appt['AppointmentDate']))) ?></small></td>
                <td><?= htmlspecialchars($appt['DoctorName']) ?></td>
                <td style="color:var(--muted)"><?= htmlspecialchars($appt['DeptName']) ?></td>
                <td>Room <?= htmlspecialchars($appt['RoomNumber']) ?></td>
                <td><?= (int)$appt['DurationMinutes'] ?> min</td>
                <td>
                    <span style="display:inline-flex;align-items:center;gap:5px;padding:4px 11px;border-radius:999px;font-size:12px;font-weight:700;<?= $ss ?>">
                        <?= $si ?> <?= htmlspecialchars($st) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    <?php else: ?>
        <div class="empty-state compact">
            <strong>No appointments yet</strong>
            <span>Book your first appointment with a doctor.</span>
            <a class="btn btn-sm btn-auto" href="book_appointment.php" style="margin-top:10px;">Book appointment</a>
        </div>
    <?php endif; ?>

    <!-- Symptom History -->
    <h3>Symptom History</h3>
    <?php if ($symptoms): ?>
        <div class="record-box">
        <div class="record-box-inner">
        <table>
            <thead><tr><th>Symptom</th><th>Severity</th><th>Date</th><th>Notes</th></tr></thead>
            <tbody>
            <?php foreach ($symptoms as $symptom):
                $score = (int)$symptom['SeverityScore'];
                $badgeClass = $score >= 7 ? 'severity-high' : ($score >= 4 ? 'severity-mid' : 'severity-low');
                $badgeLabel = $score >= 7 ? 'High' : ($score >= 4 ? 'Moderate' : 'Low');
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($symptom['SymptomName']) ?></strong></td>
                <td><span class="severity-badge <?= $badgeClass ?>"><?= $score ?>/10 · <?= $badgeLabel ?></span></td>
                <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($symptom['LoggedAt']))) ?></td>
                <td style="color:var(--muted);font-size:13px;"><?= htmlspecialchars($symptom['SymptomNote'] ?? '—') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    <?php else: ?>
        <div class="empty-state compact">
            <strong>No symptom logs yet</strong>
            <span>Log your first symptom to start tracking your health.</span>
            <a class="btn btn-sm btn-auto" href="symptom_log.php" style="margin-top:10px;">Log symptoms</a>
        </div>
    <?php endif; ?>

    <!-- Visit Records -->
    <h3>Visit Records &amp; Results</h3>
    <?php if ($records): ?>
        <div class="record-box">
        <div class="record-box-inner">
        <table>
            <thead><tr><th>Admission Date</th><th>Diagnosis</th><th>Lab Result</th><th>Consult Cost</th><th>Lab Cost</th><th>Invoice</th></tr></thead>
            <tbody>
            <?php foreach ($records as $record): ?>
            <tr>
                <td><?= htmlspecialchars(date('M j, Y', strtotime($record['AdmissionDate']))) ?></td>
                <td><?= htmlspecialchars($record['Diagnoses'] ?? '—') ?></td>
                <td style="font-size:13px;color:var(--muted);max-width:200px;"><?= htmlspecialchars($record['LabResult'] ?? '—') ?></td>
                <td><?= $record['ConsultationCost'] !== null ? '$' . number_format((float)$record['ConsultationCost'], 2) : '—' ?></td>
                <td><?= $record['LabCost'] !== null ? '$' . number_format((float)$record['LabCost'], 2) : '—' ?></td>
                <td>
                    <?php if ($record['OutOfPocket'] !== null): ?>
                        <strong style="color:var(--green);">$<?= number_format((float)$record['OutOfPocket'], 2) ?></strong>
                    <?php else: ?>
                        <span class="severity-badge severity-mid">Pending</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    <?php else: ?>
        <div class="empty-state compact">
            <strong>No visit records yet</strong>
            <span>Visit records will appear here after a clinical consultation.</span>
        </div>
    <?php endif; ?>

    <!-- Prescriptions -->
    <h3>Prescriptions</h3>
    <?php if ($prescriptions): ?>
        <div class="record-box">
        <div class="record-box-inner">
        <table>
            <thead><tr><th>Prescribed</th><th>Medication</th><th>Dosage</th><th>Quantity</th></tr></thead>
            <tbody>
            <?php foreach ($prescriptions as $rx): ?>
            <tr>
                <td><?= htmlspecialchars(date('M j, Y', strtotime($rx['PrescribedAt']))) ?></td>
                <td><strong><?= htmlspecialchars($rx['MedicationName']) ?></strong></td>
                <td style="color:var(--muted)"><?= htmlspecialchars($rx['Dosage']) ?></td>
                <td><?= (int)$rx['Quantity'] ?> units</td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
    <?php else: ?>
        <div class="empty-state compact">
            <strong>No prescriptions yet</strong>
            <span>Prescriptions issued by your doctor will appear here.</span>
        </div>
    <?php endif; ?>

    <!-- Documents -->
    <h3>My Medical Documents</h3>
    <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Upload reports, scans, or referral letters for your assigned doctor to review. Max 5 MB per file — PDF, JPG, PNG, or DOCX.</p>

    <?php if ($uploadMsg):   ?><p class="notice success"><?= $uploadMsg ?></p><?php endif; ?>
    <?php if ($uploadError): ?><p class="notice error"><?= htmlspecialchars($uploadError) ?></p><?php endif; ?>
    <?php if ($deleteMsg):   ?><p class="notice success"><?= htmlspecialchars($deleteMsg) ?></p><?php endif; ?>
    <?php if ($deleteError): ?><p class="notice error"><?= htmlspecialchars($deleteError) ?></p><?php endif; ?>

    <form method="POST" enctype="multipart/form-data" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:20px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <div style="flex:1;min-width:220px;">
            <label for="medical_doc" style="display:block;margin-bottom:6px;">Choose a file</label>
            <input type="file" id="medical_doc" name="medical_doc"
                   accept=".pdf,.jpg,.jpeg,.png,.docx"
                   style="width:100%;padding:8px;background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:8px;color:var(--text);font-size:13px;"
                   required>
        </div>
        <button type="submit" style="white-space:nowrap;">Upload Document</button>
    </form>

    <?php
    $docsStmt = $pdo->prepare("SELECT DocumentID, FileName, MimeType, UploadedAt FROM PATIENT_DOCUMENT WHERE PatientID = ? ORDER BY UploadedAt DESC");
    $docsStmt->execute([$userId]);
    $myDocs = $docsStmt->fetchAll();
    $typeIcons = [
        'application/pdf'  => '📄',
        'image/jpeg'       => '🖼',
        'image/png'        => '🖼',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => '📝',
    ];
    ?>
    <?php if ($myDocs): ?>
    <div class="record-box">
    <div class="record-box-inner">
    <table>
        <thead><tr><th>Type</th><th>File Name</th><th>Uploaded</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($myDocs as $doc):
            $icon = $typeIcons[$doc['MimeType']] ?? '📎';
        ?>
        <tr>
            <td style="font-size:20px;text-align:center;"><?= $icon ?></td>
            <td><strong><?= htmlspecialchars($doc['FileName']) ?></strong></td>
            <td style="color:var(--muted);font-size:13px;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($doc['UploadedAt']))) ?></td>
            <td>
                <div class="btn-group">
                    <a class="btn btn-sm btn-auto btn-download"
                       href="../backend/download_document.php?id=<?= (int)$doc['DocumentID'] ?>">
                       &#x2193; Download
                    </a>
                    <?php
                    $viewable = in_array($doc['MimeType'], ['application/pdf','image/jpeg','image/png']);
                    if ($viewable):
                    ?>
                    <button type="button"
                       class="btn btn-sm btn-auto btn-view js-preview-doc"
                       data-doc-id="<?= (int)$doc['DocumentID'] ?>"
                       data-file-name="<?= htmlspecialchars($doc['FileName']) ?>"
                       data-mime-type="<?= htmlspecialchars($doc['MimeType']) ?>"
                       data-preview-url="../backend/download_document.php?id=<?= (int)$doc['DocumentID'] ?>&view=1"
                       data-download-url="../backend/download_document.php?id=<?= (int)$doc['DocumentID'] ?>">
                       &#x1F441; View
                    </button>
                    <?php else: ?>
                    <span class="btn btn-sm btn-auto" style="background:rgba(255,255,255,.04);color:var(--muted2);border:1px solid var(--line);cursor:default;opacity:.5;" title="Preview not available for this file type">
                       &#x1F441; View
                    </span>
                    <?php endif; ?>
                    <form method="POST" style="margin:0;padding:0;border:0;background:transparent;box-shadow:none;display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="delete_doc_id" value="<?= (int)$doc['DocumentID'] ?>">
                        <button type="submit"
                            class="btn btn-sm btn-auto btn-delete-doc"
                            onclick="return openPremedConfirm('Delete Document', 'Are you sure you want to permanently delete &quot;<?= htmlspecialchars(addslashes($doc['FileName'])) ?>&quot;? This cannot be undone.', 'danger', this.form)">
                            &#x1F5D1; Delete
                        </button>
                    </form>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    </div>
    <?php else: ?>
        <div class="empty-state compact">
            <strong>No documents uploaded yet</strong>
            <span>Upload your first file above to share it with your doctor.</span>
        </div>
    <?php endif; ?>

    <div style="margin-top:24px;"><a class="text-link" href="dashboard.php">← Back to dashboard</a></div>
</div>

<!-- Document Preview Modal Dialog -->
<div id="doc_preview_modal" class="doc-preview-modal-overlay" aria-hidden="true">
    <div class="doc-preview-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="doc_preview_title">
        <div class="doc-preview-modal-header">
            <div class="doc-preview-header-info">
                <span id="doc_preview_icon" class="doc-preview-header-icon">📄</span>
                <div class="doc-preview-header-text">
                    <h4 id="doc_preview_title" class="doc-preview-title">Document Preview</h4>
                    <span id="doc_preview_meta" class="doc-preview-meta">PDF Document</span>
                </div>
            </div>
            <div class="doc-preview-header-actions">
                <a id="doc_preview_download_btn" href="#" class="btn btn-sm btn-auto btn-download" download>
                    ↓ Download
                </a>
                <button type="button" class="doc-preview-close-btn" id="doc_preview_close_btn" aria-label="Close Preview">&times;</button>
            </div>
        </div>
        <div class="doc-preview-modal-body" id="doc_preview_body">
            <!-- Content dynamically injected: iframe, img, or fallback -->
        </div>
    </div>
</div>

<script>
(function() {
    const previewModal = document.getElementById('doc_preview_modal');
    const previewTitle = document.getElementById('doc_preview_title');
    const previewMeta  = document.getElementById('doc_preview_meta');
    const previewIcon  = document.getElementById('doc_preview_icon');
    const previewBody  = document.getElementById('doc_preview_body');
    const previewDownload = document.getElementById('doc_preview_download_btn');
    const closeBtn     = document.getElementById('doc_preview_close_btn');

    function closePreview() {
        if (!previewModal) return;
        previewModal.classList.remove('active');
        previewModal.setAttribute('aria-hidden', 'true');
        if (previewBody) previewBody.innerHTML = '';
    }

    if (closeBtn) {
        closeBtn.addEventListener('click', closePreview);
    }

    if (previewModal) {
        previewModal.addEventListener('click', function(e) {
            if (e.target === previewModal) {
                closePreview();
            }
        });
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && previewModal && previewModal.classList.contains('active')) {
            closePreview();
        }
    });

    document.querySelectorAll('.js-preview-doc').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const fileName    = this.dataset.fileName || 'Medical Document';
            const mimeType    = this.dataset.mimeType || '';
            const previewUrl  = this.dataset.previewUrl;
            const downloadUrl = this.dataset.downloadUrl;

            previewTitle.textContent = fileName;
            previewDownload.href = downloadUrl;
            previewDownload.setAttribute('download', fileName);

            let icon = '📄';
            let typeLabel = 'Medical Record';

            if (mimeType.includes('pdf')) {
                icon = '📄';
                typeLabel = 'PDF Document';
                previewBody.innerHTML = '<iframe class="doc-preview-iframe" src="' + previewUrl + '" title="' + fileName.replace(/"/g, '&quot;') + '"></iframe>';
            } else if (mimeType.startsWith('image/')) {
                icon = '🖼';
                typeLabel = 'Medical Image / Scan';
                previewBody.innerHTML = '<img class="doc-preview-image" src="' + previewUrl + '" alt="' + fileName.replace(/"/g, '&quot;') + '">';
            } else {
                icon = '📝';
                typeLabel = 'Document File';
                previewBody.innerHTML = '<div class="doc-preview-fallback">' +
                    '<span class="doc-preview-fallback-icon">📄</span>' +
                    '<h4 style="color:#f0f7ff;margin:0 0 8px;">Inline preview unavailable</h4>' +
                    '<p style="margin:0 0 16px;font-size:13px;color:var(--muted);">This file format cannot be rendered directly in the browser.</p>' +
                    '<a href="' + downloadUrl + '" class="btn btn-sm btn-auto btn-download" download="' + fileName.replace(/"/g, '&quot;') + '">↓ Download "' + fileName.replace(/"/g, '&quot;') + '" to open</a>' +
                    '</div>';
            }

            previewIcon.textContent = icon;
            previewMeta.textContent = typeLabel;

            previewModal.classList.add('active');
            previewModal.setAttribute('aria-hidden', 'false');
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>
