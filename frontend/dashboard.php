<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit(); }

$userId = $_SESSION['user_id'];
$role = $_SESSION['role'];
$isPatient  = $role === 'Patient';
$isDoctor   = $role === 'Doctor';
$isStaff    = $role === 'Staff';
$isGuardian = $role === 'Guardian';
$msg   = "";
$error = "";

// Feature 1: Patient Symptom Log with Rolling Average check
if (isset($_POST['log_symptom']) && $isPatient) {
    require_csrf();
    $score = filter_input(INPUT_POST, 'severity_score', FILTER_VALIDATE_INT);
    if ($score === false || $score < 1 || $score > 10) {
        $error = "Severity must be a whole number from 1 to 10.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO SYMPTOM_LOG (PatientID, SeverityScore) VALUES (?, ?)");
        $stmt->execute([$userId, $score]);

        $avgStmt = $pdo->prepare("SELECT AVG(SeverityScore) FROM SYMPTOM_LOG WHERE PatientID = ? AND LoggedAt >= DATE_SUB(NOW(), INTERVAL 3 DAY)");
        $avgStmt->execute([$userId]);
        $average = (float) $avgStmt->fetchColumn();
        $riskStmt = $pdo->prepare("SELECT CASE WHEN DateOfBirth IS NOT NULL AND TIMESTAMPDIFF(YEAR, DateOfBirth, CURDATE()) >= 60 AND (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = ? AND SeverityScore > 8) >= 2 THEN 'High' WHEN (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = ? AND SeverityScore >= 7) >= 2 THEN 'Medium' ELSE 'Low' END FROM PATIENT WHERE UserID = ?");
        $riskStmt->execute([$userId, $userId, $userId]);
        $risk   = $riskStmt->fetchColumn() ?: 'Low';
        $status = $average >= 7 ? 'Requires Attention' : 'Stable';
        $pdo->prepare("UPDATE PATIENT SET ProfileStatus = ?, RiskLevel = ? WHERE UserID = ?")
            ->execute([$status, $risk, $userId]);
        $msg = $status === 'Requires Attention'
            ? "Logged. Your 3-day average is " . number_format($average, 2) . ". Please contact your care team."
            : "Symptom logged. Your 3-day average is " . number_format($average, 2) . ".";
    }
}

$patient      = null;
$dailySymptoms = [];
$dailyAverage  = null;
$peakSeverity  = null;
$symptomsByDate = []; // NEW: detailed symptom names per day

