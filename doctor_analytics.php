<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit();
}

$medications = $pdo->query("SELECT MedicationID, MedicationName FROM MEDICATION ORDER BY MedicationName")->fetchAll();
$selectedMedication = filter_input(INPUT_GET, 'medication_id', FILTER_VALIDATE_INT);
$treatmentReport = [];
if ($selectedMedication) {
    $reportStmt = $pdo->prepare("SELECT U.Name AS PatientName, M.MedicationName, (SELECT ROUND(AVG(SeverityScore), 2) FROM SYMPTOM_LOG WHERE PatientID = V.PatientID AND LoggedAt < PR.PrescribedAt) AS BeforeAverage, (SELECT ROUND(AVG(SeverityScore), 2) FROM SYMPTOM_LOG WHERE PatientID = V.PatientID AND LoggedAt >= PR.PrescribedAt) AS AfterAverage FROM PRESCRIPTION PR JOIN PRESCRIPTION_ITEM PI ON PR.PrescriptionID = PI.PrescriptionID JOIN MEDICATION M ON PI.MedicationID = M.MedicationID JOIN VISIT V ON PR.VisitID = V.VisitID JOIN PATIENT P ON V.PatientID = P.UserID JOIN `USER` U ON P.UserID = U.UserID WHERE PI.MedicationID = ? AND P.AssignedDoctorID = ? GROUP BY U.UserID, U.Name, M.MedicationName, V.PatientID, PR.PrescribedAt");
    $reportStmt->execute([$selectedMedication, $_SESSION['user_id']]);
    $treatmentReport = $reportStmt->fetchAll();
}

