<?php
require_once 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit();
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $recordId = filter_input(INPUT_POST, 'record_id', FILTER_VALIDATE_INT);
    $value = trim($_POST['value'] ?? '');
    try {
        if (!$recordId) {
            throw new RuntimeException('Select a valid record.');
        }
        $pdo->beginTransaction();
        $pdo->exec('SET @app_user_id = ' . (int) $_SESSION['user_id']);
        if ($action === 'update_diagnosis' && $value !== '') {
            $stmt = $pdo->prepare("UPDATE DIAGNOSIS D JOIN VISIT V ON D.VisitID = V.VisitID JOIN PATIENT P ON V.PatientID = P.UserID SET D.DiagnosisText = ? WHERE D.DiagnosisID = ? AND P.AssignedDoctorID = ?");
            $stmt->execute([$value, $recordId, $_SESSION['user_id']]);
        } elseif ($action === 'delete_diagnosis') {
            $stmt = $pdo->prepare("DELETE D FROM DIAGNOSIS D JOIN VISIT V ON D.VisitID = V.VisitID JOIN PATIENT P ON V.PatientID = P.UserID WHERE D.DiagnosisID = ? AND P.AssignedDoctorID = ?");
            $stmt->execute([$recordId, $_SESSION['user_id']]);
        } elseif ($action === 'update_prescription' && $value !== '') {
            $stmt = $pdo->prepare("UPDATE PRESCRIPTION PR JOIN VISIT V ON PR.VisitID = V.VisitID JOIN PATIENT P ON V.PatientID = P.UserID SET PR.PrescribedAt = ? WHERE PR.PrescriptionID = ? AND P.AssignedDoctorID = ?");
            $stmt->execute([$value, $recordId, $_SESSION['user_id']]);
        } elseif ($action === 'delete_prescription') {
            $stmt = $pdo->prepare("DELETE PR FROM PRESCRIPTION PR JOIN VISIT V ON PR.VisitID = V.VisitID JOIN PATIENT P ON V.PatientID = P.UserID WHERE PR.PrescriptionID = ? AND P.AssignedDoctorID = ?");
            $stmt->execute([$recordId, $_SESSION['user_id']]);
        } else {
            throw new RuntimeException('Provide a replacement value for updates.');
        }
        if ($stmt->rowCount() < 1) {
            throw new RuntimeException('Record not found or outside your patient scope.');
        }
        $pdo->commit();
        $message = 'Record updated and audit log entry created successfully.';
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    }
}

$diagnosisStmt = $pdo->prepare("SELECT D.DiagnosisID, D.DiagnosisText, U.Name AS PatientName FROM DIAGNOSIS D JOIN VISIT V ON D.VisitID = V.VisitID JOIN PATIENT P ON V.PatientID = P.UserID JOIN `USER` U ON V.PatientID = U.UserID WHERE P.AssignedDoctorID = ? ORDER BY D.DiagnosisID DESC");
$diagnosisStmt->execute([$_SESSION['user_id']]);
$diagnoses = $diagnosisStmt->fetchAll();

$prescriptionStmt = $pdo->prepare("SELECT PR.PrescriptionID, PR.PrescribedAt, U.Name AS PatientName FROM PRESCRIPTION PR JOIN VISIT V ON PR.VisitID = V.VisitID JOIN PATIENT P ON V.PatientID = P.UserID JOIN `USER` U ON V.PatientID = U.UserID WHERE P.AssignedDoctorID = ? ORDER BY PR.PrescriptionID DESC");
$prescriptionStmt->execute([$_SESSION['user_id']]);
$prescriptions = $prescriptionStmt->fetchAll();

