<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Staff') {
    header('Location: login.php');
    exit();
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appt_id'], $_POST['new_status'])) {
    require_csrf();
    $apptId = filter_input(INPUT_POST, 'appt_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['new_status'];
    $allowedStatuses = ['Scheduled', 'Confirmed', 'Completed', 'Cancelled'];

    if ($apptId && in_array($newStatus, $allowedStatuses, true)) {
        $update = $pdo->prepare('UPDATE APPOINTMENT SET Status = ? WHERE AppointmentID = ?');
        $update->execute([$newStatus, $apptId]);
        $msg = $update->rowCount() ? 'Appointment status updated to ' . htmlspecialchars($newStatus) . '.' : 'No status change was made.';
    } else {
        $error = 'Choose a valid appointment status.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_for_patient'])) {
    require_csrf();
    $patientId = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
    $doctorId = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
    $roomId = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
    $appointmentDate = str_replace('T', ' ', trim($_POST['appointment_date'] ?? ''));
    $duration = filter_input(INPUT_POST, 'duration_minutes', FILTER_VALIDATE_INT);

    if (!$patientId || !$doctorId || !$roomId || !$duration || $duration < 15 || $duration > 240
        || strtotime($appointmentDate) === false || strtotime($appointmentDate) < time()) {
        $error = 'Please choose a patient, doctor, room, future date/time, and valid duration.';
    } else {
        $conflict = $pdo->prepare("\n            SELECT AppointmentID FROM APPOINTMENT\n            WHERE (DoctorID = ? OR RoomID = ?)\n              AND Status NOT IN ('Cancelled')\n              AND AppointmentDate < DATE_ADD(?, INTERVAL ? MINUTE)\n              AND DATE_ADD(AppointmentDate, INTERVAL DurationMinutes MINUTE) > ?\n            LIMIT 1\n        ");
        $conflict->execute([$doctorId, $roomId, $appointmentDate, $duration, $appointmentDate]);

        if ($conflict->fetch()) {
            $error = 'Conflict detected: that doctor or room is already booked during this time.';
        } else {
            try {
                $pdo->prepare("INSERT INTO APPOINTMENT (AppointmentDate, DurationMinutes, Status, PatientID, DoctorID, RoomID) VALUES (?, ?, 'Confirmed', ?, ?, ?)")
                    ->execute([$appointmentDate, $duration, $patientId, $doctorId, $roomId]);
                $msg = 'Appointment booked and marked Confirmed for ' . date('D, M j Y \\a\\t g:i A', strtotime($appointmentDate)) . '.';
            } catch (Throwable $exception) {
                $error = 'The appointment could not be booked. Please choose another time.';
            }
        }
    }
}

