<?php
require 'db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Guardian') {
    header('Location: login.php');
    exit();
}

$guardianStmt = $pdo->prepare("SELECT G.PatientID, G.GuardianName, G.Phone, U.Name AS PatientName, P.ProfileStatus, P.RiskLevel, P.BloodGroup, P.Gender, P.DateOfBirth, DUser.Name AS DoctorName FROM GUARDIAN G JOIN PATIENT P ON P.UserID = G.PatientID JOIN `USER` U ON U.UserID = P.UserID LEFT JOIN `USER` DUser ON DUser.UserID = P.AssignedDoctorID WHERE G.GuardianUserID = ? LIMIT 1");
$guardianStmt->execute([$_SESSION['user_id']]);
$profile = $guardianStmt->fetch();

if (!$profile) {
    http_response_code(404);
}

$appointments = [];
$symptoms = [];
if ($profile) {
    $appointmentsStmt = $pdo->prepare("SELECT A.AppointmentDate, A.DurationMinutes, U.Name AS DoctorName, R.RoomNumber FROM APPOINTMENT A JOIN `USER` U ON U.UserID = A.DoctorID JOIN CLINIC_ROOM R ON R.RoomID = A.RoomID WHERE A.PatientID = ? AND A.AppointmentDate >= NOW() ORDER BY A.AppointmentDate ASC LIMIT 8");
    $appointmentsStmt->execute([(int) $profile['PatientID']]);
    $appointments = $appointmentsStmt->fetchAll();

    $symptomStmt = $pdo->prepare("SELECT SymptomName, SeverityScore, LoggedAt FROM SYMPTOM_LOG WHERE PatientID = ? ORDER BY LoggedAt DESC LIMIT 12");
    $symptomStmt->execute([(int) $profile['PatientID']]);
    $symptoms = $symptomStmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Guardian Profile View — PreMed</title>
    <link rel="stylesheet" href="style.css">
</head><body>
<?php include 'nav.php'; ?>
<div class="container" style="max-width:960px;margin:clamp(10px,4vw,36px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Guardian Access</p>
            <h2>Patient Profile View</h2>
            <p class="page-subtitle">Read-only care visibility for the linked patient.</p>
        </div>
        <span class="role-pill role-guardian">Guardian</span>
    </div>

    <?php if (!$profile): ?>
        <div class="empty-state">
            <strong>No patient linked</strong>
            <span>No patient profile is linked to this guardian account. Contact the hospital administration.</span>
        </div>
    <?php else: ?>

        <div class="metric-row cols-4" style="margin:20px 0;">
            <div><span>Patient</span><strong><?= htmlspecialchars($profile['PatientName']) ?></strong></div>
            <div><span>Profile Status</span>
                <strong style="color:<?= $profile['ProfileStatus'] === 'Requires Attention' ? 'var(--coral)' : 'var(--green)' ?>">
                    <?= htmlspecialchars($profile['ProfileStatus']) ?>
                </strong>
            </div>
            <div><span>Risk Level</span>
                <strong class="risk-<?= strtolower($profile['RiskLevel']) ?>">
                    <?= htmlspecialchars($profile['RiskLevel']) ?>
                </strong>
            </div>
            <div><span>Assigned Doctor</span><strong><?= htmlspecialchars($profile['DoctorName'] ?? 'Not assigned') ?></strong></div>
        </div>

        <h3>Patient Snapshot</h3>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Blood Group</th><th>Gender</th><th>Date of Birth</th><th>Guardian Contact</th></tr></thead>
            <tbody>
            <tr>
                <td><?= htmlspecialchars($profile['BloodGroup'] ?? 'Not set') ?></td>
                <td><?= htmlspecialchars($profile['Gender'] ?? 'Not set') ?></td>
                <td><?= $profile['DateOfBirth'] ? htmlspecialchars(date('M j, Y', strtotime($profile['DateOfBirth']))) : 'Not set' ?></td>
                <td><?= htmlspecialchars($profile['GuardianName'] . ' · ' . $profile['Phone']) ?></td>
            </tr>
            </tbody>
        </table>
        </div>

        <h3>Upcoming Appointments</h3>
        <?php if ($appointments): ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Date &amp; Time</th><th>Duration</th><th>Doctor</th><th>Room</th></tr></thead>
            <tbody>
            <?php foreach ($appointments as $appt): ?>
            <tr>
                <td><strong><?= htmlspecialchars(date('M j, Y', strtotime($appt['AppointmentDate']))) ?></strong>
                    <br><small style="color:var(--muted)"><?= htmlspecialchars(date('g:i A', strtotime($appt['AppointmentDate']))) ?></small></td>
                <td><?= (int)$appt['DurationMinutes'] ?> min</td>
                <td><?= htmlspecialchars($appt['DoctorName']) ?></td>
                <td>Room <?= htmlspecialchars($appt['RoomNumber']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <div class="empty-state compact"><strong>No upcoming appointments</strong><span>No scheduled appointments for this patient.</span></div>
        <?php endif; ?>

        <h3>Recent Symptom Logs</h3>
        <?php if ($symptoms): ?>
        <div class="table-wrap">
        <table>
            <thead><tr><th>Logged At</th><th>Symptom</th><th>Severity</th></tr></thead>
            <tbody>
            <?php foreach ($symptoms as $symptom):
                $score = (int)$symptom['SeverityScore'];
                $badgeClass = $score >= 7 ? 'severity-high' : ($score >= 4 ? 'severity-mid' : 'severity-low');
                $badgeLabel = $score >= 7 ? 'High' : ($score >= 4 ? 'Moderate' : 'Low');
            ?>
            <tr>
                <td style="color:var(--muted);font-size:13px;"><?= htmlspecialchars(date('M j, Y g:i A', strtotime($symptom['LoggedAt']))) ?></td>
                <td><strong><?= htmlspecialchars($symptom['SymptomName']) ?></strong></td>
                <td><span class="severity-badge <?= $badgeClass ?>"><?= $score ?>/10 · <?= $badgeLabel ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <div class="empty-state compact"><strong>No symptom logs yet</strong><span>Symptom history will appear here once the patient starts logging.</span></div>
        <?php endif; ?>

    <?php endif; ?>

    <div style="margin-top:24px;"><a class="text-link" href="dashboard.php">← Back to dashboard</a></div>
</div>
<?php include 'footer_nav.php'; ?>
</body>
</html>