$auditStmt = $pdo->prepare("
    SELECT AL.AuditID, AL.ActionType, AL.TableName, AL.RecordID,
           AL.OldData, AL.NewData, AL.Timestamp,
           U.Name AS ActorName
    FROM AUDIT_LOG AL
    LEFT JOIN `USER` U ON U.UserID = AL.UserID
    ORDER BY AL.Timestamp DESC LIMIT 20
");
$auditStmt->execute();
$auditEntries = $auditStmt->fetchAll();

function formatAuditPayload(?string $rawJson): string {
    if ($rawJson === null || trim($rawJson) === '') {
        return '<span style="color:var(--muted);font-style:italic;">—</span>';
    }
    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        return '<span class="json-preview" title="' . htmlspecialchars($rawJson) . '">' . htmlspecialchars($rawJson) . '</span>';
    }

    $badges = [];
    foreach ($decoded as $key => $val) {
        if ($val === null) continue;
        if ($key === 'VisitID') {
            $badges[] = '<span class="audit-data-chip chip-visit" title="Associated Visit ID: ' . htmlspecialchars((string)$val) . '"><span class="chip-lbl">Visit</span>#' . htmlspecialchars((string)$val) . '</span>';
        } elseif ($key === 'PrescribedAt') {
            $ts = strtotime($val);
            $formatted = $ts ? date('M j, Y g:i A', $ts) : htmlspecialchars($val);
            $badges[] = '<span class="audit-data-chip chip-date" title="Prescription Timestamp"><span class="chip-lbl">Date</span>' . htmlspecialchars($formatted) . '</span>';
        } elseif ($key === 'DiagnosisText') {
            $badges[] = '<span class="audit-data-chip chip-diag" title="Diagnosis description"><span class="chip-lbl">Diagnosis</span>' . htmlspecialchars((string)$val) . '</span>';
        } else {
            $badges[] = '<span class="audit-data-chip"><span class="chip-lbl">' . htmlspecialchars($key) . '</span>' . htmlspecialchars((string)$val) . '</span>';
        }
    }

    if (empty($badges)) {
        return '<span style="color:var(--muted);font-style:italic;">—</span>';
    }

    return '<div class="audit-data-row" title="' . htmlspecialchars($rawJson) . '">' . implode('', $badges) . '</div>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Clinical Records &amp; Audit Log — PreMed</title>
    <meta name="description" content="Correct or remove diagnoses and prescriptions. Every change is captured in the audit log.">
    <link rel="stylesheet" href="style.css">
    <style>
        .audit-data-row {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
            align-items: center;
            max-width: 320px;
        }
        .audit-data-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--line);
            color: var(--ink);
            white-space: normal;
            word-break: break-word;
            line-height: 1.35;
        }
        .audit-data-chip .chip-lbl {
            color: var(--muted);
            font-size: 9px;
            text-transform: uppercase;
            letter-spacing: .05em;
            font-weight: 700;
            padding-right: 2px;
            border-right: 1px solid rgba(255,255,255,0.1);
        }
        .chip-visit {
            background: rgba(15,200,228,.1);
            border-color: rgba(15,200,228,.28);
            color: #7de8f8;
        }
        .chip-date {
            background: rgba(155,124,255,.1);
            border-color: rgba(155,124,255,.28);
            color: #d2c5ff;
        }
        .chip-diag {
            background: rgba(34,212,158,.1);
            border-color: rgba(34,212,158,.28);
            color: #7df1c5;
        }
    </style>
