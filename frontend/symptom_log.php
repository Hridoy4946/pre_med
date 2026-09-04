<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Patient') {
    header('Location: login.php');
    exit();
}

$userId  = $_SESSION['user_id'];
$message = '';
$error   = '';

$symptomGroups = [
    'General / Systemic' => [
        'color' => 'amber',
        'items' => ['Fever', 'Fatigue', 'Body Ache', 'Chills', 'Sweating']
    ],
    'Respiratory' => [
        'color' => 'teal',
        'items' => ['Cough', 'Shortness of Breath', 'Runny Nose', 'Sore Throat', 'Wheezing']
    ],
    'Cardiovascular' => [
        'color' => 'coral',
        'items' => ['Chest Pain', 'Palpitations', 'Dizziness']
    ],
    'Neurological' => [
        'color' => 'violet',
        'items' => ['Headache', 'Severe Headache', 'Fainting', 'Blurred Vision']
    ],
    'Gastrointestinal' => [
        'color' => 'green',
        'items' => ['Nausea', 'Vomiting', 'Diarrhea', 'Stomach Pain']
    ],
];

// Helper to find category color by symptom name
$symptomColorMap = [];
$symptomOptions = [];
foreach ($symptomGroups as $cat => $data) {
    foreach ($data['items'] as $item) {
        $symptomOptions[] = $item;
        $symptomColorMap[$item] = $data['color'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $score        = filter_input(INPUT_POST, 'severity_score', FILTER_VALIDATE_INT);
    $symptomNames = array_values(array_unique(array_filter(array_map('trim', (array)($_POST['symptom_names'] ?? [])))));
    $symptomNote  = trim($_POST['notes'] ?? '');
    $logDateTime  = trim($_POST['log_datetime'] ?? '');
    $logTimestamp = DateTime::createFromFormat('Y-m-d\TH:i', $logDateTime);

    if (!$symptomNames || count(array_diff($symptomNames, $symptomOptions)) > 0) {
        $error = 'Choose at least one symptom tag before saving.';
    } elseif (!$logTimestamp || $logTimestamp > new DateTime()) {
        $error = 'Choose a valid date and time that is not in the future.';
    } elseif ($score === false || $score < 1 || $score > 10) {
        $error = 'Choose a severity score from 1 to 10.';
    } else {
        $stmt = $pdo->prepare('INSERT INTO SYMPTOM_LOG (PatientID, SymptomName, SymptomNote, SeverityScore, LoggedAt) VALUES (?, ?, ?, ?, ?)');
        foreach ($symptomNames as $symptomName) {
            $stmt->execute([$userId, $symptomName, $symptomNote, $score, $logTimestamp->format('Y-m-d H:i:s')]);
        }
        // Recalculate 3-day rolling average and risk
        $avgStmt = $pdo->prepare('SELECT AVG(SeverityScore) FROM SYMPTOM_LOG WHERE PatientID = ? AND LoggedAt >= DATE_SUB(NOW(), INTERVAL 3 DAY)');
        $avgStmt->execute([$userId]);
        $average = (float) $avgStmt->fetchColumn();
        $status  = $average >= 7 ? 'Requires Attention' : 'Stable';

        $riskStmt = $pdo->prepare("SELECT CASE
            WHEN DateOfBirth IS NOT NULL AND TIMESTAMPDIFF(YEAR, DateOfBirth, CURDATE()) >= 60
                 AND (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = ? AND SeverityScore > 8) >= 2 THEN 'High'
            WHEN (SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = ? AND SeverityScore >= 7) >= 2 THEN 'Medium'
            ELSE 'Low' END FROM PATIENT WHERE UserID = ?");
        $riskStmt->execute([$userId, $userId, $userId]);
        $risk = $riskStmt->fetchColumn() ?: 'Low';

        $pdo->prepare('UPDATE PATIENT SET ProfileStatus = ?, RiskLevel = ? WHERE UserID = ?')
            ->execute([$status, $risk, $userId]);

        if ($status === 'Requires Attention') {
            $message = '⚠ Your 3-day average severity is ' . number_format($average, 1) . '/10. Your profile has been flagged. Please contact your care team.';
        } else {
            $message = '✓ Symptom logged successfully. Your 3-day average is ' . number_format($average, 1) . '/10.';
        }
    }
}

$historyStmt = $pdo->prepare('SELECT COALESCE(SymptomName, \'General\') AS SymptomName, SymptomNote, SeverityScore, LoggedAt FROM SYMPTOM_LOG WHERE PatientID = ? ORDER BY LoggedAt DESC LIMIT 30');
$historyStmt->execute([$userId]);
$history = $historyStmt->fetchAll();

$todayStmt = $pdo->prepare('SELECT COUNT(*) FROM SYMPTOM_LOG WHERE PatientID = ? AND DATE(LoggedAt) = CURDATE()');
$todayStmt->execute([$userId]);
$loggedToday = (int) $todayStmt->fetchColumn() > 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Symptom Log — PreMed</title>
    <meta name="description" content="Log daily symptoms with severity scores and track your health progression over time.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
        .symptom-view-wrapper {
            width: min(100%, 1180px);
            margin: 0 auto 30px;
        }
        .symptom-grid-layout {
            display: grid;
            grid-template-columns: 1fr 1.35fr;
            gap: 18px;
            align-items: start;
        }
        .symptom-card-box {
            background: rgba(11,25,41,.97);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 20px 22px;
            box-shadow: 0 16px 40px rgba(0,0,0,.35);
        }
        .symptom-card-box h3 {
            margin: 0 0 2px;
            font-size: 17px;
            color: #f0f7ff;
        }
        .history-scroll-area {
            max-height: 560px;
            overflow-y: auto;
            padding-right: 6px;
            margin-top: 12px;
        }
        .history-scroll-area::-webkit-scrollbar { width: 5px; }
        .history-scroll-area::-webkit-scrollbar-thumb { background: var(--line); border-radius: 999px; }

        /* Categorized Groups Box with curved inner container */
        .symptom-categories-container {
            background: rgba(6,16,28,.65);
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 12px 14px;
            margin-top: 8px;
            margin-bottom: 12px;
            max-height: 220px;
            overflow-y: auto;
        }
        .symptom-categories-container::-webkit-scrollbar { width: 5px; }
        .symptom-categories-container::-webkit-scrollbar-thumb { background: var(--line); border-radius: 999px; }

        .symptom-category-group {
            margin-bottom: 10px;
        }
        .symptom-category-group:last-child {
            margin-bottom: 0;
        }
        .symptom-category-title {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
            margin-bottom: 6px;
            color: var(--muted);
        }
        .symptom-category-title .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            display: inline-block;
        }
        .symptom-tags-row {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        /* Base Curved Pill Tag */
        .curved-pill-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 12px;
            font-size: 12px;
            font-weight: 600;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: rgba(13,30,47,.85);
            color: #92b1c8;
            cursor: pointer;
            width: auto;
            margin: 0;
            transition: all 0.15s ease;
            user-select: none;
        }
        .curved-pill-tag:hover {
            transform: translateY(-1px);
            border-color: rgba(15,200,228,.4);
            color: #d8edf8;
        }

        /* Multi-Color Selected States per Category */
        .curved-pill-tag.color-amber.selected {
            border-color: var(--amber);
            background: rgba(244,184,64,.18);
            color: #ffd875;
            font-weight: 700;
            box-shadow: 0 0 10px rgba(244,184,64,.25);
        }
        .curved-pill-tag.color-teal.selected {
            border-color: var(--teal);
            background: rgba(15,200,228,.18);
            color: #7de8f8;
            font-weight: 700;
            box-shadow: 0 0 10px rgba(15,200,228,.25);
        }
        .curved-pill-tag.color-coral.selected {
            border-color: var(--coral);
            background: rgba(255,95,91,.18);
            color: #ffaaa2;
            font-weight: 700;
            box-shadow: 0 0 10px rgba(255,95,91,.25);
        }
        .curved-pill-tag.color-violet.selected {
            border-color: var(--violet);
            background: rgba(155,124,255,.18);
            color: #c4b3ff;
            font-weight: 700;
            box-shadow: 0 0 10px rgba(155,124,255,.25);
        }
        .curved-pill-tag.color-green.selected {
            border-color: var(--green);
            background: rgba(34,212,158,.18);
            color: #7df1c5;
            font-weight: 700;
            box-shadow: 0 0 10px rgba(34,212,158,.25);
        }

        /* History Category Badge Colors */
        .history-tag-pill {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
            border: 1px solid var(--line);
        }
        .history-tag-pill.color-amber  { background: rgba(244,184,64,.12);  border-color: rgba(244,184,64,.3);  color: #ffd875; }
        .history-tag-pill.color-teal   { background: rgba(15,200,228,.12);  border-color: rgba(15,200,228,.3);  color: #7de8f8; }
        .history-tag-pill.color-coral  { background: rgba(255,95,91,.12);   border-color: rgba(255,95,91,.3);   color: #ffaaa2; }
        .history-tag-pill.color-violet { background: rgba(155,124,255,.12); border-color: rgba(155,124,255,.3); color: #c4b3ff; }
        .history-tag-pill.color-green  { background: rgba(34,212,158,.12);  border-color: rgba(34,212,158,.3);  color: #7df1c5; }

        .severity-display-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-top: 10px;
        }
        .severity-score-badge {
            font-size: 19px;
            font-weight: 800;
            font-family: 'Plus Jakarta Sans', sans-serif;
            color: var(--amber);
        }

        /* Severity slider track and thumb */
        input[type="range"].severity-range {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            width: 100%;
            height: 10px;
            margin: 12px 0 6px;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-radius: 999px;
            background: linear-gradient(to right, var(--slider-color, var(--amber)) 0%, var(--slider-color, var(--amber)) var(--score, 44.4%), #152d42 var(--score, 44.4%), #152d42 100%) !important;
            outline: none;
            cursor: pointer;
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.04) !important;
            overflow: visible;
        }
        input[type="range"].severity-range:focus {
            outline: none;
            border-color: var(--teal);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.6), 0 0 0 2px rgba(15,200,228,0.35) !important;
        }
        input[type="range"].severity-range::-webkit-slider-runnable-track {
            -webkit-appearance: none;
            height: 10px;
            border-radius: 999px;
            background: transparent;
            border: none;
        }
        input[type="range"].severity-range::-webkit-slider-thumb {
            -webkit-appearance: none;
            appearance: none;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            border: 3px solid #ffffff;
            background: var(--slider-color, var(--amber));
            cursor: pointer;
            box-shadow: 0 0 12px var(--slider-glow, rgba(244,184,64,.5)), 0 2px 6px rgba(0,0,0,.6);
            transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.1s ease;
            margin-top: -6px;
        }
        input[type="range"].severity-range::-webkit-slider-thumb:hover {
            transform: scale(1.15);
        }
        input[type="range"].severity-range::-moz-range-track {
            height: 10px;
            border-radius: 999px;
            background: #152d42;
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: inset 0 2px 4px rgba(0,0,0,0.6);
        }
        input[type="range"].severity-range::-moz-range-progress {
            height: 10px;
            border-radius: 999px;
            background: var(--slider-color, var(--amber));
        }
        input[type="range"].severity-range::-moz-range-thumb {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 3px solid #ffffff;
            background: var(--slider-color, var(--amber));
            cursor: pointer;
            box-shadow: 0 0 12px var(--slider-glow, rgba(244,184,64,.5)), 0 2px 6px rgba(0,0,0,.6);
            transition: background 0.15s ease, box-shadow 0.15s ease;
        }

        @media (max-width: 860px) {
            .symptom-grid-layout {
                grid-template-columns: 1fr;
            }
            .history-scroll-area {
                max-height: 360px;
            }
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<main class="symptom-view-wrapper">

    <!-- Header Section -->
    <div class="page-header" style="margin-bottom:16px;">
        <div class="page-header-left">
            <p class="eyebrow">Daily Health Check-in</p>
            <h2>Symptom Progression Tracker</h2>
            <p class="page-subtitle">Log your daily symptoms and severity score to monitor rolling averages and recovery trends.</p>
        </div>
        <a class="btn btn-ghost btn-sm btn-auto" href="dashboard.php">← Back to Dashboard</a>
    </div>

    <?php if ($message): ?>
        <p class="notice <?= str_contains($message, '⚠') ? 'error' : 'success' ?>"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
        <p class="notice error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <!-- Two-Panel Grid -->
    <div class="symptom-grid-layout">

        <!-- LEFT PANEL: Past Reports -->
        <section class="symptom-card-box">
            <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:10px;border-bottom:1px solid var(--line);">
                <div>
                    <p class="eyebrow" style="margin:0 0 2px;">Logged Records</p>
                    <h3>Past Symptom History</h3>
                </div>
                <span class="role-pill role-patient"><?= count($history) ?> entries</span>
            </div>

            <?php if ($history): ?>
                <div class="history-scroll-area">
                    <?php foreach ($history as $entry):
                        $score = (int)$entry['SeverityScore'];
                        $badgeClass = $score >= 7 ? 'severity-high' : ($score >= 4 ? 'severity-mid' : 'severity-low');
                        $badgeLabel = $score >= 7 ? 'High' : ($score >= 4 ? 'Moderate' : 'Low');
                        $sName = $entry['SymptomName'];
                        $colorClass = $symptomColorMap[$sName] ?? 'teal';
                    ?>
                    <article class="past-log-card" style="margin-bottom:10px;border-radius:10px;">
                        <div class="past-log-head">
                            <strong style="color:#e8f4fb;font-size:14px;"><?= htmlspecialchars(date('M j, Y', strtotime($entry['LoggedAt']))) ?></strong>
                            <span class="severity-badge <?= $badgeClass ?>">
                                <?= $score ?>/10 · <?= $badgeLabel ?>
                            </span>
                        </div>
                        <small style="color:var(--muted);"><?= htmlspecialchars(date('g:i A', strtotime($entry['LoggedAt']))) ?></small>
                        <div class="past-symptom-tags" style="margin-top:6px;">
                            <span class="history-tag-pill color-<?= $colorClass ?>">
                                <?= htmlspecialchars($sName) ?>
                            </span>
                        </div>
                        <?php if (!empty($entry['SymptomNote'])): ?>
                            <small style="display:block;margin-top:6px;color:#8da9be;font-size:12px;font-style:italic;">
                                "<?= htmlspecialchars($entry['SymptomNote']) ?>"
                            </small>
                        <?php endif; ?>
                    </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state compact" style="margin-top:20px;">
                    <strong>No past reports yet</strong>
                    <span>Your logged symptoms will appear here to show your historical progression.</span>
                </div>
            <?php endif; ?>
        </section>

        <!-- RIGHT PANEL: New Symptom Entry Form -->
        <section class="symptom-card-box">
            <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:10px;border-bottom:1px solid var(--line);">
                <div>
                    <p class="eyebrow" style="margin:0 0 2px;">Daily Check-in</p>
                    <h3>Log Today's Symptoms</h3>
                </div>
                <span class="role-pill role-patient"><?= date('M j, Y') ?></span>
            </div>

            <form method="POST" id="symptom_form" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <!-- Date & Time Row -->
                <div style="margin-bottom:10px;">
                    <label for="log_datetime" style="font-size:11px;">Date &amp; Time of Symptoms</label>
                    <input type="datetime-local" id="log_datetime" name="log_datetime"
                           value="<?= date('Y-m-d\TH:i') ?>"
                           max="<?= date('Y-m-d\TH:i') ?>" required style="margin:4px 0 0;padding:9px 12px;">
                </div>

                <!-- Categorized Curved Pill Tags Section -->
                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;margin-bottom:2px;">
                    <label style="margin-bottom:0;font-size:11px;">Choose Symptoms (Select all that apply)</label>
                    <span class="selected-count" id="selected_count" style="display:none;font-size:11px;padding:2px 8px;">0 selected</span>
                </div>

                <div class="symptom-categories-container">
                    <?php 
                    $categoryColorDots = [
                        'amber'  => '#ffd875',
                        'teal'   => '#0fc8e4',
                        'coral'  => '#ff5f5b',
                        'violet' => '#9b7cff',
                        'green'  => '#22d49e',
                    ];
                    foreach ($symptomGroups as $groupName => $groupData): 
                        $color = $groupData['color'];
                        $dotColor = $categoryColorDots[$color] ?? '#0fc8e4';
                    ?>
                        <div class="symptom-category-group">
                            <div class="symptom-category-title">
                                <span class="dot" style="background:<?= $dotColor ?>;box-shadow:0 0 6px <?= $dotColor ?>;"></span>
                                <?= htmlspecialchars($groupName) ?>
                            </div>
                            <div class="symptom-tags-row">
                                <?php foreach ($groupData['items'] as $option): ?>
                                    <button type="button" 
                                            class="curved-pill-tag color-<?= $color ?>" 
                                            data-symptom="<?= htmlspecialchars($option) ?>">
                                        <?= htmlspecialchars($option) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="selected_symptoms"></div>

                <!-- Severity Slider -->
                <div class="severity-display-row">
                    <label style="margin:0;font-size:12px;">Severity Score</label>
                    <span class="severity-score-badge" id="score_value">5 / 10 · Moderate</span>
                </div>
                <input class="severity-range" type="range" id="severity_score" name="severity_score"
                       min="1" max="10" value="5" step="1">
                <div class="range-labels" style="margin-bottom:12px;">
                    <span style="color:#7df1c5;">1-3: Mild</span>
                    <span style="color:#ffd875;">4-6: Moderate</span>
                    <span style="color:#ffaaa2;">7-10: Severe Alert</span>
                </div>

                <!-- Notes Textarea -->
                <label for="notes" style="font-size:11px;">Notes &amp; Observations (Optional)</label>
                <textarea id="notes" name="notes" class="symptom-notes" rows="2"
                          style="min-height:50px;margin-top:3px;margin-bottom:12px;padding:8px 10px;"
                          placeholder="Triggers, specific locations, duration..."></textarea>

                <?php if ($loggedToday): ?>
                    <p style="margin:0 0 10px;font-size:12px;color:var(--muted);background:rgba(15,200,228,.06);padding:7px 10px;border-radius:6px;border-left:3px solid var(--teal);">
                        ℹ You have already logged symptoms today. Adding another entry updates your 3-day rolling average.
                    </p>
                <?php endif; ?>

                <button type="submit" name="log_symptom" style="padding:12px;font-size:14px;border-radius:8px;">
                    ✓ Save Symptom Report
                </button>
            </form>
        </section>

    </div>
</main>

<script>
const slider        = document.getElementById('severity_score');
const scoreValue    = document.getElementById('score_value');
const selectedCount = document.getElementById('selected_count');
const selectedSymptomsDiv = document.getElementById('selected_symptoms');

function updateScore() {
    const v = parseInt(slider.value);
    const label = v >= 7 ? 'Severe Alert' : (v >= 4 ? 'Moderate' : 'Mild');
    const color = v >= 7 ? '#ff5f5b' : (v >= 4 ? '#f4b840' : '#22d49e');
    const glow  = v >= 7 ? 'rgba(255,95,91,0.5)' : (v >= 4 ? 'rgba(244,184,64,0.5)' : 'rgba(34,212,158,0.5)');
    
    scoreValue.textContent = `${v} / 10 · ${label}`;
    scoreValue.style.color = color;

    // Accurate calculation: (v - min) / (max - min) * 100
    const pct = ((v - 1) / (10 - 1)) * 100;
    slider.style.setProperty('--score', pct.toFixed(2) + '%');
    slider.style.setProperty('--slider-color', color);
    slider.style.setProperty('--slider-glow', glow);
}

function syncSelectedSymptoms() {
    const selected = [...document.querySelectorAll('.curved-pill-tag.selected')];
    selectedSymptomsDiv.replaceChildren(...selected.map(tag => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'symptom_names[]';
        input.value = tag.dataset.symptom;
        return input;
    }));
    const n = selected.length;
    if (n > 0) {
        selectedCount.style.display = 'inline-flex';
        selectedCount.textContent   = n + ' selected';
    } else {
        selectedCount.style.display = 'none';
    }
}

slider.addEventListener('input', updateScore);

// Tag selection with multi-color support
document.querySelectorAll('.curved-pill-tag').forEach(btn => {
    btn.addEventListener('click', () => {
        btn.classList.toggle('selected');
        syncSelectedSymptoms();
    });
});

const symptomForm = document.getElementById('symptom_form');
let isSeverityConfirmed = false;

symptomForm.addEventListener('submit', e => {
    syncSelectedSymptoms();
    if (!document.querySelector('.curved-pill-tag.selected')) {
        e.preventDefault();
        if (window.openPremedConfirm) {
            window.openPremedConfirm({
                title: 'No Symptom Tag Selected',
                message: 'Please select at least one symptom tag category before saving your daily report.',
                confirmText: 'Understood',
                confirmType: 'primary',
                onConfirm: () => {}
            });
        } else {
            alert('Please select at least one symptom tag before saving.');
        }
        return;
    }

    const score = parseInt(slider.value);
    if (score >= 7 && !isSeverityConfirmed) {
        e.preventDefault();
        if (window.openPremedConfirm) {
            window.openPremedConfirm({
                title: 'Severe Symptom Alert (' + score + '/10)',
                message: 'You have rated this symptom report with a high severity score (' + score + '/10). Submitting will trigger an urgent clinical alert for your assigned doctor and care team. Do you wish to proceed?',
                confirmText: 'Submit Severe Alert',
                confirmType: 'warning',
                onConfirm: () => {
                    isSeverityConfirmed = true;
                    symptomForm.submit();
                }
            });
        }
    }
});

updateScore();
</script>
</body>
</html>
