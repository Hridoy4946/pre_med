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
        $upd = $pdo->prepare("
            UPDATE APPOINTMENT SET Status = ?
            WHERE AppointmentID = ? AND DoctorID = ?
        ");
        $upd->execute([$newStatus, $apptId, $doctorId]);
        $updateMsg = $upd->rowCount() ? '✓ Status updated to ' . htmlspecialchars($newStatus) . '.' : 'No change made.';
    }
}

$upcomingStmt = $pdo->prepare("
    SELECT A.AppointmentID, A.AppointmentDate, A.DurationMinutes, A.Status,
           U.Name AS PatientName, P.PatientCode, P.RiskLevel, P.ProfileStatus,
           R.RoomNumber
    FROM APPOINTMENT A
    JOIN PATIENT P ON A.PatientID = P.UserID
    JOIN `USER` U ON P.UserID = U.UserID
    JOIN CLINIC_ROOM R ON A.RoomID = R.RoomID
    WHERE A.DoctorID = ? AND A.AppointmentDate >= NOW() AND A.Status != 'Cancelled'
    ORDER BY A.AppointmentDate ASC
");
$upcomingStmt->execute([$doctorId]);
$upcoming = $upcomingStmt->fetchAll();

$pastStmt = $pdo->prepare("
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
");
$pastStmt->execute([$doctorId]);
$past = $pastStmt->fetchAll();

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
        $patCheck = $pdo->prepare("SELECT 1 FROM PATIENT WHERE UserID = ? AND AssignedDoctorID = ?");
        $patCheck->execute([$schedPatientId, $doctorId]);
        if (!$patCheck->fetch()) {
            $schedError = 'You can only schedule appointments for your assigned patients.';
        } else {
            $conflict = $pdo->prepare("
                SELECT AppointmentID FROM APPOINTMENT
                WHERE (DoctorID = ? OR RoomID = ?)
                  AND Status NOT IN ('Cancelled')
                  AND AppointmentDate < DATE_ADD(?, INTERVAL ? MINUTE)
                  AND DATE_ADD(AppointmentDate, INTERVAL DurationMinutes MINUTE) > ?
                LIMIT 1
            ");
            $conflict->execute([$doctorId, $schedRoomId, $schedDate, $schedDuration, $schedDate]);
            if ($conflict->fetch()) {
                $schedError = '⚠ Conflict: that room or your schedule is already booked for this time slot.';
            } else {
                $pdo->prepare("INSERT INTO APPOINTMENT (AppointmentDate, DurationMinutes, Status, PatientID, DoctorID, RoomID) VALUES (?, ?, 'Confirmed', ?, ?, ?)")
                    ->execute([$schedDate, $schedDuration, $schedPatientId, $doctorId, $schedRoomId]);
                // Re-fetch upcoming
                $upcomingStmt->execute([$doctorId]);
                $upcoming = $upcomingStmt->fetchAll();
                $countUpcoming = count($upcoming);
                $schedMsg = '✓ Appointment scheduled and set to Confirmed for ' . date('D, M j Y \a\t g:i A', strtotime($schedDate)) . '.';
            }
        }
    }
}

// Fetch doctor's assigned patients for the scheduling dropdown
$assignedPatients = $pdo->prepare("
    SELECT P.UserID, U.Name, P.PatientCode, P.RiskLevel
    FROM PATIENT P JOIN `USER` U ON U.UserID = P.UserID
    WHERE P.AssignedDoctorID = ? ORDER BY U.Name
");
$assignedPatients->execute([$doctorId]);
$myPatients = $assignedPatients->fetchAll();

$rooms = $pdo->query("SELECT * FROM CLINIC_ROOM ORDER BY RoomNumber")->fetchAll();
$minDate = date('Y-m-d\TH:i', strtotime('+30 minutes'));
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
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div style="display:flex;flex-wrap:wrap;gap:20px;align-items:flex-start;padding:clamp(10px,4vw,36px) clamp(10px,3vw,24px);max-width:1400px;margin:0 auto;">

<!-- LEFT: Schedule Form -->
<div class="card" style="flex:1;min-width:300px;max-width:380px;">
    <div class="page-header" style="margin-bottom:16px;">
        <div class="page-header-left">
            <p class="eyebrow">Doctor Panel</p>
            <h2>My Appointments</h2>
            <p class="page-subtitle">Schedule for patients or update appointment status.</p>
        </div>
        <span class="role-pill role-doctor">Doctor</span>
    </div>

    <h3 style="margin-top:0;">Schedule for Patient</h3>
    <p style="font-size:13px;color:var(--muted);margin-top:-6px;">Book an appointment for one of your assigned patients.</p>

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

    <div style="margin-top:20px;"><a class="text-link" href="dashboard.php">← Back to Dashboard</a></div>
</div>

<!-- RIGHT: Appointment list -->
<div class="card" style="flex:3;min-width:340px;">
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
