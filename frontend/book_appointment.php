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
        db_execute($conn, "
            UPDATE APPOINTMENT SET Status = 'Cancelled'
            WHERE AppointmentID = ? AND PatientID = ? AND AppointmentDate >= NOW() AND Status != 'Cancelled'
        ", [$cancelId, $_SESSION['user_id']]);
        $msg = db_affected_rows($conn) ? '✓ Appointment cancelled successfully.' : 'Could not cancel that appointment.';
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
        $checkConflict = db_fetch_one($conn, "
            SELECT AppointmentID FROM APPOINTMENT
            WHERE (DoctorID = ? OR RoomID = ?)
              AND Status NOT IN ('Cancelled')
              AND AppointmentDate < DATE_ADD(?, INTERVAL ? MINUTE)
              AND DATE_ADD(AppointmentDate, INTERVAL DurationMinutes MINUTE) > ?
            LIMIT 1
        ", [$doctorId, $roomId, $apptDate, $duration, $apptDate]);
        if ($checkConflict) {
            $error = "⚠ Conflict detected: that doctor or room is already booked during this time slot. Please choose a different time.";
        } else {
            db_begin_transaction($conn);
            try {
                db_execute($conn, "INSERT INTO APPOINTMENT (AppointmentDate, DurationMinutes, Status, PatientID, DoctorID, RoomID) VALUES (?, ?, 'Scheduled', ?, ?, ?)", [$apptDate, $duration, $patientId, $doctorId, $roomId]);
                // Assign doctor if patient has none
                db_execute($conn, "UPDATE PATIENT SET AssignedDoctorID = ? WHERE UserID = ? AND AssignedDoctorID IS NULL", [$doctorId, $patientId]);
                db_commit($conn);
                $msg = "✓ Appointment booked for " . date('D, M j Y \a\t g:i A', strtotime($apptDate)) . " — Status: Scheduled.";
            } catch (Throwable $e) {
                db_rollback($conn);
                $error = "The appointment could not be scheduled. Please try a different slot.";
            }
        }
    }
}

$doctors = db_fetch_all($conn, "SELECT D.UserID, U.Name, Dep.DeptName FROM DOCTOR D JOIN `USER` U ON D.UserID = U.UserID JOIN DEPARTMENT Dep ON D.DeptID = Dep.DeptID ORDER BY U.Name");
$rooms   = db_fetch_all($conn, "SELECT * FROM CLINIC_ROOM ORDER BY RoomNumber");
$minDate = date('Y-m-d\TH:i', strtotime('+30 minutes'));