$patientsStmt = $pdo->prepare("
    SELECT 
        P.UserID AS PatientUserID,
        U.Name AS PatientName,
        P.PatientCode,
        P.BloodGroup,
        P.DateOfBirth,
        P.RiskLevel,
        P.ProfileStatus,
        COUNT(DISTINCT S.LogID) AS TotalLogs,
        ROUND(AVG(S.SeverityScore), 1) AS AvgSeverity,
        (SELECT COUNT(*) FROM PATIENT_DOCUMENT PD WHERE PD.PatientID = P.UserID) AS DocCount,
        CASE
            WHEN P.DateOfBirth IS NOT NULL 
                 AND TIMESTAMPDIFF(YEAR, P.DateOfBirth, CURDATE()) >= 60
                 AND (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = P.UserID AND SeverityScore > 8) >= 2 THEN 'High'
            WHEN (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = P.UserID AND SeverityScore >= 7) >= 2 THEN 'Medium'
            ELSE 'Low'
        END AS CalculatedRisk
    FROM PATIENT P
    JOIN `USER` U ON P.UserID = U.UserID
    LEFT JOIN SYMPTOM_LOG S ON S.PatientID = P.UserID
    WHERE P.AssignedDoctorID = ?
    GROUP BY P.UserID, U.Name, P.PatientCode, P.BloodGroup, P.DateOfBirth, P.RiskLevel, P.ProfileStatus
    ORDER BY TotalLogs DESC
");
$patientsStmt->execute([$_SESSION['user_id']]);
$patientsReport = $patientsStmt->fetchAll();

$followupStmt = $pdo->prepare("SELECT U.Name AS PatientName, MAX(S.SeverityScore) AS PeakSeverity, MAX(S.LoggedAt) AS LastSevereLog FROM PATIENT P JOIN `USER` U ON P.UserID = U.UserID JOIN SYMPTOM_LOG S ON S.PatientID = P.UserID WHERE P.AssignedDoctorID = ? AND S.SeverityScore > 8 AND S.LoggedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND NOT EXISTS (SELECT 1 FROM APPOINTMENT A WHERE A.PatientID = P.UserID AND A.AppointmentDate >= NOW()) GROUP BY P.UserID, U.Name");
$followupStmt->execute([$_SESSION['user_id']]);
$followups = $followupStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clinical Analytics — PreMed</title>
    <meta name="description" content="Doctor analytics: treatment effectiveness, patient risk classification, and priority follow-up list.">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include 'nav.php'; ?>
<div class="container" style="max-width:1020px;margin:clamp(10px,4vw,36px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Doctor Panel</p>
            <h2>Clinical Analytics &amp; Outcomes</h2>
            <p class="page-subtitle">Evaluate medication efficacy, monitor patient risk levels, and identify high-priority follow-up cases.</p>
        </div>
        <span class="role-pill role-doctor">Doctor</span>
    </div>

    <!-- Feature 2: Treatment Effectiveness -->
    <h3 style="margin-top:20px;">Treatment Effectiveness Analysis</h3>
    <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Compare symptom severity before vs. after medication was prescribed.</p>
    <form method="GET" class="inline-form" style="margin-bottom:16px;">
        <label for="medication_id" class="sr-only">Medication</label>
        <select name="medication_id" id="medication_id" required>
            <option value="">Select a prescribed medication…</option>
            <?php foreach ($medications as $medication): ?>
                <option value="<?= (int)$medication['MedicationID'] ?>" <?= $selectedMedication === (int)$medication['MedicationID'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($medication['MedicationName']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Compare Scores</button>
    </form>

    <?php if ($selectedMedication): ?>
        <?php if ($treatmentReport): ?>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Patient</th>
                    <th>Medication</th>
                    <th>Avg Before Rx</th>
                    <th>Avg After Rx</th>
                    <th>Score Change</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($treatmentReport as $row):
                $before = $row['BeforeAverage'];
                $after  = $row['AfterAverage'];
                $delta  = ($before !== null && $after !== null) ? round($after - $before, 2) : null;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($row['PatientName']) ?></strong></td>
                <td><?= htmlspecialchars($row['MedicationName']) ?></td>
                <td><?= $before !== null ? htmlspecialchars($before) . ' / 10' : '<span style="color:var(--muted)">No prior logs</span>' ?></td>
                <td><?= $after  !== null ? htmlspecialchars($after)  . ' / 10' : '<span style="color:var(--muted)">No post logs</span>' ?></td>
                <td>
                    <?php if ($delta !== null): ?>
                        <span class="severity-badge <?= $delta < 0 ? 'severity-low' : ($delta > 0 ? 'severity-high' : 'severity-mid') ?>">
                            <?= ($delta > 0 ? '+' : '') . $delta ?> pts <?= $delta < 0 ? '(Improved)' : ($delta > 0 ? '(Worsened)' : '(Unchanged)') ?>
                        </span>
                    <?php else: ?>
                        <span style="color:var(--muted)">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <p class="notice success">No symptom data logged for patients on this medication yet.</p>
        <?php endif; ?>
    <?php endif; ?>
    
    <!-- Feature 4: Patient Risk Classification -->
    <h3 style="margin-top:30px;">Patient Risk Classification</h3>
    <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Derived from patient age, symptom severity spikes, and continuous check-ins.</p>
    <?php if ($patientsReport): ?>
    <div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Patient</th>
                <th>Blood Group</th>
                <th>Calculated Risk</th>
                <th>Profile Status</th>
                <th>Total Logs</th>
                <th>Average Severity</th>
                <th>Documents</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($patientsReport as $row):
            $calcRisk = $row['CalculatedRisk'];
            $riskBadgeClass = $calcRisk === 'High' ? 'severity-high' : ($calcRisk === 'Medium' ? 'severity-mid' : 'severity-low');
            $statusClass = $row['ProfileStatus'] === 'Requires Attention' ? 'status-attention' : 'status-stable';
        ?>
        <tr>
            <td><strong><?= htmlspecialchars($row['PatientName']) ?></strong><br>
                <span style="font-family:monospace;font-size:11px;color:var(--muted);"><?= htmlspecialchars($row['PatientCode'] ?? '') ?></span>
            </td>
            <td><?= htmlspecialchars($row['BloodGroup'] ?: '—') ?></td>
            <td>
                <span class="severity-badge <?= $riskBadgeClass ?>"><?= htmlspecialchars($calcRisk) ?> Risk</span>
            </td>
            <td>
                <span class="status-pill <?= $statusClass ?>" style="margin-left:0;"><?= htmlspecialchars($row['ProfileStatus']) ?></span>
            </td>
            <td><?= (int)$row['TotalLogs'] ?> check-ins</td>
            <td><?= $row['AvgSeverity'] !== null ? '<strong>' . htmlspecialchars($row['AvgSeverity']) . '</strong> / 10' : '<span style="color:var(--muted)">No logs</span>' ?></td>
            <td>
                <?php if ($row['DocCount'] > 0): ?>
                    <a class="btn btn-sm btn-auto" href="doctor_documents.php?patient_id=<?= (int)$row['PatientUserID'] ?>">
                        <?= (int)$row['DocCount'] ?> file<?= $row['DocCount'] > 1 ? 's' : '' ?>
                    </a>
                <?php else: ?>
                    <span style="color:var(--muted);font-size:13px;">None</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
        <p class="notice success">No patients currently assigned to you.</p>
    <?php endif; ?>

    <!-- Feature 3: Priority Follow-Up List -->
    <h3 style="margin-top:30px;">Priority Follow-up Alert List</h3>
    <p style="color:var(--muted);font-size:13px;margin-top:-6px;">Patients with severity &gt;8 in the last 7 days who have no upcoming appointment scheduled.</p>
    <?php if (!$followups): ?>
        <div class="empty-state compact">
            <strong style="color:var(--green)">✓ All at-risk patients have upcoming appointments scheduled.</strong>
            <span>No urgent follow-up contact required at this time.</span>
        </div>
    <?php else: ?>
        <p class="notice error">⚠ <?= count($followups) ?> patient(s) require priority follow-up contact.</p>
        <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Patient Name</th>
                    <th>Peak Severity</th>
                    <th>Last Severe Log Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($followups as $row): ?>
            <tr>
                <td><strong style="color:var(--coral)"><?= htmlspecialchars($row['PatientName']) ?></strong></td>
                <td><span class="severity-badge severity-high"><?= htmlspecialchars($row['PeakSeverity']) ?> / 10 · Critical</span></td>
                <td><?= htmlspecialchars(date('M j, Y g:i A', strtotime($row['LastSevereLog']))) ?></td>
                <td><a class="btn btn-sm btn-auto" href="clinical_records.php">Contact / Schedule</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>

    <div style="margin-top:24px;">
        <a class="text-link" href="dashboard.php">← Back to Dashboard</a>
    </div>
</div>
<?php include 'footer_nav.php'; ?>
</body>
</html>