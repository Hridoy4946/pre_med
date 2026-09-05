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

// Today's schedule grouped by doctor (for the summary box)
$todayStmt = $pdo->query("
    SELECT A.AppointmentDate, A.DurationMinutes, A.Status,
           DoctorUser.Name AS DoctorName, A.DoctorID,
           PatientUser.Name AS PatientName, P.PatientCode, R.RoomNumber
    FROM APPOINTMENT A
    JOIN `USER` PatientUser ON PatientUser.UserID = A.PatientID
    JOIN `USER` DoctorUser  ON DoctorUser.UserID  = A.DoctorID
    JOIN PATIENT P ON A.PatientID = P.UserID
    JOIN CLINIC_ROOM R ON R.RoomID = A.RoomID
    WHERE DATE(A.AppointmentDate) = CURDATE()
      AND A.Status != 'Cancelled'
    ORDER BY A.AppointmentDate ASC
");
$todayAll = $todayStmt->fetchAll();
// Group by DoctorName
$todayByDoctor = [];
foreach ($todayAll as $row) {
    $todayByDoctor[$row['DoctorName']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Staff Appointments — PreMed</title>
    <meta name="description" content="Manage patient appointments and booking status.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
        /* ── Today's Schedule Box (staff) ── */
        .today-schedule-box {
            border-radius: 12px;
            border: 1px solid rgba(15,200,228,.2);
            background: linear-gradient(135deg, rgba(15,200,228,.06) 0%, rgba(8,152,181,.03) 100%);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .today-schedule-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 13px 18px;
            border-bottom: 1px solid rgba(15,200,228,.15);
        }
        .today-schedule-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 700;
            color: var(--teal);
        }
        .today-count-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: 999px;
            background: var(--teal);
            color: #03111e;
        }
        .today-doctor-group { padding: 0; }
        .today-doctor-label {
            padding: 8px 18px 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--muted);
            border-bottom: 1px solid rgba(255,255,255,.04);
            background: rgba(255,255,255,.02);
        }
        .today-sched-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 9px 18px;
            border-bottom: 1px solid rgba(255,255,255,.04);
            transition: background .15s;
        }
        .today-sched-item:last-child { border-bottom: none; }
        .today-sched-item:hover { background: rgba(255,255,255,.03); }
        .today-sched-time {
            min-width: 50px;
            font-size: 12px;
            font-weight: 700;
            color: var(--teal);
            line-height: 1.3;
            text-align: center;
            padding-top: 1px;
        }
        .today-sched-time small {
            display: block;
            font-weight: 500;
            color: var(--muted);
            font-size: 10px;
        }
        .today-sched-info { flex: 1; min-width: 0; }
        .today-sched-info strong { font-size: 13px; display: block; }
        .today-sched-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 2px;
            flex-wrap: wrap;
        }
        .today-sched-meta span { font-size: 11px; color: var(--muted); }
        .today-empty {
            padding: 18px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }
        .code-badge {
            font-size: 10px; font-family: monospace;
            background: rgba(15,200,228,.12); color: var(--teal);
            border: 1px solid rgba(15,200,228,.25); border-radius: 4px;
            padding: 1px 6px;
        }
    </style>
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

    <!-- Today's Doctor Schedule Box -->
    <div class="today-schedule-box">
        <div class="today-schedule-header">
            <h3>📅 Today's Doctor Schedule &mdash; <?= date('l, M j, Y') ?></h3>
            <?php if ($todayAll): ?>
            <span class="today-count-badge"><?= count($todayAll) ?> appt<?= count($todayAll) !== 1 ? 's' : '' ?></span>
            <?php endif; ?>
        </div>
        <?php if ($todayByDoctor): ?>
        <?php
        $todayStatColors = [
            'Scheduled' => '#0fc8e4',
            'Confirmed'  => '#34d399',
            'Completed'  => '#7a99b0',
        ];
        foreach ($todayByDoctor as $docName => $appts): ?>
        <div class="today-doctor-group">
            <div class="today-doctor-label"><?= htmlspecialchars(format_doctor_name($docName)) ?> &mdash; <?= count($appts) ?> today</div>
            <?php foreach ($appts as $ts):
                $tsc = $todayStatColors[$ts['Status']] ?? '#0fc8e4';
            ?>
            <div class="today-sched-item">
                <div class="today-sched-time">
                    <?= date('g:i', strtotime($ts['AppointmentDate'])) ?>
                    <small><?= date('A', strtotime($ts['AppointmentDate'])) ?></small>
                </div>
                <div class="today-sched-info">
                    <strong><?= htmlspecialchars($ts['PatientName']) ?></strong>
                    <div class="today-sched-meta">
                        <span class="code-badge"><?= htmlspecialchars($ts['PatientCode']) ?></span>
                        <span>&middot;</span>
                        <span><?= (int)$ts['DurationMinutes'] ?> min</span>
                        <span>&middot;</span>
                        <span>Rm <?= htmlspecialchars($ts['RoomNumber']) ?></span>
                        <span>&middot;</span>
                        <span style="color:<?= $tsc ?>;font-weight:700;"><?= htmlspecialchars($ts['Status']) ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="today-empty">🗓 No appointments are scheduled for today across all doctors.</div>
        <?php endif; ?>
    </div>

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