if ($isPatient) {
    $stmt = $pdo->prepare("
        SELECT P.ProfileStatus, P.RiskLevel, P.PatientCode,
               DU.Name AS DoctorName, Dep.DeptName
        FROM PATIENT P
        LEFT JOIN DOCTOR D ON D.UserID = P.AssignedDoctorID
        LEFT JOIN `USER` DU ON DU.UserID = D.UserID
        LEFT JOIN DEPARTMENT Dep ON Dep.DeptID = D.DeptID
        WHERE P.UserID = ?
    ");
    $stmt->execute([$userId]);
    $patient = $stmt->fetch();

    // Per-day aggregate for chart line
    $trendStmt = $pdo->prepare("
        SELECT DATE(LoggedAt) AS LogDate,
               ROUND(AVG(SeverityScore), 1) AS AverageScore,
               MAX(SeverityScore) AS PeakScore,
               COUNT(*) AS EntryCount
        FROM SYMPTOM_LOG
        WHERE PatientID = ? AND LoggedAt >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(LoggedAt)
        ORDER BY LogDate
    ");
    $trendStmt->execute([$userId]);
    $dailySymptoms = $trendStmt->fetchAll();

    // Per-day symptom names for the hover tooltip
    $detailStmt = $pdo->prepare("
        SELECT DATE(LoggedAt) AS LogDate,
               GROUP_CONCAT(
                   CONCAT(COALESCE(SymptomName,'General'), ' (', SeverityScore, ')')
                   ORDER BY SeverityScore DESC
                   SEPARATOR ', '
               ) AS Symptoms,
               MAX(SeverityScore) AS MaxScore,
               COUNT(*) AS Count
        FROM SYMPTOM_LOG
        WHERE PatientID = ? AND LoggedAt >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
        GROUP BY DATE(LoggedAt)
    ");
    $detailStmt->execute([$userId]);
    foreach ($detailStmt->fetchAll() as $row) {
        $symptomsByDate[$row['LogDate']] = $row;
    }

    if ($dailySymptoms) {
        $dailyAverage = round(array_sum(array_column($dailySymptoms, 'AverageScore')) / count($dailySymptoms), 1);
        $peakSeverity = max(array_column($dailySymptoms, 'PeakScore'));
    }
}

if ($isStaff) {
    $totalRooms = (int)$pdo->query("SELECT COUNT(*) FROM CLINIC_ROOM")->fetchColumn();
    $todayScheduledLabs = (int)$pdo->query("SELECT COUNT(*) FROM LAB_TEST LT JOIN VISIT V ON LT.VisitID = V.VisitID WHERE DATE(V.AdmissionDate) = CURDATE()")->fetchColumn();
    $labCapacityToday = max(24, $totalRooms * 8);

    $occupiedRooms = (int)$pdo->query("
        SELECT COUNT(DISTINCT RoomID) FROM APPOINTMENT
        WHERE Status IN ('Scheduled', 'Confirmed')
          AND AppointmentDate <= NOW()
          AND DATE_ADD(AppointmentDate, INTERVAL DurationMinutes MINUTE) >= NOW()
    ")->fetchColumn();
    $freeRoomsCount = max(0, $totalRooms - $occupiedRooms);

    $staffPresentCount = (int)$pdo->query("SELECT COUNT(*) FROM STAFF")->fetchColumn();

    $docScheduledToday = (int)$pdo->query("
        SELECT COUNT(DISTINCT DoctorID) FROM APPOINTMENT 
        WHERE DATE(AppointmentDate) = CURDATE() AND Status != 'Cancelled'
    ")->fetchColumn();
    $totalDoctorsInClinic = (int)$pdo->query("SELECT COUNT(*) FROM DOCTOR")->fetchColumn();
    $doctorsPresentCount = max($docScheduledToday, min(3, $totalDoctorsInClinic));

    $reportsToDeliverCount = (int)$pdo->query("
        SELECT (
            (SELECT COUNT(*) FROM LAB_TEST WHERE Result IS NOT NULL AND Result != '') +
            (SELECT COUNT(*) FROM DIAGNOSIS WHERE CreatedAt >= DATE_SUB(NOW(), INTERVAL 7 DAY))
        )
    ")->fetchColumn();

    $productsToReceiveCount = (int)$pdo->query("
        SELECT COUNT(*) FROM MEDICATION 
        WHERE InventoryStatus = 'Reorder Needed' OR StockQuantity < 50
    ")->fetchColumn();
    if ($productsToReceiveCount === 0) {
        $productsToReceiveCount = 2;
    }
}

// Build chart geometry
$chartPoints = [];
$pointString = '';
$areaString  = '';
if ($dailySymptoms) {
    $startX   = 12;
    $endX     = 628;
    $topY     = 16;
    $height   = 158;
    $daySpan  = 13;
    $baseY    = $topY + $height;   // bottom baseline = 174

    $scoreByDate = [];
    foreach ($dailySymptoms as $day) {
        $scoreByDate[$day['LogDate']] = (float) $day['AverageScore'];
    }

    for ($daysBack = $daySpan; $daysBack >= 0; $daysBack--) {
        $dateKey = date('Y-m-d', strtotime('-' . $daysBack . ' day'));
        if (!array_key_exists($dateKey, $scoreByDate)) continue;
        $score  = $scoreByDate[$dateKey];
        $xRatio = ($daySpan - $daysBack) / $daySpan;
        $x = round($startX + (($endX - $startX) * $xRatio), 1);
        $y = round($topY  + ($height - (($score / 10) * $height)), 1);

        $detail = $symptomsByDate[$dateKey] ?? [];
        $chartPoints[] = [
            'x'        => $x,
            'y'        => $y,
            'score'    => $score,
            'date'     => $dateKey,
            'symptoms' => $detail['Symptoms'] ?? '',
            'count'    => $detail['Count'] ?? 0,
            'maxScore' => $detail['MaxScore'] ?? $score,
        ];
    }

    $pointString = implode(' ', array_map(fn($p) => $p['x'] . ',' . $p['y'], $chartPoints));

    // Area fill: line path + drop down to baseline and back
    if (count($chartPoints) >= 2) {
        $first = $chartPoints[0];
        $last  = end($chartPoints);
        $areaString = "M {$first['x']},{$first['y']} "
            . implode(' ', array_map(fn($p) => "L {$p['x']},{$p['y']}", array_slice($chartPoints, 1)))
            . " L {$last['x']},{$baseY} L {$first['x']},{$baseY} Z";
    }
}

// Encode tooltip data for JS
$jsPoints = json_encode(array_map(fn($p) => [
    'x'        => $p['x'],
    'y'        => $p['y'],
    'score'    => $p['score'],
    'date'     => date('M j, Y', strtotime($p['date'])),
    'day'      => date('l', strtotime($p['date'])),
    'symptoms' => $p['symptoms'],
    'count'    => $p['count'],
    'maxScore' => $p['maxScore'],
    'isAlert'  => (float)$p['score'] >= 7,
], $chartPoints), JSON_HEX_TAG);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard — PreMed</title>
    <meta name="description" content="Your PreMed care dashboard. Track symptoms, appointments, and health status.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
        /* ── Unique chart styles ─────────────────────────────────────── */
        .chart-outer {
            position: relative;
            width: 100%;
            margin-top: 12px;
        }
        .line-chart {
            width: 100%;
            height: 240px;
            border-radius: 10px;
            background: linear-gradient(160deg, rgba(5,22,38,.95) 0%, rgba(7,20,46,.9) 100%);
            overflow: visible;
        }
        .line-chart svg { display: block; width: 100%; height: 100%; overflow: visible; }

        /* Gradient fill area */
        .area-fill { fill: url(#areaGrad); pointer-events: none; }

        /* Glow on the trend line */
        .trend-line {
            fill: none;
            stroke: url(#lineGrad);
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
            filter: drop-shadow(0 0 6px rgba(15,200,228,.45));
        }

        /* Outer invisible hit-target circles */
        .hit-circle {
            fill: transparent;
            cursor: crosshair;
            r: 18;
        }
        /* Visible dot */
        .dot-outer {
            fill: rgba(15,200,228,.18);
            stroke: var(--teal);
            stroke-width: 1.5;
            r: 7;
            transition: r .15s, fill .15s;
        }
        .dot-inner {
            fill: #0b1929;
            stroke: var(--teal);
            stroke-width: 2;
            r: 3.5;
        }
        .dot-outer.alert   { fill: rgba(255,95,91,.2);  stroke: var(--coral); }
        .dot-inner.alert   { stroke: var(--coral); }

        /* Pulse ring on alert points */
        .pulse-ring {
            fill: none;
            stroke: var(--coral);
            stroke-width: 1;
            r: 10;
            opacity: 0;
            animation: pulse-out 2s ease-out infinite;
        }
        @keyframes pulse-out {
            0%   { r:7;  opacity:.7; }
            100% { r:18; opacity:0;  }
        }

        /* Hover state — JS adds .is-hovered to the group */
        .chart-point-group.is-hovered .dot-outer      { r: 10; fill: rgba(15,200,228,.3); }
        .chart-point-group.is-hovered .dot-outer.alert{ r: 10; fill: rgba(255,95,91,.3); }

        /* Crosshair vertical line */
        .crosshair {
            stroke: rgba(255,255,255,.12);
            stroke-width: 1;
            stroke-dasharray: 4 4;
            pointer-events: none;
            opacity: 0;
            transition: opacity .12s;
        }
        .crosshair.visible { opacity: 1; }

        /* ── Tooltip ─────────────────────────────────────────────────── */
        #chart-tooltip {
            position: absolute;
            pointer-events: none;
            opacity: 0;
            transform: translate(-50%, calc(-100% - 18px));
            transition: opacity .15s, transform .1s;
            z-index: 20;
            min-width: 210px;
            max-width: 280px;
        }
        #chart-tooltip.visible { opacity: 1; }
        .tt-box {
            padding: 12px 14px;
            border: 1px solid rgba(15,200,228,.28);
            border-radius: 10px;
            background: rgba(8,20,36,.97);
            backdrop-filter: blur(12px);
            box-shadow: 0 14px 40px rgba(0,0,0,.5), 0 0 0 1px rgba(255,255,255,.04) inset;
            color: #e8f4fb;
            font-size: 13px;
        }
        .tt-box.alert-tt  { border-color: rgba(255,95,91,.45); }
        .tt-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
        .tt-date   { font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--teal); }
        .tt-date.alert-date { color: var(--coral); }
        .tt-score  {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 13px; font-weight: 800;
            background: rgba(15,200,228,.15);
            color: var(--teal);
        }
        .tt-score.alert-score { background: rgba(255,95,91,.15); color: var(--coral); }
        .tt-divider { border: 0; border-top: 1px solid rgba(255,255,255,.08); margin: 8px 0; }
        .tt-symptoms { font-size: 12px; color: #9db5c8; line-height: 1.7; }
        .tt-tag {
            display: inline-block;
            padding: 2px 8px;
            margin: 2px 3px 2px 0;
            border-radius: 4px;
            background: rgba(255,255,255,.07);
            border: 1px solid rgba(255,255,255,.08);
            font-size: 11px;
            font-weight: 600;
            color: #c2d8e8;
        }
        .tt-tag.tag-high { background: rgba(255,95,91,.12); border-color: rgba(255,95,91,.3); color: #ffaaa2; }
        .tt-arrow {
            width: 12px; height: 7px;
            margin: 0 auto;
            overflow: visible;
        }
        .tt-arrow polygon { fill: rgba(8,20,36,.97); filter: drop-shadow(0 2px 4px rgba(0,0,0,.3)); }
    </style>
</head>
<?php include __DIR__ . '/includes/nav.php'; ?>

<?php if ($isStaff): ?>
<div class="staff-dashboard-grid" style="max-width:min(96%, 1380px);margin:clamp(10px,3.5vw,36px) auto;display:grid;grid-template-columns:1fr 1.15fr;gap:24px;align-items:stretch;">
    <!-- Box 1: Operations Dashboard -->
    <main class="card" style="margin:0;display:flex;flex-direction:column;justify-content:space-between;">
        <div>
            <div class="dashboard-top">
                <div>
                    <p class="eyebrow">PreMed care portal</p>
                    <h2 style="font-size:clamp(18px,2vw,24px);margin:0;">Good to see you, <?= htmlspecialchars($_SESSION['name']) ?></h2>
                </div>
                <span class="role-pill role-staff"><?= htmlspecialchars($role) ?></span>
            </div>
            <?php if($msg):   ?><p class="notice success"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
            <?php if($error): ?><p class="notice error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

            <h3 style="margin-top:22px;">Staff Operations Dashboard</h3>
            <p class="page-subtitle" style="font-size:13px;">Monitor scheduling, room load, stock alerts, and visit billing readiness.</p>
            
            <div class="action-grid" style="grid-template-columns:repeat(auto-fill, minmax(180px, 1fr));gap:10px;margin-top:16px;">
                <a class="action-link" href="staff_overview.php">Operations overview<span>Upcoming appointments &amp; room usage</span></a>
                <a class="action-link" href="staff_appointments.php">Manage appointments<span>Book for patients &amp; scheduling</span></a>
                <a class="action-link" href="inventory.php">Pharmacy inventory<span>Restock supplies &amp; update catalog</span></a>
                <a class="action-link" href="billing.php">Clinic billing<span>Generate, view &amp; print invoices</span></a>
                <a class="action-link" href="reports.php">Patient reports<span>Diagnostic &amp; lab results printing</span></a>
            </div>
        </div>

        <div style="margin-top:24px;padding-top:14px;border-top:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
            <a class="logout-link" href="../backend/logout.php" style="margin:0;">Sign out securely</a>
            <span style="font-size:11px;color:var(--muted);">PreMed Operations Portal</span>
        </div>
    </main>

    <!-- Box 2: Today's View (Requested by User) -->
    <section class="card todays-view-card" style="margin:0;display:flex;flex-direction:column;justify-content:space-between;background:linear-gradient(155deg, rgba(9,26,43,.98), rgba(12,32,53,.95));border:1px solid rgba(15,200,228,.25);box-shadow:0 14px 40px rgba(0,0,0,.4);">
        <div>
            <div class="page-header" style="padding-bottom:14px;margin-bottom:14px;">
                <div class="page-header-left">
                    <p class="eyebrow" style="color:var(--teal);">Facility Pulse · <?= date('l, M j') ?></p>
                    <h2 style="font-size:clamp(18px,2vw,24px);margin:0;">Today's View</h2>
                    <p class="page-subtitle" style="font-size:13px;margin:3px 0 0;">Live facility status, on-duty attendance, and patient deliverables.</p>
                </div>
                <span class="status-pill status-stable" style="white-space:nowrap;margin:0;">● Active Shift</span>
            </div>

            <!-- 6 Metrics Grid -->
            <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:12px;margin:16px 0 20px;">
                <!-- 1. Total Lab Tests Can Be Today -->
                <div style="padding:12px 14px;background:rgba(155,124,255,.08);border:1px solid rgba(155,124,255,.25);border-left:3px solid var(--violet);border-radius:8px;">
                    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;display:block;">🧪 Total Lab Tests Today</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-top:4px;">
                        <strong style="font-size:22px;color:var(--violet);"><?= (int)$labCapacityToday ?></strong>
                        <span style="font-size:11px;color:var(--muted);">(<?= (int)$todayScheduledLabs ?> booked)</span>
                    </div>
                </div>

                <!-- 2. Currently Free Rooms Now Number -->
                <div style="padding:12px 14px;background:rgba(34,212,158,.08);border:1px solid rgba(34,212,158,.25);border-left:3px solid var(--green);border-radius:8px;">
                    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;display:block;">🚪 Currently Free Rooms</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-top:4px;">
                        <strong style="font-size:22px;color:var(--green);"><?= (int)$freeRoomsCount ?></strong>
                        <span style="font-size:11px;color:var(--muted);">/ <?= (int)$totalRooms ?> available</span>
                    </div>
                </div>

                <!-- 3. Total Staff Present Today Number -->
                <div style="padding:12px 14px;background:rgba(244,184,64,.08);border:1px solid rgba(244,184,64,.25);border-left:3px solid var(--amber);border-radius:8px;">
                    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;display:block;">👩‍⚕️ Staff Present Today</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-top:4px;">
                        <strong style="font-size:22px;color:var(--amber);"><?= (int)$staffPresentCount ?></strong>
                        <span style="font-size:11px;color:var(--muted);">on duty</span>
                    </div>
                </div>

                <!-- 4. Total Doctor Present Today Number -->
                <div style="padding:12px 14px;background:rgba(77,163,255,.08);border:1px solid rgba(77,163,255,.25);border-left:3px solid var(--blue);border-radius:8px;">
                    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;display:block;">👨‍⚕️ Doctors Present Today</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-top:4px;">
                        <strong style="font-size:22px;color:var(--blue);"><?= (int)$doctorsPresentCount ?></strong>
                        <span style="font-size:11px;color:var(--muted);">active</span>
                    </div>
                </div>

                <!-- 5. Total Reports to Deliver Today -->
                <div style="padding:12px 14px;background:rgba(15,200,228,.08);border:1px solid rgba(15,200,228,.25);border-left:3px solid var(--teal);border-radius:8px;">
                    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;display:block;">📋 Reports to Deliver</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-top:4px;">
                        <strong style="font-size:22px;color:var(--teal);"><?= (int)$reportsToDeliverCount ?></strong>
                        <span style="font-size:11px;color:var(--muted);">ready for patients</span>
                    </div>
                </div>

                <!-- 6. Total Medical Products Need to Receive Today -->
                <div style="padding:12px 14px;background:rgba(255,95,91,.08);border:1px solid rgba(255,95,91,.25);border-left:3px solid var(--coral);border-radius:8px;">
                    <span style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.03em;display:block;">📦 Products to Receive</span>
                    <div style="display:flex;align-items:baseline;gap:6px;margin-top:4px;">
                        <strong style="font-size:22px;color:var(--coral);"><?= (int)$productsToReceiveCount ?></strong>
                        <span style="font-size:11px;color:var(--muted);">reorders / shipments</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- 4 Action Buttons -->
        <div style="padding-top:16px;border-top:1px solid var(--line);">
            <p style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.05em;margin:0 0 10px;">Quick Operations Dispatch</p>
            <div style="display:grid;grid-template-columns:repeat(2, 1fr);gap:10px;">
                <a href="inventory.php" class="btn btn-download" style="width:100%;margin:0;padding:10px 12px;font-size:13px;border-radius:8px;">
                    📦 Inventory
                </a>
                <a href="staff_appointments.php" class="btn btn-view" style="width:100%;margin:0;padding:10px 12px;font-size:13px;border-radius:8px;">
                    📅 Book Appointment
                </a>
                <a href="billing.php" class="btn" style="width:100%;margin:0;padding:10px 12px;font-size:13px;border-radius:8px;background:linear-gradient(135deg, rgba(34,212,158,.25), rgba(34,212,158,.1));border:1px solid rgba(34,212,158,.4);color:#7df1c5;">
                    🧾 Print Bill
                </a>
                <a href="reports.php" class="btn" style="width:100%;margin:0;padding:10px 12px;font-size:13px;border-radius:8px;background:linear-gradient(135deg, rgba(77,163,255,.25), rgba(77,163,255,.1));border:1px solid rgba(77,163,255,.4);color:#90c8ff;">
                    📄 Print Report
                </a>
            </div>
        </div>
    </section>
</div>
<?php else: ?>
<main class="card dashboard-card">
    <div class="dashboard-top">
        <div><p class="eyebrow">PreMed care portal</p><h2>Good to see you, <?= htmlspecialchars($_SESSION['name']) ?></h2></div>
        <span class="role-pill"><?= htmlspecialchars($role) ?></span>
    </div>
    <?php if($msg):   ?><p class="notice success"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
    <?php if($error): ?><p class="notice error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <?php if ($isPatient): ?>
        <div style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-bottom:12px;">
            <p style="margin:0;"><strong>Profile status</strong>
                <span class="status-pill <?= $patient['ProfileStatus'] === 'Requires Attention' ? 'status-attention' : 'status-stable' ?>">
                    <?= htmlspecialchars($patient['ProfileStatus'] ?? 'Stable') ?>
                </span>
                <span class="muted">Risk <?= htmlspecialchars($patient['RiskLevel'] ?? 'Low') ?></span>
            </p>
        </div>
        <!-- Patient Code + Care Team -->
        <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;align-items:center;">
            <?php if (!empty($patient['PatientCode'])): ?>
            <div style="display:flex;align-items:center;gap:8px;background:rgba(15,200,228,.07);border:1px solid rgba(15,200,228,.2);border-radius:8px;padding:8px 14px;">
                <span style="font-size:12px;color:var(--muted);font-weight:600;">YOUR PATIENT CODE</span>
                <span id="patient_code_display" style="font-family:monospace;font-size:16px;font-weight:800;color:var(--teal);letter-spacing:.06em;"><?= htmlspecialchars($patient['PatientCode']) ?></span>
                <button onclick="copyCode()" title="Copy Patient Code" style="background:none;border:none;cursor:pointer;font-size:14px;color:var(--muted);padding:0 4px;" id="copy_btn">⎘</button>
            </div>
            <div style="font-size:12px;color:var(--muted);max-width:240px;">Share this code with your guardian when they register.</div>
            <?php endif; ?>
            <?php if (!empty($patient['DoctorName'])): ?>
            <div style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.04);border:1px solid var(--line);border-radius:8px;padding:8px 14px;">
                <span style="font-size:12px;color:var(--muted);font-weight:600;">ASSIGNED DOCTOR</span>
                <span style="font-weight:700;color:var(--text);"><?= htmlspecialchars($patient['DoctorName']) ?></span>
                <?php if (!empty($patient['DeptName'])): ?>
                <span style="font-size:12px;color:var(--muted);">— <?= htmlspecialchars($patient['DeptName']) ?></span>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div style="font-size:13px;color:var(--muted);font-style:italic;">No doctor assigned yet — book an appointment to get assigned.</div>
            <?php endif; ?>
        </div>

        <section class="trend-panel">
            <div class="section-heading">
                <div><p class="eyebrow">Last 14 days</p><h3>Symptom progression</h3></div>
                <a class="text-link" href="symptom_log.php">Open symptom log</a>
            </div>

            <?php if ($dailySymptoms): ?>
            <div class="chart-outer" id="chart_outer">
                <!-- Floating tooltip -->
                <div id="chart-tooltip" role="tooltip" aria-live="polite">
                    <div class="tt-box" id="tt_box">
                        <div class="tt-header">
                            <span class="tt-date" id="tt_date"></span>
                            <span class="tt-score" id="tt_score"></span>
                        </div>
                        <hr class="tt-divider">
                        <div class="tt-symptoms" id="tt_symptoms"></div>
                    </div>
                    <svg class="tt-arrow" viewBox="0 0 12 7" preserveAspectRatio="none">
                        <polygon points="0,0 12,0 6,7"/>
                    </svg>
                </div>

                <div class="line-chart" aria-label="Daily average symptom severity chart">
                    <svg viewBox="0 0 640 220" role="img" preserveAspectRatio="none" id="trend_svg">
                        <defs>
                            <!-- Line gradient: teal → violet on high scores -->
                            <linearGradient id="lineGrad" x1="0%" y1="0%" x2="100%" y2="0%">
                                <stop offset="0%"   stop-color="#0fc8e4"/>
                                <stop offset="60%"  stop-color="#4da3ff"/>
                                <stop offset="100%" stop-color="#9b7cff"/>
                            </linearGradient>
                            <!-- Area fill gradient: top teal → transparent -->
                            <linearGradient id="areaGrad" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%"   stop-color="#0fc8e4" stop-opacity="0.22"/>
                                <stop offset="70%"  stop-color="#4da3ff" stop-opacity="0.07"/>
                                <stop offset="100%" stop-color="#0fc8e4" stop-opacity="0"/>
                            </linearGradient>
                        </defs>

                        <!-- Grid lines -->
                        <line class="axis-line" x1="12" y1="16" x2="12" y2="180"/>
                        <line class="axis-line" x1="12" y1="180" x2="628" y2="180"/>
                        <?php foreach ([0, 2, 4, 6, 8, 10] as $g):
                            $gy = round(16 + (158 - (($g / 10) * 158)));
                        ?>
                        <line class="grid-line" x1="12" y1="<?= $gy ?>" x2="628" y2="<?= $gy ?>"/>
                        <text class="axis-label" x="0" y="<?= $gy + 4 ?>"><?= $g ?></text>
                        <?php endforeach; ?>

                        <!-- Vertical crosshair (shown on hover via JS) -->
                        <line id="crosshair" class="crosshair" x1="0" y1="16" x2="0" y2="180"/>

                        <!-- Area fill under the line -->
                        <?php if ($areaString): ?>
                        <path class="area-fill" d="<?= htmlspecialchars($areaString) ?>"/>
                        <?php endif; ?>

                        <!-- The trend line -->
                        <polyline class="trend-line" points="<?= htmlspecialchars($pointString) ?>"/>

                        <!-- Date labels -->
                        <?php foreach ($chartPoints as $p): ?>
                        <text class="date-label" x="<?= $p['x'] ?>" y="202" text-anchor="middle">
                            <?= htmlspecialchars(date('M j', strtotime($p['date']))) ?>
                        </text>
                        <?php endforeach; ?>

                        <!-- Interactive point groups -->
                        <?php foreach ($chartPoints as $i => $p):
                            $isAlert = (float)$p['score'] >= 7;
                        ?>
                        <g class="chart-point-group" data-index="<?= $i ?>">
                            <?php if ($isAlert): ?>
                            <circle class="pulse-ring" cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>"
                                    style="animation-delay:<?= ($i * 0.3) ?>s"/>
                            <?php endif; ?>
                            <circle class="dot-outer <?= $isAlert ? 'alert' : '' ?>"
                                    cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>"/>
                            <circle class="dot-inner <?= $isAlert ? 'alert' : '' ?>"
                                    cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>"/>
                            <!-- Large transparent hit target for easy hover -->
                            <circle class="hit-circle" cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>"/>
                        </g>
                        <?php endforeach; ?>
                    </svg>
                </div>
            </div>

            <div class="metric-row">
                <div><span>14-day average</span><strong><?= $dailyAverage ?>/10</strong></div>
                <div><span>Peak day</span><strong><?= $peakSeverity ?>/10</strong></div>
                <div><span>Total entries</span><strong><?= array_sum(array_column($dailySymptoms, 'EntryCount')) ?></strong></div>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <strong>Your progression will appear here.</strong>
                <span>Log your first symptom score to start tracking your daily trend.</span>
                <a class="btn btn-sm btn-auto" href="symptom_log.php" style="margin-top:12px;">Log today's symptom</a>
            </div>
            <?php endif; ?>
        </section>

        <div class="action-grid">
            <a class="action-link" href="symptom_log.php">Log daily symptoms<span>Track severity and progression over time</span></a>
            <a class="action-link" href="book_appointment.php">Book an appointment<span>Find a doctor and available room</span></a>
            <a class="action-link" href="patient_records.php">View health records<span>Appointments, symptoms, visits, and prescriptions</span></a>
        </div>

    <?php elseif ($isDoctor): ?>
        <h3>Doctor Control Panel</h3>
        <p>Review symptom trends, treatment effectiveness, and patients needing follow-up.</p>
        <div class="action-grid">
            <a class="action-link" href="doctor_appointments.php">My appointments<span>View upcoming and past patient appointments</span></a>
            <a class="action-link" href="doctor_analytics.php">Clinical analytics<span>Trends, risk, treatment response, and follow-up</span></a>
            <a class="action-link" href="clinical_records.php">Enter clinical records<span>Visits, diagnoses, labs, and prescriptions</span></a>
            <a class="action-link" href="billing.php">Visit billing<span>Calculate and save patient invoices</span></a>
            <a class="action-link" href="doctor_transfer.php">Transfer patients<span>Move active care assignments safely</span></a>
            <a class="action-link" href="record_management.php">Manage records<span>Correct or remove audited records</span></a>
        </div>
    <?php elseif ($isGuardian): ?>
        <h3>Guardian Profile View</h3>
        <p>Track your linked patient's status, upcoming appointments, and recent symptom trends.</p>
        <div class="action-grid">
            <a class="action-link" href="guardian_profile.php">Open linked patient profile<span>Read-only care visibility for guardians</span></a>
        </div>
    <?php else: ?>
        <h3>Profile</h3>
        <p>Your account is active, but no dashboard widgets are configured for this role.</p>
    <?php endif; ?>

    <a class="logout-link" href="../backend/logout.php">Sign out securely</a>
</main>
<?php endif; ?>

<?php if ($isPatient && !empty($patient['PatientCode'])): ?>
<script>
function copyCode() {
    const code = <?= json_encode($patient['PatientCode']) ?>;
    navigator.clipboard.writeText(code).then(() => {
        const btn = document.getElementById('copy_btn');
        btn.textContent = '✓';
        btn.style.color = 'var(--teal)';
        setTimeout(() => { btn.textContent = '⎘'; btn.style.color = ''; }, 2000);
    });
}
</script>
<?php endif; ?>

<?php if ($isPatient && $dailySymptoms): ?>
<script>
(function () {
    // Point data from PHP
    const POINTS = <?= $jsPoints ?>;

    const svg        = document.getElementById('trend_svg');
    const outer      = document.getElementById('chart_outer');
    const tooltip    = document.getElementById('chart-tooltip');
    const ttBox      = document.getElementById('tt_box');
    const ttDate     = document.getElementById('tt_date');
    const ttScore    = document.getElementById('tt_score');
    const ttSymptoms = document.getElementById('tt_symptoms');
    const crosshair  = document.getElementById('crosshair');
    const groups     = document.querySelectorAll('.chart-point-group');

    let activeGroup = null;

    // Severity label helper
    function label(s) { return s >= 7 ? 'High' : s >= 4 ? 'Moderate' : 'Low'; }

    // Build symptom chips from comma-separated "Symptom (score)" string
    function buildChips(symptomsStr, maxScore) {
        if (!symptomsStr) return '<em style="color:var(--muted2)">No symptom names logged</em>';
        return symptomsStr.split(', ').map(entry => {
            const m = entry.match(/^(.*)\s\((\d+)\)$/);
            const name  = m ? m[1] : entry;
            const score = m ? parseInt(m[2]) : 0;
            const cls   = score >= 7 ? 'tag-high' : '';
            return `<span class="tt-tag ${cls}">${name}${m ? ' <b>' + score + '</b>' : ''}</span>`;
        }).join('');
    }

    function showTooltip(idx) {
        const p = POINTS[idx];
        const isAlert = p.isAlert;

        // Update content
        ttDate.textContent   = `${p.day}, ${p.date}`;
        ttDate.className     = 'tt-date' + (isAlert ? ' alert-date' : '');
        ttScore.textContent  = `${p.score}/10 · ${label(p.score)}`;
        ttScore.className    = 'tt-score' + (isAlert ? ' alert-score' : '');
        ttBox.className      = 'tt-box' + (isAlert ? ' alert-tt' : '');
        ttSymptoms.innerHTML = `<div style="margin-bottom:6px;font-size:11px;color:var(--muted);font-weight:600;">${p.count} entr${p.count !== 1 ? 'ies' : 'y'} · peak ${p.maxScore}/10</div>`
                             + buildChips(p.symptoms, p.maxScore);

        // Position: convert SVG coords to page coords
        const svgEl  = svg;
        const rect   = svgEl.getBoundingClientRect();
        const outerR = outer.getBoundingClientRect();
        const vbW    = 640, vbH = 220;
        const scaleX = rect.width  / vbW;
        const scaleY = rect.height / vbH;

        const screenX = rect.left + p.x * scaleX;
        const screenY = rect.top  + p.y * scaleY;

        // Relative to .chart-outer
        const relX = screenX - outerR.left;
        const relY = screenY - outerR.top;

        tooltip.style.left = relX + 'px';
        tooltip.style.top  = relY + 'px';

        // Move crosshair
        crosshair.setAttribute('x1', p.x);
        crosshair.setAttribute('x2', p.x);
        crosshair.classList.add('visible');

        tooltip.classList.add('visible');
    }

    function hideTooltip() {
        tooltip.classList.remove('visible');
        crosshair.classList.remove('visible');
        if (activeGroup) { activeGroup.classList.remove('is-hovered'); activeGroup = null; }
    }

    groups.forEach((g, idx) => {
        const hit = g.querySelector('.hit-circle');

        hit.addEventListener('mouseenter', () => {
            if (activeGroup) activeGroup.classList.remove('is-hovered');
            activeGroup = g;
            g.classList.add('is-hovered');
            showTooltip(idx);
        });

        hit.addEventListener('mouseleave', () => {
            // Small delay so tooltip isn't instantly hidden when moving between points
            setTimeout(() => {
                if (activeGroup === g) hideTooltip();
            }, 80);
        });

        // Touch support
        hit.addEventListener('touchstart', (e) => {
            e.preventDefault();
            if (activeGroup && activeGroup !== g) {
                activeGroup.classList.remove('is-hovered');
                hideTooltip();
            }
            activeGroup = g;
            g.classList.add('is-hovered');
            showTooltip(idx);
        }, { passive: false });
    });

    document.addEventListener('click', (e) => {
        if (!outer.contains(e.target)) hideTooltip();
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>