<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header("Location: login.php");
    exit();
}

$msg   = "";
$error = "";

// Handle appointment cancellation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_appointment_id'])) {
    require_csrf();
    $cancelId = filter_input(INPUT_POST, 'cancel_appointment_id', FILTER_VALIDATE_INT);
    if ($cancelId) {
        $cancelStmt = $pdo->prepare("
            UPDATE APPOINTMENT SET Status = 'Cancelled'
            WHERE AppointmentID = ? AND PatientID = ? AND AppointmentDate >= NOW() AND Status != 'Cancelled'
        ");
        $cancelStmt->execute([$cancelId, $_SESSION['user_id']]);
        $msg = $cancelStmt->rowCount() ? '✓ Appointment cancelled successfully.' : 'Could not cancel that appointment.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['doctor_id'])) {
    require_csrf();
    $patientId = $_SESSION['user_id'];
    $doctorId  = filter_input(INPUT_POST, 'doctor_id', FILTER_VALIDATE_INT);
    $roomId    = filter_input(INPUT_POST, 'room_id', FILTER_VALIDATE_INT);
    $apptDate  = str_replace('T', ' ', trim($_POST['appointment_date'] ?? ''));
    $duration  = filter_input(INPUT_POST, 'duration_minutes', FILTER_VALIDATE_INT);

    if (!$doctorId || !$roomId || !$duration || $duration < 15 || $duration > 240
        || strtotime($apptDate) === false || strtotime($apptDate) < time()) {
        $error = "Please choose a valid future date/time, doctor, room, and duration.";
    } else {
        // Conflict resolution: check doctor AND room overlap
        $checkStmt = $pdo->prepare("
            SELECT AppointmentID FROM APPOINTMENT
            WHERE (DoctorID = ? OR RoomID = ?)
              AND Status NOT IN ('Cancelled')
              AND AppointmentDate < DATE_ADD(?, INTERVAL ? MINUTE)
              AND DATE_ADD(AppointmentDate, INTERVAL DurationMinutes MINUTE) > ?
            LIMIT 1
        ");
        $checkStmt->execute([$doctorId, $roomId, $apptDate, $duration, $apptDate]);
        if ($checkStmt->fetch()) {
            $error = "⚠ Conflict detected: that doctor or room is already booked during this time slot. Please choose a different time.";
        } else {
            $pdo->beginTransaction();
            try {
                $pdo->prepare("INSERT INTO APPOINTMENT (AppointmentDate, DurationMinutes, Status, PatientID, DoctorID, RoomID) VALUES (?, ?, 'Scheduled', ?, ?, ?)")
                    ->execute([$apptDate, $duration, $patientId, $doctorId, $roomId]);
                // Assign doctor if patient has none
                $pdo->prepare('UPDATE PATIENT SET AssignedDoctorID = ? WHERE UserID = ? AND AssignedDoctorID IS NULL')
                    ->execute([$doctorId, $patientId]);
                $pdo->commit();
                $msg = "✓ Appointment booked for " . date('D, M j Y \a\t g:i A', strtotime($apptDate)) . " — Status: Scheduled.";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $error = "The appointment could not be scheduled. Please try a different slot.";
            }
        }
    }
}

$doctors = $pdo->query("SELECT D.UserID, U.Name, Dep.DeptName FROM DOCTOR D JOIN `USER` U ON D.UserID = U.UserID JOIN DEPARTMENT Dep ON D.DeptID = Dep.DeptID ORDER BY U.Name")->fetchAll();
$rooms   = $pdo->query("SELECT * FROM CLINIC_ROOM ORDER BY RoomNumber")->fetchAll();
$minDate = date('Y-m-d\TH:i', strtotime('+30 minutes'));

// Fetch this patient's appointments to show in the status panel
$myApptStmt = $pdo->prepare("
    SELECT A.AppointmentID, A.AppointmentDate, A.DurationMinutes, A.Status,
           U.Name AS DoctorName, Dep.DeptName, R.RoomNumber
    FROM APPOINTMENT A
    JOIN DOCTOR D ON A.DoctorID = D.UserID
    JOIN `USER` U ON D.UserID = U.UserID
    JOIN DEPARTMENT Dep ON D.DeptID = Dep.DeptID
    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
    WHERE A.PatientID = ?
    ORDER BY A.AppointmentDate DESC
    LIMIT 10
");
$myApptStmt->execute([$_SESSION['user_id']]);
$myAppointments = $myApptStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book Appointment — PreMed</title>
    <meta name="description" content="Book an appointment with a doctor. Conflict detection prevents double-booking of doctors and rooms.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;padding:clamp(10px,4vw,36px) clamp(10px,3vw,24px);max-width:1200px;margin:0 auto;">

<!-- LEFT: Booking Form -->
<div class="card" style="flex:1;min-width:300px;max-width:520px;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Scheduling</p>
            <h2>Book an Appointment</h2>
            <p class="page-subtitle">Conflict detection prevents double-booking of doctors and rooms.</p>
        </div>
        <span class="role-pill role-patient">Patient</span>
    </div>

    <?php if ($msg):   ?><p class="notice success"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form method="POST" style="margin-top:18px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <label for="doctor_id">Doctor</label>
        <select name="doctor_id" id="doctor_id" required>
            <option value="">Choose a doctor…</option>
            <?php foreach ($doctors as $doc): ?>
                <option value="<?= (int)$doc['UserID'] ?>"
                    <?= (isset($_POST['doctor_id']) && (int)$_POST['doctor_id'] === (int)$doc['UserID']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($doc['Name']) ?> — <?= htmlspecialchars($doc['DeptName']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="room_id">Clinic room</label>
        <select name="room_id" id="room_id" required>
            <option value="">Choose a room…</option>
            <?php foreach ($rooms as $room): ?>
                <option value="<?= (int)$room['RoomID'] ?>"
                    <?= (isset($_POST['room_id']) && (int)$_POST['room_id'] === (int)$room['RoomID']) ? 'selected' : '' ?>>
                    Room <?= htmlspecialchars($room['RoomNumber']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="appointment_date">Date &amp; time</label>
        <input type="datetime-local" id="appointment_date" name="appointment_date"
               min="<?= $minDate ?>"
               value="<?= htmlspecialchars($_POST['appointment_date'] ?? '') ?>" required>

        <label>Duration</label>
        <div class="duration-presets">
            <?php foreach ([15, 30, 45, 60] as $d): ?>
                <button type="button" class="duration-btn <?= ($d === 30) ? 'active' : '' ?>"
                        data-minutes="<?= $d ?>"><?= $d ?> min</button>
            <?php endforeach; ?>
        </div>
        <input type="hidden" id="duration_minutes" name="duration_minutes" value="30">

        <div class="callout" style="margin-top:12px;">
            <strong>How this works:</strong> Before booking, the system checks that neither the selected doctor nor the room has an overlapping appointment during your chosen time slot.
        </div>

        <button type="submit" style="margin-top:16px;">Check &amp; Book Slot</button>
    </form>
    <div style="margin-top:14px;"><a class="text-link" href="dashboard.php">← Back to dashboard</a></div>
</div>

<!-- RIGHT: Current Appointment Status Panel -->
<div class="card" style="flex:2;min-width:340px;">
    <div class="page-header" style="margin-bottom:16px;">
        <div class="page-header-left">
            <p class="eyebrow">Your Bookings</p>
            <h3 style="margin:0;">Current Appointment Status</h3>
        </div>
    </div>

    <?php
    $statusColors = [
        'Scheduled'  => ['bg' => 'rgba(15,200,228,.12)',  'color' => '#0fc8e4',  'border' => 'rgba(15,200,228,.3)'],
        'Confirmed'  => ['bg' => 'rgba(52,211,153,.12)',  'color' => '#34d399',  'border' => 'rgba(52,211,153,.3)'],
        'Completed'  => ['bg' => 'rgba(122,153,176,.10)', 'color' => '#7a99b0',  'border' => 'rgba(122,153,176,.25)'],
        'Cancelled'  => ['bg' => 'rgba(255,95,91,.10)',   'color' => '#ff5f5b',  'border' => 'rgba(255,95,91,.3)'],
    ];
    $statusIcons = [
        'Scheduled' => '🕐', 'Confirmed' => '✅', 'Completed' => '✔', 'Cancelled' => '✕',
    ];
    ?>

    <?php if ($myAppointments): ?>
    <div class="table-wrap">
    <table>
        <thead><tr>
            <th>Date &amp; Time</th>
            <th>Doctor</th>
            <th>Room</th>
            <th>Duration</th>
            <th>Status</th>
            <th>Action</th>
        </tr></thead>
        <tbody>
        <?php foreach ($myAppointments as $appt):
            $st    = $appt['Status'];
            $sc    = $statusColors[$st] ?? $statusColors['Scheduled'];
            $isUpcoming = strtotime($appt['AppointmentDate']) >= time();
            $isCancellable = $isUpcoming && $st !== 'Cancelled' && $st !== 'Completed';
        ?>
        <tr>
            <td>
                <strong><?= htmlspecialchars(date('M j, Y', strtotime($appt['AppointmentDate']))) ?></strong><br>
                <small style="color:var(--muted)"><?= htmlspecialchars(date('g:i A', strtotime($appt['AppointmentDate']))) ?></small>
            </td>
            <td>
                <?= htmlspecialchars($appt['DoctorName']) ?><br>
                <small style="color:var(--muted)"><?= htmlspecialchars($appt['DeptName']) ?></small>
            </td>
            <td>Room <?= htmlspecialchars($appt['RoomNumber']) ?></td>
            <td><?= (int)$appt['DurationMinutes'] ?> min</td>
            <td>
                <span style="
                    display:inline-flex;align-items:center;gap:5px;
                    padding:4px 11px;border-radius:999px;font-size:12px;font-weight:700;
                    background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['border'] ?>;
                ">
                    <?= $statusIcons[$st] ?? '' ?> <?= htmlspecialchars($st) ?>
                </span>
            </td>
            <td>
                <?php if ($isCancellable): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="cancel_appointment_id" value="<?= (int)$appt['AppointmentID'] ?>">
                    <button type="submit"
                            data-confirm-title="Cancel Appointment?"
                            data-confirm-message="Are you sure you want to cancel your scheduled appointment with <?= htmlspecialchars(format_doctor_name($appt['DoctorName'])) ?>? Your doctor and clinic staff will be notified."
                            data-confirm-btn="Yes, Cancel Appointment"
                            data-confirm-type="danger"
                            style="
                        padding:4px 12px;font-size:12px;border-radius:6px;
                        background:rgba(255,95,91,.12);color:#ff5f5b;
                        border:1px solid rgba(255,95,91,.3);cursor:pointer;
                    ">Cancel</button>
                </form>
                <?php else: ?>
                    <span style="color:var(--muted);font-size:12px;">—</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>

    <!-- Status legend -->
    <div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:16px;">
        <?php foreach ($statusColors as $label => $sc): ?>
        <span style="display:inline-flex;align-items:center;gap:5px;font-size:11px;font-weight:700;
                     padding:3px 10px;border-radius:999px;
                     background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['border'] ?>;">
            <?= $statusIcons[$label] ?? '' ?> <?= $label ?>
        </span>
        <?php endforeach; ?>
        <span style="font-size:11px;color:var(--muted);align-self:center;">— Doctor or staff may update to Confirmed after review.</span>
    </div>

    <?php else: ?>
        <div class="empty-state compact">
            <strong>No appointments yet</strong>
            <span>Book your first appointment using the form above.</span>
        </div>
    <?php endif; ?>
</div><!-- end status card -->
</div><!-- end flex wrapper -->

<script>
const durationInput = document.getElementById('duration_minutes');
document.querySelectorAll('.duration-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.duration-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        durationInput.value = btn.dataset.minutes;
    });
});
</script>
<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>