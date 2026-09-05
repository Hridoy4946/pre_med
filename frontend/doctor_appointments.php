<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit();
}
$doctorId = $_SESSION['user_id'];

$updateMsg = '';
// Handle status update by doctor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['appt_id'], $_POST['new_status'])) {
    require_csrf();
    $apptId    = filter_input(INPUT_POST, 'appt_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['new_status'];
    $allowed   = ['Scheduled', 'Confirmed', 'Completed', 'Cancelled'];
    if ($apptId && in_array($newStatus, $allowed, true)) {
        db_execute($conn, "
            UPDATE APPOINTMENT SET Status = ?
            WHERE AppointmentID = ? AND DoctorID = ?
        ", [$newStatus, $apptId, $doctorId]);
        $updateMsg = db_affected_rows($conn) ? '✓ Status updated to ' . htmlspecialchars($newStatus) . '.' : 'No change made.';
    }
}

$upcomingSql = "
    SELECT A.AppointmentID, A.AppointmentDate, A.DurationMinutes, A.Status,
           U.Name AS PatientName, P.PatientCode, P.RiskLevel, P.ProfileStatus,
           R.RoomNumber
    FROM APPOINTMENT A
    JOIN PATIENT P ON A.PatientID = P.UserID
    JOIN `USER` U ON P.UserID = U.UserID
    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
    WHERE A.DoctorID = ? AND A.AppointmentDate >= NOW() AND A.Status != 'Cancelled'
    ORDER BY A.AppointmentDate ASC
";
$upcoming = db_fetch_all($conn, $upcomingSql, [$doctorId]);

$pastSql = "
    SELECT A.AppointmentID, A.AppointmentDate, A.DurationMinutes, A.Status,
           U.Name AS PatientName, P.PatientCode, P.RiskLevel,
           R.RoomNumber
    FROM APPOINTMENT A
    JOIN PATIENT P ON A.PatientID = P.UserID
    JOIN `USER` U ON P.UserID = U.UserID
    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
    WHERE A.DoctorID = ? AND (A.AppointmentDate < NOW() OR A.Status = 'Cancelled')
    ORDER BY A.AppointmentDate DESC
    LIMIT 30
";
$past = db_fetch_all($conn, $pastSql, [$doctorId]);

$countUpcoming = count($upcoming);
$countPast     = count($past);

// Handle new appointment scheduling by doctor for a patient
$schedMsg = ''; $schedError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sched_patient_id'])) {
    require_csrf();
    $schedPatientId = filter_input(INPUT_POST, 'sched_patient_id', FILTER_VALIDATE_INT);
    $schedRoomId    = filter_input(INPUT_POST, 'sched_room_id',    FILTER_VALIDATE_INT);
    $schedDate      = str_replace('T', ' ', trim($_POST['sched_date'] ?? ''));
    $schedDuration  = filter_input(INPUT_POST, 'sched_duration',   FILTER_VALIDATE_INT);

    if (!$schedPatientId || !$schedRoomId || !$schedDuration || $schedDuration < 15 || $schedDuration > 240
        || strtotime($schedDate) === false || strtotime($schedDate) < time()) {
        $schedError = 'Please fill in all fields and choose a future date/time.';
    } else {
        // Verify this is one of the doctor's assigned patients
        if (!db_fetch_column($conn, "SELECT 1 FROM PATIENT WHERE UserID = ? AND AssignedDoctorID = ?", [$schedPatientId, $doctorId])) {
            $schedError = 'You can only schedule appointments for your assigned patients.';
        } else {
            $conflict = db_fetch_one($conn, "
                SELECT AppointmentID FROM APPOINTMENT
                WHERE (DoctorID = ? OR RoomID = ?)
                  AND Status NOT IN ('Cancelled')
                  AND AppointmentDate < DATE_ADD(?, INTERVAL ? MINUTE)
                  AND DATE_ADD(AppointmentDate, INTERVAL DurationMinutes MINUTE) > ?
                LIMIT 1
            ", [$doctorId, $schedRoomId, $schedDate, $schedDuration, $schedDate]);
            if ($conflict) {
                $schedError = '⚠ Conflict: that room or your schedule is already booked for this time slot.';
            } else {
                db_execute($conn, "INSERT INTO APPOINTMENT (AppointmentDate, DurationMinutes, Status, PatientID, DoctorID, RoomID) VALUES (?, ?, 'Confirmed', ?, ?, ?)", [$schedDate, $schedDuration, $schedPatientId, $doctorId, $schedRoomId]);
                // Re-fetch upcoming
                $upcoming = db_fetch_all($conn, $upcomingSql, [$doctorId]);
                $countUpcoming = count($upcoming);
                $schedMsg = '✓ Appointment scheduled and set to Confirmed for ' . date('D, M j Y \a\t g:i A', strtotime($schedDate)) . '.';
            }
        }
    }
}

// Fetch doctor's assigned patients for the scheduling dropdown
$myPatients = db_fetch_all($conn, "
    SELECT P.UserID, U.Name, P.PatientCode, P.RiskLevel
    FROM PATIENT P JOIN `USER` U ON U.UserID = P.UserID
    WHERE P.AssignedDoctorID = ? ORDER BY U.Name
", [$doctorId]);

$rooms = db_fetch_all($conn, "SELECT * FROM CLINIC_ROOM ORDER BY RoomNumber");
$minDate = date('Y-m-d\TH:i', strtotime('+30 minutes'));

// Today's schedule for the doctor
$todaySchedule = db_fetch_all($conn, "
    SELECT A.AppointmentDate, A.DurationMinutes, A.Status,
           U.Name AS PatientName, P.PatientCode, P.RiskLevel, R.RoomNumber
    FROM APPOINTMENT A
    JOIN PATIENT P ON A.PatientID = P.UserID
    JOIN `USER` U ON P.UserID = U.UserID
    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
    WHERE A.DoctorID = ?
      AND DATE(A.AppointmentDate) = CURDATE()
      AND A.Status != 'Cancelled'
    ORDER BY A.AppointmentDate ASC
", [$doctorId]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Appointments — PreMed</title>
    <meta name="description" content="View all your scheduled and past patient appointments.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
        .appt-tabs { display:flex; gap:8px; margin-bottom:20px; }
        .appt-tab {
            padding:7px 20px; border-radius:999px; cursor:pointer;
            font-size:13px; font-weight:700; border:1px solid var(--line);
            background:transparent; color:var(--muted); transition:.15s;
        }
        .appt-tab.active { background:var(--teal); color:#0b1929; border-color:var(--teal); }
        .appt-section { display:none; }
        .appt-section.active { display:block; }
        .risk-High   { color:#ff5f5b; font-weight:700; }
        .risk-Medium { color:#f59e0b; font-weight:700; }
        .risk-Low    { color:#34d399; font-weight:700; }
        .code-badge {
            font-size:11px; font-family:monospace;
            background:rgba(15,200,228,.12); color:var(--teal);
            border:1px solid rgba(15,200,228,.25); border-radius:4px;
            padding:2px 7px; letter-spacing:.04em;
        }
        .today-marker {
            display:inline-flex; align-items:center; gap:5px;
            font-size:11px; font-weight:700; color:#f59e0b;
            background:rgba(245,158,11,.1); border:1px solid rgba(245,158,11,.25);
            border-radius:4px; padding:2px 8px;
        }

        /* ── Today's Schedule Box ── */
        .today-schedule-box {
            margin-top: 24px;
            border-radius: 12px;
            border: 1px solid rgba(15,200,228,.2);
            background: linear-gradient(135deg, rgba(15,200,228,.06) 0%, rgba(8,152,181,.04) 100%);
            overflow: hidden;
        }
        .today-schedule-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 16px;
            border-bottom: 1px solid rgba(15,200,228,.15);
        }
        .today-schedule-header h4 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--teal);
            display: flex;
            align-items: center;
            gap: 7px;
        }
        .today-count-badge {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 999px;
            background: var(--teal);
            color: #03111e;
        }
        .today-sched-item {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 10px 16px;
            border-bottom: 1px solid rgba(255,255,255,.04);
            transition: background .15s;
        }
        .today-sched-item:last-child { border-bottom: none; }
        .today-sched-item:hover { background: rgba(255,255,255,.03); }
        .today-sched-time {
            min-width: 52px;
            font-size: 12px;
            font-weight: 700;
            color: var(--teal);
            line-height: 1.3;
            text-align: center;
            padding-top: 2px;
        }
        .today-sched-time small {
            display: block;
            font-weight: 500;
            color: var(--muted);
            font-size: 10px;
        }
        .today-sched-info {
            flex: 1;
            min-width: 0;
        }
        .today-sched-info strong {
            font-size: 13px;
            display: block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .today-sched-meta {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-top: 3px;
            flex-wrap: wrap;
        }
        .today-sched-meta span {
            font-size: 11px;
            color: var(--muted);
        }
        .today-empty {
            padding: 18px 16px;
            text-align: center;
            color: var(--muted);
            font-size: 13px;
        }

        /* ── Status Dropdown & Save Button in Table ── */
        .status-dropdown {
            width: 126px !important;
            min-width: 126px !important;
            height: 32px !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            padding: 4px 26px 4px 10px !important;
            background-color: #0b1e32 !important;
            background-position: right 8px center !important;
            background-size: 12px 12px !important;
            border: 1px solid var(--line) !important;
            border-radius: 6px !important;
            color: #e8f4fb !important;
            margin: 0 !important;
            line-height: normal !important;
            cursor: pointer !important;
            box-sizing: border-box !important;
        }
        .status-dropdown:focus {
            border-color: var(--teal) !important;
            outline: none !important;
        }
        .btn-save-status {
            width: auto !important;
            height: 32px !important;
            font-size: 12px !important;
            font-weight: 700 !important;
            padding: 0 12px !important;
            margin: 0 !important;
            border-radius: 6px !important;
            background: rgba(15,200,228,.15) !important;
            border: 1px solid rgba(15,200,228,.35) !important;
            color: var(--teal) !important;
            cursor: pointer !important;
            white-space: nowrap !important;
            display: inline-flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all .15s ease !important;
        }
        .btn-save-status:hover {
            background: var(--teal) !important;
            color: #0b1929 !important;
            box-shadow: 0 0 10px rgba(15,200,228,.3) !important;
        }

        /* ── Schedule Form Controls ── */
        .sched-form {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }
        .sched-select, .sched-input {
            width: 100% !important;
            box-sizing: border-box !important;
            height: 40px !important;
            padding: 8px 12px !important;
            margin: 4px 0 12px !important;
            border-radius: 8px !important;
            border: 1px solid var(--line) !important;
            background-color: #0d1e2f !important;
            color: var(--ink) !important;
            font-size: 13px !important;
        }
        .sched-select:focus, .sched-input:focus {
            border-color: var(--teal) !important;
            outline: none !important;
        }
        .duration-presets {
            display: grid !important;
            grid-template-columns: repeat(4, 1fr) !important;
            gap: 6px !important;
            margin: 4px 0 14px !important;
            width: 100% !important;
        }
        .duration-btn {
            width: 100% !important;
            height: 36px !important;
            padding: 0 4px !important;
            font-size: 12px !important;
            font-weight: 600 !important;
            border-radius: 6px !important;
            border: 1px solid var(--line) !important;
            background: rgba(255,255,255,.05) !important;
            color: var(--muted) !important;
            cursor: pointer !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: all .15s ease !important;
            margin: 0 !important;
            white-space: nowrap !important;
        }
        .duration-btn.active, .duration-btn:hover {
            background: var(--teal-glow) !important;
            border-color: var(--teal) !important;
            color: var(--teal) !important;
        }
        .btn-schedule-submit {
            width: 100% !important;
            height: 42px !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            margin-top: 8px !important;
            padding: 0 16px !important;
            background: linear-gradient(135deg, #12c8e4, #0898b5) !important;
            color: #03111e !important;
            border: none !important;
            border-radius: 8px !important;
            cursor: pointer !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            transition: filter .15s ease, box-shadow .15s ease !important;
        }
        .btn-schedule-submit:hover {
            filter: brightness(1.1) !important;
            box-shadow: 0 4px 18px rgba(15,200,228,.3) !important;
        }

        /* ── Today's Enhanced Schedule Box ── */
        .day-schedule-box {
            margin-bottom: 20px;
            border-radius: 12px;
            border: 1px solid rgba(15,200,228,.2);
            background: linear-gradient(135deg, rgba(15,200,228,.05) 0%, rgba(8,152,181,.03) 100%);
            overflow: hidden;
        }
        .day-schedule-hdr {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 11px 14px;
            border-bottom: 1px solid rgba(15,200,228,.12);
        }
        .day-schedule-hdr h4 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--teal);
        }
        .day-schedule-hdr small {
            font-size: 10px;
            color: var(--muted);
            font-weight: 500;
        }
        .day-slot-count {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 999px;
            background: var(--teal);
            color: #03111e;
        }
        .day-slot-free-count {
            font-size: 11px;
            font-weight: 700;
            padding: 2px 9px;
            border-radius: 999px;
            background: rgba(34,212,158,.2);
            color: #22d49e;
            border: 1px solid rgba(34,212,158,.3);
        }
        .day-slots-list {
            padding: 8px 10px 10px;
            display: flex;
            flex-direction: column;
            gap: 5px;
            max-height: 420px;
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: rgba(15,200,228,.3) transparent;
        }
        .day-slots-list::-webkit-scrollbar { width: 4px; }
        .day-slots-list::-webkit-scrollbar-thumb { background: rgba(15,200,228,.3); border-radius: 4px; }
        .day-slot {
            display: flex;
            align-items: center;
            gap: 10px;
            border-radius: 8px;
            padding: 7px 10px;
            transition: background .15s;
        }
        .day-slot-busy {
            background: rgba(15,200,228,.06);
            border: 1px solid rgba(15,200,228,.15);
        }
        .day-slot-free {
            background: rgba(34,212,158,.06);
            border: 1px solid rgba(34,212,158,.2);
            cursor: pointer;
        }
        .day-slot-free:hover {
            background: rgba(34,212,158,.14);
            border-color: rgba(34,212,158,.5);
            transform: translateX(2px);
        }
        .day-slot-time {
            min-width: 44px;
            font-size: 11px;
            font-weight: 700;
            text-align: center;
            line-height: 1.2;
        }
        .day-slot-busy .day-slot-time { color: var(--teal); }
        .day-slot-free .day-slot-time { color: #22d49e; }
        .day-slot-pill {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 999px;
            flex-shrink: 0;
        }
        .day-slot-busy .day-slot-pill {
            background: rgba(15,200,228,.15);
            color: var(--teal);
        }
        .day-slot-free .day-slot-pill {
            background: rgba(34,212,158,.15);
            color: #22d49e;
        }
        .day-slot-detail {
            flex: 1;
            font-size: 11px;
            color: var(--muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .day-slot-free .day-slot-detail {
            color: rgba(34,212,158,.7);
            font-style: italic;
        }
        .day-slot-click-hint {
            font-size: 10px;
            color: rgba(34,212,158,.6);
            flex-shrink: 0;
        }
        .day-empty { padding: 14px; text-align: center; color: var(--muted); font-size: 12px; }
        .risk-badge-High   { color: #ff5f5b; font-weight: 700; font-size: 10px; }
        .risk-badge-Medium { color: #f59e0b; font-weight: 700; font-size: 10px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-start;padding:clamp(10px,3vw,28px) clamp(10px,2vw,20px);max-width:1700px;margin:0 auto;">

<!-- COL 1: Today's Day Planner -->
<div class="card" style="flex:0 0 260px;min-width:220px;max-width:280px;">
    <?php
    /* ── Build free-slot timeline for today ─────────────────────────────
       Working hours: 08:00 – 18:00 in 30-min increments.
       We mark slots as BUSY if they overlap with any today appointment.
    ── */
    $workStart = 8;  // 8 AM
    $workEnd   = 18; // 6 PM
    $slotStep  = 30; // minutes
    $todayDate = date('Y-m-d');

    // Build list of busy intervals from today's appointments
    $busyIntervals = [];
    foreach ($todaySchedule as $apt) {
        $s = strtotime($apt['AppointmentDate']);
        $e = $s + (int)$apt['DurationMinutes'] * 60;
        $busyIntervals[] = [
            'start'    => $s,
            'end'      => $e,
            'patient'  => $apt['PatientName'],
            'code'     => $apt['PatientCode'],
            'duration' => (int)$apt['DurationMinutes'],
            'status'   => $apt['Status'],
            'risk'     => $apt['RiskLevel'],
            'room'     => $apt['RoomNumber'],
        ];
    }

    // Generate all 30-min slots from workStart to workEnd
    $timelineSlots = [];
    $cursor = strtotime($todayDate . ' ' . str_pad($workStart, 2, '0', STR_PAD_LEFT) . ':00:00');
    $dayEnd = strtotime($todayDate . ' ' . str_pad($workEnd,   2, '0', STR_PAD_LEFT) . ':00:00');

    while ($cursor < $dayEnd) {
        $slotEnd = $cursor + $slotStep * 60;
        $slotBusy = null;
        foreach ($busyIntervals as $bi) {
            // overlap: cursor < bi.end AND slotEnd > bi.start
            if ($cursor < $bi['end'] && $slotEnd > $bi['start']) {
                $slotBusy = $bi;
                break;
            }
        }
        // Only show slot if it starts at the exact start of a busy interval OR is free
        // (skip intermediate busy slots — we show one line per appointment)
        $isApptStart = $slotBusy && abs($cursor - $slotBusy['start']) < 60;
        if (!$slotBusy || $isApptStart) {
            $timelineSlots[] = [
                'time'    => $cursor,
                'busy'    => $slotBusy ? true : false,
                'appt'    => $slotBusy,
            ];
        }
        $cursor = $slotEnd;
    }
    $freeCount = count(array_filter($timelineSlots, fn($s) => !$s['busy']));
    ?>

    <!-- ── Today's Day Planner ── -->
    <div class="page-header" style="margin-bottom:12px;">
        <div class="page-header-left">
            <p class="eyebrow">Doctor Panel</p>
            <h3 style="margin:0;font-size:15px;">Today's Schedule</h3>
        </div>
        <span class="role-pill role-doctor">Doctor</span>
    </div>

    <div style="margin-bottom:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap;">
        <?php if (count($todaySchedule) > 0): ?>
        <span class="day-slot-count"><?= count($todaySchedule) ?> busy</span>
        <?php endif; ?>
        <span class="day-slot-free-count"><?= $freeCount ?> free</span>
        <span style="font-size:10px;color:var(--muted);margin-left:2px;"><?= date('M j') ?></span>
    </div>

    <div style="background:rgba(15,200,228,.04);border:1px solid rgba(15,200,228,.12);border-radius:10px;overflow:hidden;">
        <div class="day-schedule-hdr">
            <h4>📅 Today — <?= date('l, M j') ?></h4>
            <div style="display:flex;gap:6px;align-items:center;">
                <?php if (count($todaySchedule) > 0): ?>
                <span class="day-slot-count"><?= count($todaySchedule) ?> busy</span>
                <?php endif; ?>
                <span class="day-slot-free-count"><?= $freeCount ?> free</span>
            </div>
        </div>
        <?php if ($timelineSlots): ?>
        <div class="day-slots-list" id="doc-day-planner">
            <?php foreach ($timelineSlots as $slot):
                $slotTimeStr = date('H:i', $slot['time']);
                $slotLabel   = date('g:i A', $slot['time']);
            ?>
            <?php if ($slot['busy']): ?>
            <!-- BUSY SLOT -->
            <div class="day-slot day-slot-busy">
                <div class="day-slot-time"><?= date('g:i', $slot['time']) ?><br><small style="font-weight:400;color:var(--muted);font-size:9px;"><?= date('A', $slot['time']) ?></small></div>
                <span class="day-slot-pill">Busy</span>
                <div class="day-slot-detail">
                    <?= htmlspecialchars($slot['appt']['patient']) ?>
                    · <?= $slot['appt']['duration'] ?> min
                    · Rm <?= htmlspecialchars($slot['appt']['room']) ?>
                    <?php if ($slot['appt']['risk'] !== 'Low'): ?>
                    <span class="risk-badge-<?= $slot['appt']['risk'] ?>"> ⚠<?= $slot['appt']['risk'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <!-- FREE SLOT — clickable to auto-fill datetime -->
            <div class="day-slot day-slot-free"
                 data-slot-time="<?= $todayDate ?>T<?= $slotTimeStr ?>"
                 onclick="fillScheduleDate(this.dataset.slotTime)"
                 title="Click to book at <?= $slotLabel ?>">
                <div class="day-slot-time"><?= date('g:i', $slot['time']) ?><br><small style="font-weight:400;font-size:9px;opacity:.7;"><?= date('A', $slot['time']) ?></small></div>
                <span class="day-slot-pill">Free</span>
                <div class="day-slot-detail">Available slot</div>
                <span class="day-slot-click-hint">↑ Use</span>
            </div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="day-empty">🗓 No working hours data for today.</div>
        <?php endif; ?>
    </div><!-- end planner inner box -->
    <div style="margin-top:14px;"><a class="text-link" href="dashboard.php">← Back to Dashboard</a></div>
</div><!-- end col1 day planner -->

<!-- COL 2: Schedule for Patient form -->
<div class="card" style="flex:0 0 280px;min-width:240px;max-width:300px;">
    <h3 style="margin-top:0;">Schedule for Patient</h3>
    <p style="font-size:12px;color:var(--muted);margin-top:-6px;margin-bottom:12px;">Click a <span style="color:#22d49e;font-weight:700;">free slot</span> on the left to auto-fill the time.</p>

    <?php if ($schedMsg): ?><p class="notice success"><?= $schedMsg ?></p><?php endif; ?>
    <?php if ($schedError): ?><p class="notice error"><?= htmlspecialchars($schedError) ?></p><?php endif; ?>

    <?php if ($myPatients): ?>
    <form method="POST" class="sched-form" style="margin-top:12px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <label for="sched_patient_id">Patient</label>
        <select name="sched_patient_id" id="sched_patient_id" class="sched-select" required>
            <option value="">Choose patient…</option>
            <?php foreach ($myPatients as $p): ?>
            <option value="<?= (int)$p['UserID'] ?>">
                <?= htmlspecialchars($p['Name']) ?>
                (<?= htmlspecialchars($p['PatientCode']) ?>)
                — <?= htmlspecialchars($p['RiskLevel']) ?> risk
            </option>
            <?php endforeach; ?>
        </select>

        <label for="sched_room_id">Clinic Room</label>
        <select name="sched_room_id" id="sched_room_id" class="sched-select" required>
            <option value="">Choose room…</option>
            <?php foreach ($rooms as $room): ?>
            <option value="<?= (int)$room['RoomID'] ?>">Room <?= htmlspecialchars($room['RoomNumber']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="sched_date">Date &amp; Time</label>
        <input type="datetime-local" id="sched_date" name="sched_date" class="sched-input"
               min="<?= $minDate ?>" required>

        <label>Duration</label>
        <div class="duration-presets">
            <?php foreach ([15, 30, 45, 60] as $d): ?>
                <button type="button" class="duration-btn <?= ($d === 30) ? 'active' : '' ?>"
                        data-sched="<?= $d ?>"><?= $d ?> min</button>
            <?php endforeach; ?>
        </div>
        <input type="hidden" id="sched_duration" name="sched_duration" value="30">

        <button type="submit" class="btn-schedule-submit">Book Appointment</button>
    </form>
    <?php else: ?>
        <div class="empty-state compact"><strong>No patients assigned yet.</strong><span>Patients will appear here once assigned to you.</span></div>
    <?php endif; ?>

    <div style="margin-top:16px;"><a class="text-link" href="dashboard.php">← Back to Dashboard</a></div>
</div><!-- end col2 schedule form -->

<!-- COL 3: Appointment list -->
<div class="card" style="flex:1;min-width:420px;">
    <?php if ($updateMsg): ?><p class="notice success"><?= $updateMsg ?></p><?php endif; ?>

    <!-- Summary metrics -->
    <div class="metric-row cols-4" style="margin:20px 0;">
        <div>
            <span>Upcoming</span>
            <strong><?= $countUpcoming ?></strong>
        </div>
        <div>
            <span>Today</span>
            <strong>
                <?php
                $today = array_filter($upcoming, fn($a) =>
                    date('Y-m-d', strtotime($a['AppointmentDate'])) === date('Y-m-d')
                );
                echo count($today);
                ?>
            </strong>
        </div>
        <div>
            <span>Past (last 30)</span>
            <strong><?= $countPast ?></strong>
        </div>
        <div>
            <span>Next appointment</span>
            <strong style="font-size:13px;">
                <?= $upcoming
                    ? htmlspecialchars(date('M j, g:i A', strtotime($upcoming[0]['AppointmentDate'])))
                    : '—'
                ?>
            </strong>
        </div>
    </div>

    <!-- Tabs -->
    <div class="appt-tabs">
        <button class="appt-tab active" onclick="switchTab('upcoming', this)" id="tab_upcoming">
            Upcoming <?php if ($countUpcoming > 0): ?><span style="margin-left:4px;font-size:11px;background:var(--teal);color:#0b1929;border-radius:999px;padding:1px 7px;"><?= $countUpcoming ?></span><?php endif; ?>
        </button>
        <button class="appt-tab" onclick="switchTab('past', this)" id="tab_past">
            Past History
        </button>
    </div>

    <!-- Upcoming -->
    <div class="appt-section active" id="sec_upcoming">
        <?php
        $statusColors = [
            'Scheduled' => 'background:rgba(15,200,228,.12);color:#0fc8e4;border:1px solid rgba(15,200,228,.3);',
            'Confirmed' => 'background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.3);',
            'Completed' => 'background:rgba(122,153,176,.10);color:#7a99b0;border:1px solid rgba(122,153,176,.25);',
            'Cancelled' => 'background:rgba(255,95,91,.10);color:#ff5f5b;border:1px solid rgba(255,95,91,.3);',
        ];
        ?>
        <?php if ($upcoming): ?>
        <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Date &amp; Time</th>
                <th>Duration</th>
                <th>Patient</th>
                <th>Patient Code</th>
                <th>Risk</th>
                <th>Status</th>
                <th style="min-width:190px;">Update Status</th>
                <th>Room</th>
            </tr></thead>
            <tbody>
            <?php foreach ($upcoming as $appt):
                $isToday = date('Y-m-d', strtotime($appt['AppointmentDate'])) === date('Y-m-d');
                $st = $appt['Status'];
                $ss = $statusColors[$st] ?? $statusColors['Scheduled'];
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars(date('M j, Y', strtotime($appt['AppointmentDate']))) ?></strong><br>
                    <small style="color:var(--muted)"><?= htmlspecialchars(date('g:i A', strtotime($appt['AppointmentDate']))) ?></small>
                    <?php if ($isToday): ?><br><span class="today-marker">⚡ Today</span><?php endif; ?>
                </td>
                <td><?= (int)$appt['DurationMinutes'] ?> min</td>
                <td><strong><?= htmlspecialchars($appt['PatientName']) ?></strong></td>
                <td><span class="code-badge"><?= htmlspecialchars($appt['PatientCode']) ?></span></td>
                <td><span class="risk-<?= htmlspecialchars($appt['RiskLevel']) ?>"><?= htmlspecialchars($appt['RiskLevel']) ?></span></td>
                <td>
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;<?= $ss ?>">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </td>
                <td style="white-space:nowrap;">
                    <form method="POST" class="inline-action-form" style="display:inline-flex;align-items:center;gap:6px;margin:0 !important;padding:0 !important;background:transparent !important;border:none !important;box-shadow:none !important;width:auto !important;">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                        <input type="hidden" name="appt_id" value="<?= (int)$appt['AppointmentID'] ?>">
                        <select name="new_status" class="status-dropdown" aria-label="Update status">
                            <?php foreach (['Scheduled','Confirmed','Completed','Cancelled'] as $opt): ?>
                            <option value="<?= $opt ?>" <?= $st === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn-save-status">Save</button>
                    </form>
                </td>
                <td>Room <?= htmlspecialchars($appt['RoomNumber']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <div class="empty-state compact">
                <strong>No upcoming appointments</strong>
                <span>No patient appointments are currently scheduled for you.</span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Past -->
    <div class="appt-section" id="sec_past">
        <?php if ($past): ?>
        <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Date &amp; Time</th>
                <th>Duration</th>
                <th>Patient</th>
                <th>Patient Code</th>
                <th>Risk Level</th>
                <th>Status</th>
                <th>Room</th>
            </tr></thead>
            <tbody>
            <?php foreach ($past as $appt):
                $st = $appt['Status'];
                $ss = $statusColors[$st] ?? $statusColors['Completed'];
            ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars(date('M j, Y', strtotime($appt['AppointmentDate']))) ?></strong><br>
                    <small style="color:var(--muted)"><?= htmlspecialchars(date('g:i A', strtotime($appt['AppointmentDate']))) ?></small>
                </td>
                <td><?= (int)$appt['DurationMinutes'] ?> min</td>
                <td><strong><?= htmlspecialchars($appt['PatientName']) ?></strong></td>
                <td><span class="code-badge"><?= htmlspecialchars($appt['PatientCode']) ?></span></td>
                <td><span class="risk-<?= htmlspecialchars($appt['RiskLevel']) ?>"><?= htmlspecialchars($appt['RiskLevel']) ?></span></td>
                <td>
                    <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:700;<?= $ss ?>">
                        <?= htmlspecialchars($st) ?>
                    </span>
                </td>
                <td>Room <?= htmlspecialchars($appt['RoomNumber']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?>
            <div class="empty-state compact">
                <strong>No past appointment history</strong>
                <span>Completed appointments will appear here.</span>
            </div>
        <?php endif; ?>
    </div>

    <div style="margin-top:24px;"><a class="text-link" href="dashboard.php">← Back to Dashboard</a></div>
</div><!-- end appointment list card -->
</div><!-- end flex wrapper -->

<script>
function switchTab(name, btn) {
    document.querySelectorAll('.appt-tab').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.appt-section').forEach(s => s.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('sec_' + name).classList.add('active');
}

// ── Free-slot click → auto-fill the datetime input ──────────────────────
function fillScheduleDate(slotTime) {
    const input = document.getElementById('sched_date');
    if (!input) return;

    input.value = slotTime;

    // Visual feedback: flash teal border on input
    input.style.borderColor = '#0fc8e4';
    input.style.boxShadow   = '0 0 0 3px rgba(15,200,228,.25)';
    setTimeout(() => {
        input.style.borderColor = '';
        input.style.boxShadow   = '';
    }, 1800);

    // Highlight selected free slot, un-highlight others
    document.querySelectorAll('.day-slot-free').forEach(el => {
        el.style.background   = '';
        el.style.borderColor  = '';
    });
    const clicked = document.querySelector(`.day-slot-free[data-slot-time="${slotTime}"]`);
    if (clicked) {
        clicked.style.background  = 'rgba(34,212,158,.22)';
        clicked.style.borderColor = 'rgba(34,212,158,.7)';
        // Update hint text
        const hint = clicked.querySelector('.day-slot-click-hint');
        if (hint) hint.textContent = '✓ Selected';
    }

    // Scroll form into view
    document.getElementById('sched_date')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// Sched duration buttons
const schedDurInput = document.getElementById('sched_duration');
if (schedDurInput) {
    document.querySelectorAll('[data-sched]').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('[data-sched]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            schedDurInput.value = btn.dataset.sched;
        });
    });
}

// Status cancellation confirmation
document.querySelectorAll('form').forEach(f => {
    f.addEventListener('submit', e => {
        const select = f.querySelector('select[name="new_status"]');
        if (select && select.value === 'Cancelled' && !f.dataset.confirmed) {
            e.preventDefault();
            if (window.openPremedConfirm) {
                window.openPremedConfirm({
                    title: 'Cancel Appointment?',
                    message: 'Are you sure you want to mark this patient consultation as Cancelled? This will remove it from active upcoming slots.',
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