</head>
<body>
<?php include 'nav.php'; ?>
<div class="container" style="max-width:980px;margin:clamp(10px,4vw,36px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Audited Changes &amp; Compliance</p>
            <h2>Manage Clinical Records</h2>
            <p class="page-subtitle">Every update and delete automatically fires a SQL trigger that logs old vs. new values into the <code style="color:var(--teal)">AUDIT_LOG</code> table.</p>
        </div>
        <span class="role-pill role-doctor">Audit Trigger Active</span>
    </div>

    <?php if ($message): ?><p class="notice success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error):   ?><p class="notice error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <!-- Active Diagnoses Section -->
    <h3 style="margin-top:24px;">Active Diagnoses</h3>
    <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Edit diagnosis text and click Update, or click Delete to remove. SQL triggers log all modifications.</p>
    <?php if ($diagnoses): ?>
        <?php foreach ($diagnoses as $diagnosis): ?>
            <form method="POST" class="record-row" style="margin-bottom:8px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="record_id" value="<?= (int)$diagnosis['DiagnosisID'] ?>">
                <span style="font-weight:600;color:#e8f4fb;min-width:140px;"><?= htmlspecialchars($diagnosis['PatientName']) ?></span>
                <input type="text" name="value" value="<?= htmlspecialchars($diagnosis['DiagnosisText']) ?>" required style="margin:0;">
                <button name="action" value="update_diagnosis" type="submit" class="btn btn-sm btn-auto"
                        data-confirm-title="Confirm Diagnosis Update"
                        data-confirm-message="Are you sure you want to update this diagnosis text? This change will be captured in the AUDIT_LOG."
                        data-confirm-btn="Update Diagnosis"
                        data-confirm-type="primary">Update</button>
                <button name="action" value="delete_diagnosis" type="submit" class="btn btn-sm btn-auto btn-danger"
                        data-confirm-title="Delete Diagnosis Record?"
                        data-confirm-message="Are you sure you want to permanently delete this diagnosis? This deletion will be logged in the AUDIT_LOG."
                        data-confirm-btn="Delete Diagnosis"
                        data-confirm-type="danger">Delete</button>
            </form>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state compact"><strong>No diagnoses found</strong><span>No active diagnoses for your assigned patients.</span></div>
    <?php endif; ?>

    <!-- Active Prescriptions Section -->
    <h3 style="margin-top:30px;">Active Prescriptions</h3>
    <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Modify the prescription timestamp to correct dates, or delete records. Both operations write to the audit trail.</p>
    <?php if ($prescriptions): ?>
        <?php foreach ($prescriptions as $prescription): ?>
            <form method="POST" class="record-row" style="margin-bottom:8px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="record_id" value="<?= (int)$prescription['PrescriptionID'] ?>">
                <span style="font-weight:600;color:#e8f4fb;min-width:140px;"><?= htmlspecialchars($prescription['PatientName']) ?></span>
                <input type="datetime-local" name="value" value="<?= date('Y-m-d\TH:i', strtotime($prescription['PrescribedAt'])) ?>" required style="margin:0;">
                <button name="action" value="update_prescription" type="submit" class="btn btn-sm btn-auto"
                        data-confirm-title="Confirm Prescription Update"
                        data-confirm-message="Are you sure you want to update this prescription date? This update will be logged in the AUDIT_LOG."
                        data-confirm-btn="Update Prescription"
                        data-confirm-type="primary">Update</button>
                <button name="action" value="delete_prescription" type="submit" class="btn btn-sm btn-auto btn-danger"
                        data-confirm-title="Delete Prescription Record?"
                        data-confirm-message="Are you sure you want to permanently delete this prescription? This action will be audited under compliance rules."
                        data-confirm-btn="Delete Prescription"
                        data-confirm-type="danger">Delete</button>
            </form>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty-state compact"><strong>No prescriptions found</strong><span>No active prescriptions for your assigned patients.</span></div>
    <?php endif; ?>

    <!-- Audit Log Viewer Table -->
    <div class="audit-section" style="margin-top:36px;padding-top:24px;border-top:1px solid var(--line);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <div>
                <p class="eyebrow" style="margin:0 0 2px;">System Trigger Logs</p>
                <h3 style="margin:0;">AUDIT_LOG History</h3>
            </div>
            <span class="role-pill role-doctor"><?= count($auditEntries) ?> entries</span>
        </div>
        <p style="color:var(--muted);font-size:13px;margin-top:0;">Timestamped records populated automatically by MySQL triggers on every diagnosis/prescription change.</p>

        <?php if ($auditEntries): ?>
            <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Action</th>
                        <th>Table</th>
                        <th>Record ID</th>
                        <th>Old Data</th>
                        <th>New Data</th>
                        <th>Timestamp</th>
                        <th>Actor</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($auditEntries as $entry): ?>
                <tr>
                    <td style="color:var(--muted);font-size:12px;"><?= (int)$entry['AuditID'] ?></td>
                    <td>
                        <span class="audit-action <?= $entry['ActionType'] === 'DELETE' ? 'audit-delete' : 'audit-update' ?>">
                            <?= htmlspecialchars($entry['ActionType']) ?>
                        </span>
                    </td>
                    <td style="font-size:13px;"><strong><?= htmlspecialchars($entry['TableName']) ?></strong></td>
                    <td style="font-size:13px;"><?= htmlspecialchars($entry['RecordID']) ?></td>
                    <td><?= formatAuditPayload($entry['OldData']) ?></td>
                    <td><?= formatAuditPayload($entry['NewData']) ?></td>
                    <td style="white-space:nowrap;font-size:12px;color:var(--muted);"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($entry['Timestamp']))) ?></td>
                    <td style="font-size:13px;"><?= htmlspecialchars($entry['ActorName'] ?? 'System') ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php else: ?>
            <div class="empty-state compact">
                <strong>No audit entries yet</strong>
                <span>Update or delete a diagnosis or prescription above to trigger your first audit log entry.</span>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top:24px;">
        <a class="text-link" href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>
<?php include 'footer_nav.php'; ?>
</body>
</html>