// Fetch this patient's appointments to show in the status panel
$myAppointments = db_fetch_all($conn, "
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
", [$_SESSION['user_id']]);

// Today's schedule for ALL doctors (so we can display per selected doctor)
$allDocTodayRaw = db_fetch_all($conn, "
    SELECT A.DoctorID, A.AppointmentDate, A.DurationMinutes, A.Status,
           PU.Name AS PatientName, P.PatientCode, R.RoomNumber
    FROM APPOINTMENT A
    JOIN PATIENT P ON A.PatientID = P.UserID
    JOIN `USER` PU ON P.UserID = PU.UserID
    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
    WHERE DATE(A.AppointmentDate) = CURDATE()
      AND A.Status != 'Cancelled'
    ORDER BY A.DoctorID, A.AppointmentDate ASC
");
// Group by DoctorID for JS consumption
$docTodayMap = [];
foreach ($allDocTodayRaw as $row) {
    $docTodayMap[$row['DoctorID']][] = $row;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Book Appointment — PreMed</title>
    <meta name="description" content="Book an appointment with a doctor. Conflict detection prevents double-booking of doctors and rooms.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
        /* ── Today's Doctor Schedule Box ── */
        .today-schedule-box {
            margin-top: 20px;
            border-radius: 12px;
            border: 1px solid rgba(15,200,228,.2);
            background: linear-gradient(135deg, rgba(15,200,228,.06) 0%, rgba(8,152,181,.04) 100%);
            overflow: hidden;
        }
        .today-schedule-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 11px 15px;
            border-bottom: 1px solid rgba(15,200,228,.15);
        }
        .today-schedule-header h4 {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: var(--teal);
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
            gap: 10px;
            padding: 9px 15px;
            border-bottom: 1px solid rgba(255,255,255,.04);
            transition: background .15s;
        }
        .today-sched-item:last-child { border-bottom: none; }
        .today-sched-item:hover { background: rgba(255,255,255,.03); }
        .today-sched-time {
            min-width: 48px;
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
        .today-sched-info { flex: 1; min-width: 0; }
        .today-sched-info strong { font-size: 12px; display: block; }
        .today-sched-meta {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-top: 2px;
            flex-wrap: wrap;
        }
        .today-sched-meta span { font-size: 11px; color: var(--muted); }
        .today-empty {
            padding: 16px 15px;
            text-align: center;
            color: var(--muted);
            font-size: 12px;
        }
        .code-badge {
            font-size: 10px; font-family: monospace;
            background: rgba(15,200,228,.12); color: var(--teal);
            border: 1px solid rgba(15,200,228,.25); border-radius: 4px;
            padding: 1px 6px; letter-spacing: .04em;
        }
        .duration-presets {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 6px;
            margin: 4px 0 14px;
        }
        .duration-btn {
            height: 36px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 6px;
            border: 1px solid var(--line);
            background: rgba(255,255,255,.05);
            color: var(--muted);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all .15s ease;
        }
        .duration-btn.active, .duration-btn:hover {
            background: rgba(15,200,228,.12);
            border-color: var(--teal);
            color: var(--teal);
        }

        /* ── Day Slot Timeline (free/busy) ── */
        .day-slots-list { padding: 6px 8px 8px; display: flex; flex-direction: column; gap: 4px; max-height: 260px; overflow-y: auto; }
        .day-slot {
            display: flex; align-items: center; gap: 8px;
            border-radius: 7px; padding: 6px 9px;
            transition: background .12s, transform .12s;
        }
        .day-slot-busy { background: rgba(15,200,228,.06); border: 1px solid rgba(15,200,228,.15); }
        .day-slot-free { background: rgba(34,212,158,.06); border: 1px solid rgba(34,212,158,.2); cursor: pointer; }
        .day-slot-free:hover { background: rgba(34,212,158,.14); border-color: rgba(34,212,158,.5); transform: translateX(2px); }
        .day-slot-time { min-width: 60px; font-size: 11px; font-weight: 700; text-align: center; line-height: 1.2; }
        .day-slot-busy .day-slot-time { color: var(--teal); }
        .day-slot-free .day-slot-time { color: #22d49e; }
        .day-slot-pill { font-size: 10px; font-weight: 700; padding: 1px 7px; border-radius: 999px; flex-shrink: 0; }
        .day-slot-busy .day-slot-pill { background: rgba(15,200,228,.15); color: var(--teal); }
        .day-slot-free .day-slot-pill { background: rgba(34,212,158,.15); color: #22d49e; }
        .day-slot-detail { flex: 1; font-size: 11px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .day-slot-free .day-slot-detail { color: rgba(34,212,158,.7); font-style: italic; }
        .day-slot-click-hint { font-size: 10px; color: rgba(34,212,158,.6); flex-shrink: 0; }
    </style>
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

    <!-- Today's Doctor Schedule Box (top of card) -->
    <div class="today-schedule-box" id="today-doc-schedule" style="margin-top:16px;margin-bottom:18px;">
        <div class="today-schedule-header">
            <h4>📅 Today's Doctor Schedule</h4>
            <span class="today-count-badge" id="today-doc-count" style="display:none;"></span>
        </div>
        <div id="today-doc-body">
            <div class="today-empty">Select a doctor below to see their schedule for today.</div>
        </div>
    </div>

    <form method="POST" style="margin-top:0;">
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
// Duration buttons
const durationInput = document.getElementById('duration_minutes');
document.querySelectorAll('.duration-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.duration-btn').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        durationInput.value = btn.dataset.minutes;
    });
});

// Today's doctor schedule — data embedded from PHP
const docScheduleMap = <?= json_encode($docTodayMap, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

const WORK_START = 8;   // 8 AM
const WORK_END   = 18;  // 6 PM
const SLOT_MIN   = 30;  // minutes per slot
const TODAY_STR  = '<?= date('Y-m-d') ?>';

function escHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

// Auto-fill the datetime input and highlight the chosen free slot
function fillBookingDate(slotTime) {
    const input = document.getElementById('appointment_date');
    if (!input) return;
    input.value = slotTime;

    // Flash feedback on input
    input.style.borderColor = '#22d49e';
    input.style.boxShadow   = '0 0 0 3px rgba(34,212,158,.25)';
    setTimeout(() => { input.style.borderColor = ''; input.style.boxShadow = ''; }, 1800);

    // Highlight the selected slot
    document.querySelectorAll('.day-slot-free').forEach(el => {
        el.style.background  = '';
        el.style.borderColor = '';
        const h = el.querySelector('.day-slot-click-hint');
        if (h) h.textContent = '↑ Use';
    });
    const clicked = document.querySelector(`.day-slot-free[data-slot-time="${slotTime}"]`);
    if (clicked) {
        clicked.style.background  = 'rgba(34,212,158,.22)';
        clicked.style.borderColor = 'rgba(34,212,158,.7)';
        const h = clicked.querySelector('.day-slot-click-hint');
        if (h) h.textContent = '✓ Selected';
    }
    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function buildTimeline(doctorId) {
    const body  = document.getElementById('today-doc-body');
    const badge = document.getElementById('today-doc-count');
    const appts = docScheduleMap[doctorId] || [];

    if (!doctorId) {
        badge.style.display = 'none';
        body.innerHTML = '<div class="today-empty">Select a doctor below to see their schedule for today.</div>';
        return;
    }

    // Build busy intervals from appointments
    const busyIntervals = appts.map(a => {
        const start = new Date(a.AppointmentDate.replace(' ', 'T')).getTime();
        const end   = start + a.DurationMinutes * 60000;
        return { start, end, appt: a };
    });

    // Generate 30-min slots for working hours
    const slots = [];
    let cursor = new Date(`${TODAY_STR}T${String(WORK_START).padStart(2,'0')}:00`).getTime();
    const dayEnd = new Date(`${TODAY_STR}T${String(WORK_END).padStart(2,'0')}:00`).getTime();

    while (cursor < dayEnd) {
        const slotEnd  = cursor + SLOT_MIN * 60000;
        let busyMatch  = null;
        for (const bi of busyIntervals) {
            if (cursor < bi.end && slotEnd > bi.start) { busyMatch = bi; break; }
        }
        // Only show slot if it's free OR it's the start of a busy appointment
        const isApptStart = busyMatch && Math.abs(cursor - busyMatch.start) < 60000;
        if (!busyMatch || isApptStart) {
            slots.push({ time: cursor, busy: !!busyMatch, appt: busyMatch?.appt });
        }
        cursor = slotEnd;
    }

    const freeCount = slots.filter(s => !s.busy).length;

    badge.textContent = appts.length + ' busy · ' + freeCount + ' free';
    badge.style.display = 'inline-block';
    badge.style.background = 'transparent';
    badge.style.border = 'none';
    badge.style.color = 'var(--muted)';
    badge.style.padding = '0';
    badge.style.fontSize = '11px';
    badge.style.borderRadius = '0';

    body.innerHTML = '<div class="day-slots-list">' + slots.map(slot => {
        const dt      = new Date(slot.time);
        const timeStr = dt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        const hhmm    = dt.toTimeString().slice(0,5); // "HH:MM"
        const slotKey = `${TODAY_STR}T${hhmm}`;

        if (slot.busy) {
            return `<div class="day-slot day-slot-busy">
                <div class="day-slot-time">${timeStr}</div>
                <span class="day-slot-pill">Busy</span>
                <div class="day-slot-detail">${escHtml(slot.appt.PatientName)} · ${slot.appt.DurationMinutes} min · Rm ${escHtml(slot.appt.RoomNumber)}</div>
            </div>`;
        } else {
            return `<div class="day-slot day-slot-free" data-slot-time="${slotKey}"
                        onclick="fillBookingDate(this.dataset.slotTime)"
                        title="Click to book at ${timeStr}">
                <div class="day-slot-time">${timeStr}</div>
                <span class="day-slot-pill">Free</span>
                <div class="day-slot-detail">Available slot</div>
                <span class="day-slot-click-hint">↑ Use</span>
            </div>`;
        }
    }).join('') + '</div>';
}

const docSelect = document.getElementById('doctor_id');
if (docSelect) {
    docSelect.addEventListener('change', () => buildTimeline(docSelect.value));
    if (docSelect.value) buildTimeline(docSelect.value);
}
</script>

<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>