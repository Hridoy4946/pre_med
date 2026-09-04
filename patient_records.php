<?php
require 'db.php';
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
            $fp = __DIR__ . '/uploads/' . basename($dDoc['StoredName']);
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
            $destPath   = __DIR__ . '/uploads/' . $storedName;
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
    <link rel="stylesheet" href="style.css">
<style>
/* ── Scrollable record table boxes ─────────────────────────── */
.record-box {
    border: 1px solid var(--line);
    border-radius: 10px;
    margin: 12px 0 24px;
    overflow: hidden;
    background: var(--surface);
}
.record-box-inner {
    max-height: 265px;   /* ~5 rows × 53px */
    overflow-y: auto;
    overflow-x: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--teal) transparent;
}
.record-box-inner::-webkit-scrollbar { width: 6px; height: 6px; }
.record-box-inner::-webkit-scrollbar-track { background: transparent; }
.record-box-inner::-webkit-scrollbar-thumb { background: var(--teal); border-radius: 3px; }
.record-box table { margin: 0; border-radius: 0; border: none; }
.record-box table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: var(--card);
    border-bottom: 1px solid var(--line);
}
/* Action buttons in documents table */
.btn-delete-doc {
    /* now uses global .btn-delete class — kept for form button reset */
    background: linear-gradient(135deg, rgba(255,95,91,.18), rgba(255,95,91,.08));
    color: var(--coral);
    border: 1px solid rgba(255,95,91,.35);
    cursor: pointer;
    white-space: nowrap;
}
.btn-delete-doc:hover { background: linear-gradient(135deg, rgba(255,95,91,.30), rgba(255,95,91,.16)); box-shadow: 0 6px 18px rgba(255,95,91,.22); filter: brightness(1); transform: translateY(-1px); }
</style>
</head>
<body>
<?php include 'nav.php'; ?>
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
                       href="download_document.php?id=<?= (int)$doc['DocumentID'] ?>">
                       &#x2193; Download
                    </a>
                    <?php
                    $viewable = in_array($doc['MimeType'], ['application/pdf','image/jpeg','image/png']);
                    if ($viewable):
                    ?>
                    <a class="btn btn-sm btn-auto btn-view"
                       href="download_document.php?id=<?= (int)$doc['DocumentID'] ?>"
                       target="_blank" rel="noopener">
                       &#x1F441; View
                    </a>
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
<?php include 'footer_nav.php'; ?>
</body>
</html>
