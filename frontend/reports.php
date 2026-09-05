<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Staff', 'Doctor'], true)) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['role'];
$isStaff = $role === 'Staff';
$userId = (int)$_SESSION['user_id'];

// Track delivered report IDs in session for demo persistence
if (!isset($_SESSION['delivered_reports'])) {
    $_SESSION['delivered_reports'] = [];
}

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_delivery'])) {
    require_csrf();
    $reportKey = trim($_POST['report_key'] ?? '');
    if ($reportKey) {
        if (in_array($reportKey, $_SESSION['delivered_reports'], true)) {
            $_SESSION['delivered_reports'] = array_diff($_SESSION['delivered_reports'], [$reportKey]);
            $msg = 'Report status updated to Ready for Delivery.';
        } else {
            $_SESSION['delivered_reports'][] = $reportKey;
            $msg = 'Report successfully marked as Delivered to patient.';
        }
    }
}

// Fetch all Lab Test Reports
$labReports = db_fetch_all($conn, "
    SELECT LT.TestID, LT.Result, LT.Cost, V.VisitID, V.AdmissionDate,
           U.Name AS PatientName, P.PatientCode, P.BloodGroup, P.Gender, P.DateOfBirth,
           DocU.Name AS DoctorName, Dep.DeptName
    FROM LAB_TEST LT
    JOIN VISIT V ON LT.VisitID = V.VisitID
    JOIN PATIENT P ON V.PatientID = P.UserID
    JOIN `USER` U ON P.UserID = U.UserID
    LEFT JOIN `USER` DocU ON P.AssignedDoctorID = DocU.UserID
    LEFT JOIN DOCTOR Doc ON Doc.UserID = DocU.UserID
    LEFT JOIN DEPARTMENT Dep ON Doc.DeptID = Dep.DeptID
    ORDER BY V.AdmissionDate DESC
");

// Fetch Diagnosis Reports
$diagReports = db_fetch_all($conn, "
    SELECT D.DiagnosisID, D.DiagnosisText, D.CreatedAt, V.VisitID, V.AdmissionDate,
           U.Name AS PatientName, P.PatientCode, P.BloodGroup, P.Gender, P.DateOfBirth,
           DocU.Name AS DoctorName, Dep.DeptName
    FROM DIAGNOSIS D
    JOIN VISIT V ON D.VisitID = V.VisitID
    JOIN PATIENT P ON V.PatientID = P.UserID
    JOIN `USER` U ON P.UserID = U.UserID
    LEFT JOIN `USER` DocU ON P.AssignedDoctorID = DocU.UserID
    LEFT JOIN DOCTOR Doc ON Doc.UserID = DocU.UserID
    LEFT JOIN DEPARTMENT Dep ON Doc.DeptID = Dep.DeptID
    ORDER BY D.CreatedAt DESC
");

// Calculate total metrics
$totalLab = count($labReports);
$totalDiag = count($diagReports);
$totalAll = $totalLab + $totalDiag;

$deliveredCount = 0;
foreach ($labReports as $lr) {
    if (in_array('lab_' . $lr['TestID'], $_SESSION['delivered_reports'], true)) $deliveredCount++;
}
foreach ($diagReports as $dr) {
    if (in_array('diag_' . $dr['DiagnosisID'], $_SESSION['delivered_reports'], true)) $deliveredCount++;
}
$pendingDelivery = max(0, $totalAll - $deliveredCount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clinical &amp; Diagnostic Reports — PreMed</title>
    <meta name="description" content="View, print, and track clinical laboratory and diagnostic reports for patients.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
    .rep-container { max-width: 1240px; margin: clamp(10px, 4vw, 36px) auto; }
    .rep-card {
        background: rgba(11,25,41,.97);
        border: 1px solid var(--line);
        border-radius: var(--radius);
        padding: clamp(18px, 2.5vw, 28px);
        margin-bottom: 24px;
        box-shadow: var(--shadow);
    }
    .filter-tabs {
        display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;
    }
    .filter-tab {
        width: auto !important; margin: 0 !important;
        padding: 7px 16px; border-radius: 999px; font-size: 13px; font-weight: 600;
        cursor: pointer; border: 1px solid var(--line); background: var(--surface2); color: var(--muted);
        transition: all .15s;
    }
    .filter-tab.active, .filter-tab:hover {
        background: var(--teal-glow); border-color: var(--teal); color: var(--teal);
    }

    /* Print Document Overlay Modal */
    #reportModalOverlay {
        display: none; position: fixed; inset: 0; z-index: 9999;
        background: rgba(0,0,0,.75); backdrop-filter: blur(5px);
        align-items: center; justify-content: center;
        padding: 20px;
    }
    #reportModalOverlay.open { display: flex; }
    #printableReport {
        background: #ffffff; color: #111827; border-radius: 12px;
        padding: 36px 42px; width: 680px; max-width: 95vw; max-height: 90vh;
        overflow-y: auto; font-family: 'DM Sans', -apple-system, sans-serif;
        box-shadow: 0 25px 60px rgba(0,0,0,.7); position: relative;
    }
    #printableReport h1 { font-size: 20px; margin: 0; color: #0f2438; }
    #printableReport .rep-clinic-header {
        display: flex; justify-content: space-between; align-items: flex-start;
        padding-bottom: 18px; border-bottom: 2px solid #0fc8e4; margin-bottom: 20px;
    }
    #printableReport .rep-meta-grid {
        display: grid; grid-template-columns: 1fr 1fr; gap: 10px;
        background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 18px;
        font-size: 13px; margin-bottom: 22px;
    }
    #printableReport .rep-meta-grid div { display: flex; flex-direction: column; }
    #printableReport .rep-meta-grid span { color: #64748b; font-size: 11px; text-transform: uppercase; font-weight: 700; }
    #printableReport .rep-meta-grid strong { color: #0f172a; font-size: 14px; margin-top: 2px; }
    #printableReport .rep-result-box {
        background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px 20px;
        font-size: 14px; line-height: 1.6; margin-bottom: 24px; color: #1e293b;
    }
    #printableReport .rep-sign-row {
        display: flex; justify-content: space-between; align-items: flex-end;
        margin-top: 36px; padding-top: 20px; border-top: 1px dashed #cbd5e1;
    }
    #printableReport .rep-sign-col { text-align: center; }
    #printableReport .rep-sign-line { width: 180px; border-bottom: 1px solid #94a3b8; margin-bottom: 6px; }
    #printableReport .rep-modal-actions {
        display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px;
    }
    #printableReport .rep-modal-actions button {
        width: auto; margin: 0; padding: 9px 20px; font-size: 13px; font-weight: 700; border-radius: 6px; cursor: pointer;
    }

    @media print {
        body > *:not(#reportModalOverlay) { display: none !important; }
        #reportModalOverlay { position: static; background: none; display: block !important; padding: 0; }
        #printableReport { box-shadow: none; width: 100%; max-width: 100%; padding: 0; margin: 0; border: none; }
        .rep-modal-actions, .rep-modal-close { display: none !important; }
    }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>

<div class="rep-container">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Staff Panel</p>
            <h2>Clinical &amp; Diagnostic Reports</h2>
            <p class="page-subtitle">Track patient laboratory test results, diagnostic evaluations, and print verified patient reports.</p>
        </div>
        <span class="role-pill role-staff">Staff</span>
    </div>

    <?php if ($msg): ?><p class="notice success"><?= htmlspecialchars($msg) ?></p><?php endif; ?>

    <!-- Summary Metrics -->
    <div class="metric-row cols-4" style="margin:20px 0;">
        <div>
            <span>Total Reports On File</span>
            <strong><?= (int)$totalAll ?></strong>
        </div>
        <div>
            <span>Ready / Deliverable</span>
            <strong style="color:var(--teal)"><?= (int)$pendingDelivery ?></strong>
        </div>
        <div>
            <span>Delivered to Patients</span>
            <strong style="color:var(--green)"><?= (int)$deliveredCount ?></strong>
        </div>
        <div>
            <span>Laboratory Tests</span>
            <strong style="color:var(--violet)"><?= (int)$totalLab ?></strong>
        </div>
    </div>

    <div class="rep-card">
        <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:14px;margin-bottom:14px;">
            <div class="filter-tabs">
                <button type="button" class="filter-tab active" onclick="setTab('all', this)">All Reports (<?= $totalAll ?>)</button>
                <button type="button" class="filter-tab" onclick="setTab('lab', this)">🧪 Lab Test Results (<?= $totalLab ?>)</button>
                <button type="button" class="filter-tab" onclick="setTab('diag', this)">📋 Clinical Diagnoses (<?= $totalDiag ?>)</button>
            </div>
            <div>
                <input type="text" id="repSearch" placeholder="🔍 Search patient, doctor, test..." onkeyup="filterReports()" style="margin:0;padding:8px 14px;min-width:240px;font-size:13px;">
            </div>
        </div>

        <div class="table-wrap" style="margin:0;max-height:580px;overflow-y:auto;">
            <table>
                <thead>
                    <tr>
                        <th style="min-width:130px;">Report Type</th>
                        <th style="min-width:170px;">Patient</th>
                        <th style="min-width:160px;">Attending Doctor</th>
                        <th style="min-width:110px;">Date</th>
                        <th style="min-width:120px;">Delivery Status</th>
                        <th style="min-width:200px;text-align:right;">Actions</th>
                    </tr>
                </thead>
                <tbody id="repTableBody">
                <!-- 1. Lab Reports -->
                <?php foreach ($labReports as $lr): 
                    $key = 'lab_' . $lr['TestID'];
                    $isDelivered = in_array($key, $_SESSION['delivered_reports'], true);
                    $searchData = strtolower($lr['PatientName'] . ' ' . $lr['PatientCode'] . ' ' . ($lr['DoctorName'] ?? '') . ' ' . $lr['Result']);
                ?>
                <tr class="rep-row" data-type="lab" data-search="<?= htmlspecialchars($searchData) ?>">
                    <td>
                        <span class="role-pill role-doctor" style="font-size:11px;">🧪 Lab Test #<?= (int)$lr['TestID'] ?></span>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($lr['PatientName']) ?></strong><br>
                        <small style="color:var(--muted);font-family:monospace;"><?= htmlspecialchars($lr['PatientCode']) ?></small>
                    </td>
                    <td>
                        <?= htmlspecialchars(format_doctor_name($lr['DoctorName'] ?? '—')) ?><br>
                        <small style="color:var(--muted);"><?= htmlspecialchars($lr['DeptName'] ?? 'Clinic Staff') ?></small>
                    </td>
                    <td>
                        <?= htmlspecialchars(date('M j, Y', strtotime($lr['AdmissionDate']))) ?>
                    </td>
                    <td>
                        <?php if ($isDelivered): ?>
                            <span class="severity-badge severity-low" style="font-size:11px;">✓ Delivered</span>
                        <?php else: ?>
                            <span class="severity-badge severity-mid" style="font-size:11px;">📄 Ready to Deliver</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex;gap:6px;align-items:center;">
                            <form method="POST" class="inline-action-form" style="margin:0 !important;padding:0 !important;background:transparent !important;border:none !important;box-shadow:none !important;display:inline-flex !important;width:auto !important;border-radius:0 !important;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="toggle_delivery" value="1">
                                <input type="hidden" name="report_key" value="<?= htmlspecialchars($key) ?>">
                                <button type="submit" class="btn btn-sm btn-auto btn-ghost" title="Toggle delivery status" style="font-size:11px;padding:4px 9px;width:auto !important;">
                                    <?= $isDelivered ? '↩ Mark Pending' : '✓ Mark Delivered' ?>
                                </button>
                            </form>
                            <button type="button" class="btn btn-sm btn-auto btn-download btn-print-report"
                                data-title="Diagnostic Laboratory Test Report"
                                data-patname="<?= htmlspecialchars($lr['PatientName'], ENT_QUOTES, 'UTF-8') ?>"
                                data-patcode="<?= htmlspecialchars($lr['PatientCode'], ENT_QUOTES, 'UTF-8') ?>"
                                data-gender="<?= htmlspecialchars($lr['Gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>"
                                data-blood="<?= htmlspecialchars($lr['BloodGroup'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>"
                                data-doctor="<?= htmlspecialchars(format_doctor_name($lr['DoctorName'] ?? 'PreMed Clinic Staff'), ENT_QUOTES, 'UTF-8') ?>"
                                data-dept="<?= htmlspecialchars($lr['DeptName'] ?? 'Clinical Diagnostics', ENT_QUOTES, 'UTF-8') ?>"
                                data-date="<?= htmlspecialchars(date('F j, Y', strtotime($lr['AdmissionDate'])), ENT_QUOTES, 'UTF-8') ?>"
                                data-content="<?= htmlspecialchars($lr['Result'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-visit="<?= (int)$lr['VisitID'] ?>"
                                data-doccode="LAB-<?= (int)$lr['TestID'] ?>"
                                style="width:auto !important;">🖨 Print Report</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- 2. Diagnosis Reports -->
                <?php foreach ($diagReports as $dr): 
                    $key = 'diag_' . $dr['DiagnosisID'];
                    $isDelivered = in_array($key, $_SESSION['delivered_reports'], true);
                    $searchData = strtolower($dr['PatientName'] . ' ' . $dr['PatientCode'] . ' ' . ($dr['DoctorName'] ?? '') . ' ' . $dr['DiagnosisText']);
                ?>
                <tr class="rep-row" data-type="diag" data-search="<?= htmlspecialchars($searchData) ?>">
                    <td>
                        <span class="role-pill role-patient" style="font-size:11px;">📋 Diagnosis #<?= (int)$dr['DiagnosisID'] ?></span>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($dr['PatientName']) ?></strong><br>
                        <small style="color:var(--muted);font-family:monospace;"><?= htmlspecialchars($dr['PatientCode']) ?></small>
                    </td>
                    <td>
                        <?= htmlspecialchars(format_doctor_name($dr['DoctorName'] ?? '—')) ?><br>
                        <small style="color:var(--muted);"><?= htmlspecialchars($dr['DeptName'] ?? 'General Medicine') ?></small>
                    </td>
                    <td>
                        <?= htmlspecialchars(date('M j, Y', strtotime($dr['CreatedAt']))) ?>
                    </td>
                    <td>
                        <?php if ($isDelivered): ?>
                            <span class="severity-badge severity-low" style="font-size:11px;">✓ Delivered</span>
                        <?php else: ?>
                            <span class="severity-badge severity-mid" style="font-size:11px;">📄 Ready to Deliver</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right;">
                        <div style="display:inline-flex;gap:6px;align-items:center;">
                            <form method="POST" class="inline-action-form" style="margin:0 !important;padding:0 !important;background:transparent !important;border:none !important;box-shadow:none !important;display:inline-flex !important;width:auto !important;border-radius:0 !important;">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                <input type="hidden" name="toggle_delivery" value="1">
                                <input type="hidden" name="report_key" value="<?= htmlspecialchars($key) ?>">
                                <button type="submit" class="btn btn-sm btn-auto btn-ghost" title="Toggle delivery status" style="font-size:11px;padding:4px 9px;width:auto !important;">
                                    <?= $isDelivered ? '↩ Mark Pending' : '✓ Mark Delivered' ?>
                                </button>
                            </form>
                            <button type="button" class="btn btn-sm btn-auto btn-download btn-print-report"
                                data-title="Official Clinical Diagnosis Summary"
                                data-patname="<?= htmlspecialchars($dr['PatientName'], ENT_QUOTES, 'UTF-8') ?>"
                                data-patcode="<?= htmlspecialchars($dr['PatientCode'], ENT_QUOTES, 'UTF-8') ?>"
                                data-gender="<?= htmlspecialchars($dr['Gender'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>"
                                data-blood="<?= htmlspecialchars($dr['BloodGroup'] ?? 'N/A', ENT_QUOTES, 'UTF-8') ?>"
                                data-doctor="<?= htmlspecialchars(format_doctor_name($dr['DoctorName'] ?? 'PreMed Clinic Staff'), ENT_QUOTES, 'UTF-8') ?>"
                                data-dept="<?= htmlspecialchars($dr['DeptName'] ?? 'Consultation & Diagnostics', ENT_QUOTES, 'UTF-8') ?>"
                                data-date="<?= htmlspecialchars(date('F j, Y', strtotime($dr['CreatedAt'])), ENT_QUOTES, 'UTF-8') ?>"
                                data-content="<?= htmlspecialchars($dr['DiagnosisText'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                data-visit="<?= (int)$dr['VisitID'] ?>"
                                data-doccode="DIAG-<?= (int)$dr['DiagnosisID'] ?>"
                                style="width:auto !important;">🖨 Print Report</button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Printable Report Modal -->
<div id="reportModalOverlay">
    <div id="printableReport">
        <button type="button" class="rep-modal-close" onclick="closeReportModal()" style="position:absolute;top:16px;right:20px;background:none;border:none;font-size:24px;color:#64748b;cursor:pointer;">&times;</button>
        
        <div class="rep-clinic-header">
            <div>
                <h1 style="color:#0f2438;display:flex;align-items:center;gap:6px;">
                    <span style="color:#0899b8;font-weight:900;">+</span> PreMed Care Medical Center
                </h1>
                <p style="margin:4px 0 0;font-size:12px;color:#64748b;">Department of Clinical Pathology &amp; Patient Health Records</p>
                <p style="margin:2px 0 0;font-size:11px;color:#94a3b8;">12 Hospital Lane, Dhaka · Contact: +880 1700-000000 · premed.care</p>
            </div>
            <div style="text-align:right;">
                <span id="repDocCode" style="font-family:monospace;font-size:13px;font-weight:700;color:#0899b8;"></span><br>
                <span style="font-size:11px;color:#64748b;">OFFICIAL RECORD</span>
            </div>
        </div>

        <h3 id="repTitle" style="color:#0f172a;margin:0 0 14px;font-size:17px;border-left:3px solid #0899b8;padding-left:10px;"></h3>

        <div class="rep-meta-grid">
            <div><span>Patient Name</span><strong id="repPatName"></strong></div>
            <div><span>Patient Code</span><strong id="repPatCode" style="font-family:monospace;"></strong></div>
            <div><span>Attending Physician</span><strong id="repDoctor"></strong></div>
            <div><span>Department</span><strong id="repDept"></strong></div>
            <div><span>Date of Record</span><strong id="repDate"></strong></div>
            <div><span>Blood Group / Gender</span><strong id="repBloodGender"></strong></div>
        </div>

        <h4 style="margin:0 0 8px;font-size:13px;color:#334155;text-transform:uppercase;letter-spacing:.04em;">Clinical Findings &amp; Test Observations:</h4>
        <div class="rep-result-box" id="repContent"></div>

        <div style="font-size:11px;color:#64748b;background:#f1f5f9;padding:8px 12px;border-radius:6px;margin-bottom:20px;">
            <strong>Notice:</strong> This document represents an authenticated extract from the PreMed Electronic Health Records (EHR) database. Results should be interpreted by a qualified medical practitioner.
        </div>

        <div class="rep-sign-row">
            <div class="rep-sign-col">
                <div class="rep-sign-line"></div>
                <small style="color:#64748b;font-size:11px;font-weight:600;">Lab Technician / Reporting Officer</small>
            </div>
            <div class="rep-sign-col">
                <div class="rep-sign-line"></div>
                <small style="color:#64748b;font-size:11px;font-weight:600;">Verified By Attending Physician</small>
            </div>
        </div>

        <div class="rep-modal-actions">
            <button type="button" class="btn btn-ghost" onclick="closeReportModal()" style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;">Close</button>
            <button type="button" class="btn" onclick="window.print()" style="background:#0f2438;color:#ffffff;">🖨 Print Report Slip</button>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer_nav.php'; ?>

<script>
var currentTab = 'all';

function setTab(tab, btn) {
    currentTab = tab;
    document.querySelectorAll('.filter-tab').forEach(function(b) { b.classList.remove('active'); });
    btn.classList.add('active');
    filterReports();
}

function filterReports() {
    var q = document.getElementById('repSearch').value.toLowerCase().trim();
    var rows = document.querySelectorAll('.rep-row');
    rows.forEach(function(row) {
        var type = row.dataset.type;
        var search = row.dataset.search || '';
        var matchesTab = (currentTab === 'all' || currentTab === type);
        var matchesSearch = (!q || search.includes(q));
        row.style.display = (matchesTab && matchesSearch) ? '' : 'none';
    });
}

function openReportModal(title, patName, patCode, gender, blood, doctor, dept, date, content, visitId, docCode) {
    document.getElementById('repTitle').textContent = title;
    document.getElementById('repPatName').textContent = patName;
    document.getElementById('repPatCode').textContent = patCode;
    document.getElementById('repBloodGender').textContent = (blood || 'N/A') + ' · ' + (gender || 'N/A');
    document.getElementById('repDoctor').textContent = doctor;
    document.getElementById('repDept').textContent = dept;
    document.getElementById('repDate').textContent = date;
    document.getElementById('repContent').textContent = content;
    document.getElementById('repDocCode').textContent = docCode + ' (Visit #' + visitId + ')';
    document.getElementById('reportModalOverlay').classList.add('open');
}

function closeReportModal() {
    document.getElementById('reportModalOverlay').classList.remove('open');
}

document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-print-report');
    if (!btn) return;
    openReportModal(
        btn.dataset.title || '',
        btn.dataset.patname || '',
        btn.dataset.patcode || '',
        btn.dataset.gender || 'N/A',
        btn.dataset.blood || 'N/A',
        btn.dataset.doctor || '',
        btn.dataset.dept || '',
        btn.dataset.date || '',
        btn.dataset.content || '',
        btn.dataset.visit || '',
        btn.dataset.doccode || ''
    );
});

document.getElementById('reportModalOverlay').addEventListener('click', function(e) {
    if (e.target === this) closeReportModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeReportModal();
});
</script>
</body>
</html>