$patients = $pdo->query("SELECT P.UserID, U.Name, P.PatientCode FROM PATIENT P JOIN `USER` U ON U.UserID = P.UserID ORDER BY U.Name")->fetchAll();
$doctors = $pdo->query("SELECT D.UserID, U.Name, Dep.DeptName FROM DOCTOR D JOIN `USER` U ON D.UserID = U.UserID JOIN DEPARTMENT Dep ON D.DeptID = Dep.DeptID ORDER BY U.Name")->fetchAll();
$rooms = $pdo->query('SELECT RoomID, RoomNumber FROM CLINIC_ROOM ORDER BY RoomNumber')->fetchAll();
$appointments = $pdo->query("\n    SELECT A.AppointmentID, A.AppointmentDate, A.DurationMinutes, A.Status,\n           PatientUser.Name AS PatientName, DoctorUser.Name AS DoctorName, R.RoomNumber\n    FROM APPOINTMENT A\n    JOIN `USER` PatientUser ON PatientUser.UserID = A.PatientID\n    JOIN `USER` DoctorUser ON DoctorUser.UserID = A.DoctorID\n    JOIN CLINIC_ROOM R ON R.RoomID = A.RoomID\n    WHERE A.AppointmentDate >= DATE_SUB(NOW(), INTERVAL 30 DAY)\n    ORDER BY A.AppointmentDate >= NOW() DESC, A.AppointmentDate ASC\n    LIMIT 40\n")->fetchAll();
$minDate = date('Y-m-d\\TH:i', strtotime('+30 minutes'));
$statusStyles = [
    'Scheduled' => ['color' => 'var(--teal)', 'background' => 'rgba(15,200,228,.12)'],
    'Confirmed' => ['color' => 'var(--green)', 'background' => 'rgba(34,212,158,.12)'],
    'Completed' => ['color' => 'var(--muted)', 'background' => 'rgba(122,153,176,.12)'],
    'Cancelled' => ['color' => 'var(--coral)', 'background' => 'rgba(255,95,91,.12)'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Appointments — PreMed</title>
    <meta name="description" content="Manage patient appointments and booking status.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="container staff-appt-container" style="width:min(96%, 1520px) !important;max-width:1520px !important;margin:clamp(10px,3.5vw,36px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Staff Panel</p>
            <h2>Appointments</h2>
            <p class="page-subtitle">Book appointments for patients and keep scheduling status current.</p>
        </div>
        <span class="role-pill role-staff">Staff</span>
    </div>

    <?php if ($msg): ?><p class="notice success"><?= $msg ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <div class="staff-appointment-layout">
        <section class="card staff-appointment-card staff-booking-card">
            <h3 style="margin-top:0;">Book for a Patient</h3>
            <p class="page-subtitle">New staff bookings are created as Confirmed.</p>
            <form method="POST" style="margin-top:16px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="book_for_patient" value="1">

                <label for="patient_id">Patient</label>
                <select id="patient_id" name="patient_id" required>
                    <option value="">Choose patient...</option>
                    <?php foreach ($patients as $patient): ?>
                        <option value="<?= (int)$patient['UserID'] ?>"><?= htmlspecialchars($patient['Name']) ?> (<?= htmlspecialchars($patient['PatientCode']) ?>)</option>
                    <?php endforeach; ?>
                </select>

                <label for="doctor_id">Doctor</label>
                <select id="doctor_id" name="doctor_id" required>
                    <option value="">Choose doctor...</option>
                    <?php foreach ($doctors as $doctor): ?>
                        <option value="<?= (int)$doctor['UserID'] ?>"><?= htmlspecialchars(format_doctor_name($doctor['Name'])) ?> - <?= htmlspecialchars($doctor['DeptName']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="room_id">Clinic room</label>
                <select id="room_id" name="room_id" required>
                    <option value="">Choose room...</option>
                    <?php foreach ($rooms as $room): ?>
                        <option value="<?= (int)$room['RoomID'] ?>">Room <?= htmlspecialchars($room['RoomNumber']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label for="appointment_date">Date &amp; time</label>
                <input type="datetime-local" id="appointment_date" name="appointment_date" min="<?= $minDate ?>" required>

                <label for="duration_minutes">Duration</label>
                <select id="duration_minutes" name="duration_minutes" required>
                    <?php foreach ([15, 30, 45, 60] as $durationOption): ?>
                        <option value="<?= $durationOption ?>"<?= $durationOption === 30 ? ' selected' : '' ?>><?= $durationOption ?> minutes</option>
                    <?php endforeach; ?>
                </select>

                <button type="submit" style="width:100%;margin-top:8px;">Book Appointment</button>
            </form>
        </section>

        <section class="card staff-appointment-card staff-status-card">
            <div class="section-heading">
                <div><h3 style="margin-top:0;">Current Appointment Status</h3><p class="page-subtitle">Upcoming appointments appear first, followed by the last 30 days.</p></div>
            </div>
            <?php if ($appointments): ?>
            <div class="table-wrap staff-status-table-wrap">
                <table class="staff-status-table">
                    <thead><tr><th>Date &amp; Time</th><th>Patient</th><th>Doctor</th><th>Room</th><th>Status</th><th>Update</th></tr></thead>
                    <tbody>
                    <?php foreach ($appointments as $appointment):
                        $status = $appointment['Status'];
                        $style = $statusStyles[$status] ?? $statusStyles['Scheduled'];
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars(date('M j, Y', strtotime($appointment['AppointmentDate']))) ?></strong><br><small style="color:var(--muted)"><?= htmlspecialchars(date('g:i A', strtotime($appointment['AppointmentDate']))) ?> · <?= (int)$appointment['DurationMinutes'] ?> min</small></td>
                        <td><?= htmlspecialchars($appointment['PatientName']) ?></td>
                        <td><?= htmlspecialchars(format_doctor_name($appointment['DoctorName'])) ?></td>
                        <td>Room <?= htmlspecialchars($appointment['RoomNumber']) ?></td>
                        <td><span style="display:inline-flex;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;color:<?= $style['color'] ?>;background:<?= $style['background'] ?>;"><?= htmlspecialchars($status) ?></span></td>
                        <td>
                            <form method="POST" style="margin:0;gap:6px;align-items:center;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="appt_id" value="<?= (int)$appointment['AppointmentID'] ?>">
                                <select name="new_status" aria-label="Update status for <?= htmlspecialchars($appointment['PatientName']) ?>" style="width:auto;margin:0;padding:7px 8px;">
                                    <?php foreach (['Scheduled', 'Confirmed', 'Completed', 'Cancelled'] as $option): ?>
                                        <option value="<?= $option ?>"<?= $option === $status ? ' selected' : '' ?>><?= $option ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm">Save</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="empty-state compact"><strong>No appointments found</strong><span>Use the booking form to create the first appointment.</span></div>
            <?php endif; ?>
        </section>
    </div>
</div>
<script>
document.querySelectorAll('form').forEach(f => {
    f.addEventListener('submit', e => {
        const select = f.querySelector('select[name="new_status"]');
        if (select && select.value === 'Cancelled' && !f.dataset.confirmed) {
            e.preventDefault();
            if (window.openPremedConfirm) {
                window.openPremedConfirm({
                    title: 'Cancel Clinic Appointment?',
                    message: 'Are you sure you want to mark this appointment as Cancelled? Both patient and assigned doctor schedules will be updated.',
                    confirmText: 'Yes, Cancel Appointment',
                    confirmType: 'danger',
                    onConfirm: () => {
                        f.dataset.confirmed = 'true';
                        f.submit();
                    }
                });
            }
        }
    });
});
</script>
<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>
