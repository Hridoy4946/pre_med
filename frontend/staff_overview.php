<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Staff') {
    header('Location: login.php');
    exit();
}

$summary = db_fetch_one($conn, "SELECT (SELECT COUNT(*) FROM APPOINTMENT WHERE AppointmentDate >= NOW() AND AppointmentDate < DATE_ADD(NOW(), INTERVAL 7 DAY)) AS UpcomingAppointments, (SELECT COUNT(*) FROM APPOINTMENT WHERE DATE(AppointmentDate) = CURDATE()) AS TodayAppointments, (SELECT COUNT(*) FROM MEDICATION WHERE InventoryStatus = 'Reorder Needed') AS ReorderCount, (SELECT COUNT(*) FROM VISIT V LEFT JOIN INVOICE I ON I.VisitID = V.VisitID WHERE I.InvoiceID IS NULL) AS UnbilledVisits") ?: ['UpcomingAppointments' => 0, 'TodayAppointments' => 0, 'ReorderCount' => 0, 'UnbilledVisits' => 0];

$upcomingAppointments = db_fetch_all($conn, "SELECT A.AppointmentDate, A.DurationMinutes, UPatient.Name AS PatientName, UDoctor.Name AS DoctorName, R.RoomNumber FROM APPOINTMENT A JOIN `USER` UPatient ON UPatient.UserID = A.PatientID JOIN `USER` UDoctor ON UDoctor.UserID = A.DoctorID JOIN CLINIC_ROOM R ON R.RoomID = A.RoomID WHERE A.AppointmentDate >= NOW() ORDER BY A.AppointmentDate ASC LIMIT 12");

$roomUsage = db_fetch_all($conn, "SELECT R.RoomNumber, COUNT(A.AppointmentID) AS SlotCount FROM CLINIC_ROOM R LEFT JOIN APPOINTMENT A ON A.RoomID = R.RoomID AND A.AppointmentDate >= CURDATE() AND A.AppointmentDate < DATE_ADD(CURDATE(), INTERVAL 1 DAY) GROUP BY R.RoomID, R.RoomNumber ORDER BY SlotCount DESC, R.RoomNumber");

$inventoryRows = db_fetch_all($conn, "SELECT MedicationName, StockQuantity, InventoryStatus FROM MEDICATION ORDER BY StockQuantity ASC, MedicationName ASC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Operations Overview — PreMed</title>
    <meta name="description" content="Staff operational overview: appointments, room usage, inventory, and billing status.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="container" style="max-width:1000px;margin:clamp(10px,4vw,36px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Staff Panel</p>
            <h2>Operations Overview</h2>
            <p class="page-subtitle">Scheduling, room load, medication inventory, and billing readiness.</p>
        </div>
        <span class="role-pill role-staff">Staff</span>
    </div>

    <div class="metric-row cols-4" style="margin:20px 0;">
        <div>
            <span>Upcoming (7 days)</span>
            <strong><?= (int)$summary['UpcomingAppointments'] ?></strong>
        </div>
        <div>
            <span>Today's appointments</span>
            <strong><?= (int)$summary['TodayAppointments'] ?></strong>
        </div>
        <div>
            <span>Reorder alerts</span>
            <strong style="color:<?= $summary['ReorderCount'] > 0 ? 'var(--coral)' : 'var(--green)' ?>"><?= (int)$summary['ReorderCount'] ?></strong>
        </div>
        <div>
            <span>Visits without invoice</span>
            <strong style="color:<?= $summary['UnbilledVisits'] > 0 ? 'var(--amber)' : 'var(--green)' ?>"><?= (int)$summary['UnbilledVisits'] ?></strong>
        </div>
    </div>

    <h3>Upcoming Appointments</h3>
    <?php if ($upcomingAppointments): ?>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Date &amp; Time</th><th>Duration</th><th>Patient</th><th>Doctor</th><th>Room</th></tr></thead>
        <tbody>
        <?php foreach ($upcomingAppointments as $appointment): ?>
        <tr>
            <td><strong><?= htmlspecialchars(date('M j, Y', strtotime($appointment['AppointmentDate']))) ?></strong><br>
                <small style="color:var(--muted)"><?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentDate']))) ?></small></td>
            <td><?= (int)$appointment['DurationMinutes'] ?> min</td>
            <td><?= htmlspecialchars($appointment['PatientName']) ?></td>
            <td><?= htmlspecialchars($appointment['DoctorName']) ?></td>
            <td>Room <?= htmlspecialchars($appointment['RoomNumber']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
        <div class="empty-state compact"><strong>No upcoming appointments</strong><span>No appointments are scheduled in the next 7 days.</span></div>
    <?php endif; ?>

    <h3>Room Usage Today</h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Room</th><th>Booked Slots Today</th><th>Load</th></tr></thead>
        <tbody>
        <?php foreach ($roomUsage as $room): ?>
        <tr>
            <td><strong>Room <?= htmlspecialchars($room['RoomNumber']) ?></strong></td>
            <td><?= (int)$room['SlotCount'] ?> appointment<?= $room['SlotCount'] != 1 ? 's' : '' ?></td>
            <td>
                <?php if ($room['SlotCount'] == 0): ?>
                    <span class="severity-badge severity-low">Available</span>
                <?php elseif ($room['SlotCount'] >= 3): ?>
                    <span class="severity-badge severity-high">Busy</span>
                <?php else: ?>
                    <span class="severity-badge severity-mid">Moderate</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <h3>Medication Inventory</h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th>Medication</th><th>Stock</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($inventoryRows as $item): ?>
        <tr>
            <td><strong><?= htmlspecialchars($item['MedicationName']) ?></strong></td>
            <td><?= (int)$item['StockQuantity'] ?> units</td>
            <td>
                <?php if ($item['InventoryStatus'] === 'Reorder Needed'): ?>
                    <span class="severity-badge severity-high">Reorder Needed</span>
                <?php else: ?>
                    <span class="severity-badge severity-low">Available</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <div style="margin-top:20px;"><a class="text-link" href="dashboard.php">← Back to Dashboard</a></div>
</div>
<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>
