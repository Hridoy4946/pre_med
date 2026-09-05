<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['Doctor', 'Staff'], true)) {
    header('Location: login.php');
    exit();
}

$role = $_SESSION['role'];
$isStaff = $role === 'Staff';
$isDoctor = $role === 'Doctor';

$visitId = filter_input(INPUT_GET, 'visit_id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $visitId = filter_input(INPUT_POST, 'visit_id', FILTER_VALIDATE_INT);
}
$generatedMessage = '';
$billingError = '';

if ($isStaff) {
    $visits = db_fetch_all($conn, "
        SELECT V.VisitID, V.AdmissionDate, U.Name AS PatientName, DocU.Name AS DoctorName
        FROM VISIT V
        JOIN PATIENT P ON V.PatientID = P.UserID
        JOIN `USER` U ON P.UserID = U.UserID
        LEFT JOIN `USER` DocU ON P.AssignedDoctorID = DocU.UserID
        ORDER BY V.AdmissionDate DESC
        LIMIT 80
    ");
} else {
    $visits = db_fetch_all($conn, "
        SELECT V.VisitID, V.AdmissionDate, U.Name AS PatientName, '' AS DoctorName
        FROM VISIT V
        JOIN PATIENT P ON V.PatientID = P.UserID
        JOIN `USER` U ON P.UserID = U.UserID
        WHERE P.AssignedDoctorID = ?
        ORDER BY V.AdmissionDate DESC
    ", [$_SESSION['user_id']]);
}

$invoice = null;
if ($visitId) {
    $sql = "
        SELECT V.VisitID, U.Name AS PatientName, P.PatientCode, DocU.Name AS DoctorName,
               COALESCE((SELECT SUM(Cost) FROM CONSULTATION WHERE VisitID = V.VisitID), 0) AS ConsultationTotal,
               COALESCE((SELECT SUM(Cost) FROM LAB_TEST WHERE VisitID = V.VisitID), 0) AS LabTotal,
               COALESCE((SELECT SUM(PI.Quantity * M.UnitCost) FROM PRESCRIPTION_ITEM PI JOIN PRESCRIPTION PR ON PI.PrescriptionID = PR.PrescriptionID JOIN MEDICATION M ON PI.MedicationID = M.MedicationID WHERE PR.VisitID = V.VisitID), 0) AS MedicationTotal,
               COALESCE((SELECT CoveragePercentage FROM PATIENT_INSURANCE WHERE PatientID = V.PatientID ORDER BY InsuranceID DESC LIMIT 1), 0) AS CoveragePercentage
        FROM VISIT V
        JOIN PATIENT P ON V.PatientID = P.UserID
        JOIN `USER` U ON V.PatientID = U.UserID
        LEFT JOIN `USER` DocU ON P.AssignedDoctorID = DocU.UserID
        WHERE V.VisitID = ? " . ($isDoctor ? "AND P.AssignedDoctorID = ?" : "");
    
    $params = $isDoctor ? [$visitId, $_SESSION['user_id']] : [$visitId];
    $invoice = db_fetch_one($conn, $sql, $params);
}
$total = $invoice ? (float) $invoice['ConsultationTotal'] + (float) $invoice['LabTotal'] + (float) $invoice['MedicationTotal'] : 0;
$outOfPocket = $total * (1 - ((float) ($invoice['CoveragePercentage'] ?? 0) / 100));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($visitId && $invoice) {
        $inTx = false;
        try {
            db_begin_transaction($conn);
            $inTx = true;
            db_execute($conn, 'SET @app_user_id = ' . (int) $_SESSION['user_id']);
            db_execute($conn, "INSERT INTO INVOICE (`Date`, OutOfPocket, VisitID) VALUES (NOW(), ?, ?) ON DUPLICATE KEY UPDATE `Date` = VALUES(`Date`), OutOfPocket = VALUES(OutOfPocket)", [$outOfPocket, $visitId]);
            db_commit($conn);
            $inTx = false;
            $generatedMessage = 'Invoice generated and saved successfully.';
        } catch (Throwable $exception) {
            if ($inTx) {
                db_rollback($conn);
            }
            $billingError = 'Invoice could not be saved.';
        }
    }
}

// Fetch saved invoices
if ($isStaff) {
    $savedInvoices = db_fetch_all($conn, "
        SELECT I.InvoiceID, I.Date, I.OutOfPocket, V.VisitID, U.Name AS PatientName, P.PatientCode, DocU.Name AS DoctorName
        FROM INVOICE I
        JOIN VISIT V ON I.VisitID = V.VisitID
        JOIN PATIENT P ON V.PatientID = P.UserID
        JOIN `USER` U ON P.UserID = U.UserID
        LEFT JOIN `USER` DocU ON P.AssignedDoctorID = DocU.UserID
        ORDER BY I.Date DESC
    ");
} else {
    $savedInvoices = db_fetch_all($conn, "
        SELECT I.InvoiceID, I.Date, I.OutOfPocket, V.VisitID, U.Name AS PatientName, P.PatientCode, DocU.Name AS DoctorName
        FROM INVOICE I
        JOIN VISIT V ON I.VisitID = V.VisitID
        JOIN PATIENT P ON V.PatientID = P.UserID
        JOIN `USER` U ON P.UserID = U.UserID
        LEFT JOIN `USER` DocU ON P.AssignedDoctorID = DocU.UserID
        WHERE P.AssignedDoctorID = ?
        ORDER BY I.Date DESC
    ", [$_SESSION['user_id']]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Visit Billing — PreMed</title>
    <meta name="description" content="Calculate and generate patient invoices with insurance coverage applied.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
    .inv-box {
        border: 1px solid var(--line); border-radius: 10px;
        margin: 20px 0 10px; overflow: hidden; background: var(--surface);
    }
    .inv-box-inner {
        max-height: 265px; overflow-y: auto; overflow-x: auto;
        scrollbar-width: thin; scrollbar-color: var(--teal) transparent;
    }
    .inv-box-inner::-webkit-scrollbar { width: 6px; height: 6px; }
    .inv-box-inner::-webkit-scrollbar-track { background: transparent; }
    .inv-box-inner::-webkit-scrollbar-thumb { background: var(--teal); border-radius: 3px; }
    .inv-box table { margin: 0; border-radius: 0; border: none; }
    .inv-box table thead th {
        position: sticky; top: 0; z-index: 2;
        background: var(--card); border-bottom: 1px solid var(--line);
    }
    .btn-print-inv {
        display: inline-flex; align-items: center; gap: 5px;
        padding: 5px 12px; border-radius: 6px; font-size: 12px; font-weight: 700;
        background: rgba(15,200,228,.12); color: var(--teal);
        border: 1px solid rgba(15,200,228,.3);
        cursor: pointer; transition: background .15s; white-space: nowrap;
    }
    .btn-print-inv:hover { background: rgba(15,200,228,.24); }
    /* Print slip modal */
    #printSlipOverlay {
        display: none; position: fixed; inset: 0; z-index: 9999;
        background: rgba(0,0,0,.65); backdrop-filter: blur(4px);
        align-items: center; justify-content: center;
    }
    #printSlipOverlay.open { display: flex; }
    #printSlip {
        background: #fff; color: #111; border-radius: 10px;
        padding: 32px 36px; width: 480px; max-width: 95vw;
        font-family: 'Inter', sans-serif; position: relative;
    }
    #printSlip h2 { margin: 0 0 4px; font-size: 20px; color: #0a1828; }
    #printSlip .slip-sub { color: #555; font-size: 13px; margin-bottom: 20px; }
    #printSlip table { width: 100%; border-collapse: collapse; font-size: 14px; }
    #printSlip td { padding: 7px 0; border-bottom: 1px solid #eee; }
    #printSlip td:last-child { text-align: right; font-weight: 700; }
    #printSlip .slip-total td { border-bottom: none; font-size: 16px; padding-top: 14px; }
    #printSlip .slip-close {
        position: absolute; top: 14px; right: 16px;
        background: none; border: none; font-size: 20px; cursor: pointer; color: #666;
    }
    #printSlip .slip-actions { display: flex; gap: 10px; margin-top: 22px; justify-content: flex-end; }
    #printSlip .slip-actions button {
        padding: 8px 20px; border-radius: 6px; font-size: 13px; font-weight: 700;
        cursor: pointer; border: none;
    }
    #printSlip .slip-print-btn { background: #0a1828; color: #fff; }
    #printSlip .slip-cancel-btn { background: #eee; color: #333; }
    @media print {
        body > *:not(#printSlip) { display: none !important; }
        #printSlipOverlay { position: static; background: none; display: block !important; }
        #printSlip { box-shadow: none; width: 100%; padding: 20px; }
        .slip-close, .slip-actions { display: none !important; }
    }
    </style>
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="card" style="max-width:860px;margin:clamp(10px,4vw,36px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow"><?= $isStaff ? 'Staff Panel' : 'Doctor Panel' ?></p>
            <h2><?= $isStaff ? 'Clinic Billing & Invoices' : 'Dynamic Visit Billing' ?></h2>
            <p class="page-subtitle"><?= $isStaff ? 'Calculate, generate, review, and print patient invoices with insurance settlement.' : 'Select a visit to calculate costs across consultations, labs, and prescriptions, then apply insurance.' ?></p>
        </div>
        <span class="role-pill <?= $isStaff ? 'role-staff' : 'role-doctor' ?>"><?= htmlspecialchars($_SESSION['role']) ?></span>
    </div>
    <?php if ($generatedMessage): ?><p class="notice success"><?= htmlspecialchars($generatedMessage) ?></p><?php endif; ?>
    <?php if ($billingError):     ?><p class="notice error"><?= htmlspecialchars($billingError) ?></p><?php endif; ?>

    <form method="GET" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;width:auto;background:transparent;border:0;padding:0;box-shadow:none;margin-top:14px;">
        <div style="flex:1;min-width:200px;">
            <label for="visit_id">Select visit</label>
            <select name="visit_id" id="visit_id" required style="margin:4px 0 0;">
                <option value="">Choose a patient visit…</option>
                <?php foreach ($visits as $visit): ?>
                    <option value="<?= (int)$visit['VisitID'] ?>" <?= $visitId === (int)$visit['VisitID'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($visit['PatientName'] . (!empty($visit['DoctorName']) ? ' (Doctor: ' . format_doctor_name($visit['DoctorName']) . ')' : '') . ' — ' . date('M j, Y', strtotime($visit['AdmissionDate']))) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" style="width:auto;margin:0;">Calculate Invoice</button>
    </form>

    <?php if ($invoice): ?>
        <div style="margin-top:28px;padding-top:22px;border-top:1px solid var(--line);">
            <h3 style="margin-top:0;"><?= htmlspecialchars($invoice['PatientName']) ?> — Invoice Breakdown</h3>
            <div class="metric-row" style="margin-bottom:14px;">
                <div><span>Consultations</span><strong style="color:var(--blue)">$<?= number_format((float)$invoice['ConsultationTotal'], 2) ?></strong></div>
                <div><span>Lab Tests</span><strong style="color:var(--violet)">$<?= number_format((float)$invoice['LabTotal'], 2) ?></strong></div>
                <div><span>Prescriptions</span><strong style="color:var(--amber)">$<?= number_format((float)$invoice['MedicationTotal'], 2) ?></strong></div>
            </div>
            <div class="metric-row" style="margin-bottom:20px;">
                <div><span>Gross Total</span><strong>$<?= number_format($total, 2) ?></strong></div>
                <div><span>Insurance Coverage</span><strong style="color:var(--green)"><?= number_format((float)$invoice['CoveragePercentage'], 1) ?>%</strong></div>
                <div style="border-left-color:var(--coral)"><span>Out of Pocket</span><strong style="color:var(--coral);font-size:24px;">$<?= number_format($outOfPocket, 2) ?></strong></div>
            </div>
            <div class="callout">
                <strong>How it’s calculated:</strong> Gross Total × (1 − Insurance%) = Out-of-Pocket
            </div>
            <form method="POST" style="margin-top:16px;">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="visit_id" value="<?= (int)$visitId ?>">
                <button type="submit" id="generate_invoice_btn">Generate &amp; Save Invoice</button>
            </form>
        </div>
    <?php endif; ?>

    <!-- Saved Invoices -->
    <div style="margin-top:32px;padding-top:24px;border-top:1px solid var(--line);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <h3 style="margin:0;">🗂 Saved Invoices</h3>
            <span style="font-size:12px;color:var(--muted);"><?= count($savedInvoices) ?> invoice<?= count($savedInvoices) !== 1 ? 's' : '' ?> on file</span>
        </div>
        <?php if ($savedInvoices): ?>
        <div class="inv-box">
        <div class="inv-box-inner">
        <table>
            <thead><tr>
                <th>Invoice #</th><th>Patient</th><th>Doctor</th><th>Visit</th><th>Date</th><th>Billing Status</th><th>Out-of-Pocket</th><th>Action</th>
            </tr></thead>
            <tbody>
            <?php foreach ($savedInvoices as $inv): 
                $isPaid = ((float)$inv['OutOfPocket'] <= 0);
                $statusLabel = $isPaid ? 'Settled (100% Insured)' : 'Pending Due';
                $statusColor = $isPaid ? 'var(--green)' : 'var(--amber)';
                $statusBg = $isPaid ? 'rgba(34,212,158,.12)' : 'rgba(244,184,64,.12)';
            ?>
            <tr>
                <td style="font-family:monospace;color:var(--teal);">#<?= (int)$inv['InvoiceID'] ?></td>
                <td>
                    <strong><?= htmlspecialchars($inv['PatientName']) ?></strong><br>
                    <small style="color:var(--muted);font-family:monospace;"><?= htmlspecialchars($inv['PatientCode']) ?></small>
                </td>
                <td><?= htmlspecialchars(format_doctor_name($inv['DoctorName'] ?? '—')) ?></td>
                <td style="color:var(--muted);">Visit #<?= (int)$inv['VisitID'] ?></td>
                <td><?= htmlspecialchars(date('M j, Y', strtotime($inv['Date']))) ?></td>
                <td>
                    <span style="display:inline-flex;padding:3px 9px;border-radius:999px;font-size:11px;font-weight:700;color:<?= $statusColor ?>;background:<?= $statusBg ?>;white-space:nowrap;">
                        <?= $statusLabel ?>
                    </span>
                </td>
                <td><strong style="color:var(--coral);">$<?= number_format((float)$inv['OutOfPocket'], 2) ?></strong></td>
                <td>
                    <button type="button" class="btn btn-sm btn-auto btn-download btn-print-bill"
                        data-patient="<?= htmlspecialchars($inv['PatientName'], ENT_QUOTES, 'UTF-8') ?>"
                        data-code="<?= htmlspecialchars($inv['PatientCode'], ENT_QUOTES, 'UTF-8') ?>"
                        data-invoice="<?= (int)$inv['InvoiceID'] ?>"
                        data-visit="<?= (int)$inv['VisitID'] ?>"
                        data-date="<?= htmlspecialchars(date('M j, Y', strtotime($inv['Date'])), ENT_QUOTES, 'UTF-8') ?>"
                        data-outofpocket="<?= (float)$inv['OutOfPocket'] ?>"
                        data-doctor="<?= htmlspecialchars(format_doctor_name($inv['DoctorName'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        style="width:auto !important;">🖨 Print Bill</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        </div>
        <?php else: ?>
        <div class="empty-state compact">
            <strong>No invoices saved yet</strong>
            <span>Generate and save an invoice above — it will appear here.</span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Print Slip Overlay -->
<div id="printSlipOverlay">
  <div id="printSlip">
    <button class="slip-close" onclick="closePrintSlip()" aria-label="Close">&times;</button>
    <h2>🧳 PreMed Clinic Invoice</h2>
    <p class="slip-sub" id="slipSub"></p>
    <table>
      <tbody id="slipBody"></tbody>
    </table>
    <div class="slip-actions">
      <button class="slip-cancel-btn" onclick="closePrintSlip()">Close</button>
      <button class="slip-print-btn" onclick="window.print()">&#128424; Print Invoice</button>
    </div>
  </div>
</div>

<?php include __DIR__ . '/includes/footer_nav.php'; ?>
<script>
function openPrintSlip(patientName, patientCode, invoiceId, visitId, date, outOfPocket, doctorName) {
    document.getElementById('slipSub').textContent =
        'Patient: ' + patientName + ' (' + patientCode + ')  |  Invoice #' + invoiceId + '  |  Visit #' + visitId;
    var rows = [
        ['Date of Invoice', date],
        ['Attending Doctor', doctorName || 'Clinic Staff on Record'],
        ['Patient ID Code', patientCode],
        ['Visit Record ID', '#' + visitId],
        ['Invoice Number', '#' + invoiceId],
        ['Out-of-Pocket Balance', '$' + parseFloat(outOfPocket || 0).toFixed(2)],
        ['Status', parseFloat(outOfPocket || 0) <= 0 ? 'Fully Covered / Settled' : 'Payment Due at Desk']
    ];
    var html = '';
    rows.forEach(function(r) {
        html += '<tr><td>' + r[0] + '</td><td>' + r[1] + '</td></tr>';
    });
    document.getElementById('slipBody').innerHTML = html;
    document.getElementById('printSlipOverlay').classList.add('open');
}
function closePrintSlip() {
    document.getElementById('printSlipOverlay').classList.remove('open');
}
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.btn-print-bill');
    if (!btn) return;
    openPrintSlip(
        btn.dataset.patient || '',
        btn.dataset.code || '',
        btn.dataset.invoice || '',
        btn.dataset.visit || '',
        btn.dataset.date || '',
        parseFloat(btn.dataset.outofpocket || 0),
        btn.dataset.doctor || ''
    );
});
document.getElementById('printSlipOverlay').addEventListener('click', function(e) {
    if (e.target === this) closePrintSlip();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closePrintSlip();
});
</script>
</body>
</html>
