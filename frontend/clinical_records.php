<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit();
}

$message = '';
$error = '';
$patients = db_fetch_all($conn, "SELECT P.UserID, U.Name FROM PATIENT P JOIN `USER` U ON P.UserID = U.UserID WHERE P.AssignedDoctorID = ? ORDER BY U.Name", [$_SESSION['user_id']]);
$medications = db_fetch_all($conn, "SELECT MedicationID, MedicationName, StockQuantity FROM MEDICATION ORDER BY MedicationName");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $patientId = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
    $medicationId = filter_input(INPUT_POST, 'medication_id', FILTER_VALIDATE_INT);
    $quantity = filter_input(INPUT_POST, 'quantity', FILTER_VALIDATE_INT);
    $consultationCost = filter_input(INPUT_POST, 'consultation_cost', FILTER_VALIDATE_FLOAT);
    $labCost = filter_input(INPUT_POST, 'lab_cost', FILTER_VALIDATE_FLOAT);
    $diagnosisText = trim($_POST['diagnosis_text'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $labResult = trim($_POST['lab_result'] ?? '');
    $dosage = trim($_POST['dosage'] ?? '');

    if (!$patientId || ($medicationId && (!$quantity || $quantity < 1 || $dosage === ''))) {
        $error = 'Select a patient. A medication requires a dosage and positive quantity.';
    } elseif ($consultationCost === false || $consultationCost < 0 || $labCost === false || $labCost < 0) {
        $error = 'Costs must be zero or a positive number.';
    } else {
        $inTx = false;
        try {
            db_begin_transaction($conn);
            $inTx = true;
            if (!db_fetch_column($conn, "SELECT 1 FROM PATIENT WHERE UserID = ? AND AssignedDoctorID = ? LIMIT 1", [$patientId, $_SESSION['user_id']])) {
                throw new RuntimeException('This patient is not assigned to your care.');
            }
            db_execute($conn, 'SET @app_user_id = ' . (int) $_SESSION['user_id']);

            db_execute($conn, 'INSERT INTO VISIT (PatientID) VALUES (?)', [$patientId]);
            $visitId = db_insert_id($conn);

            if ($diagnosisText !== '') {
                db_execute($conn, 'INSERT INTO DIAGNOSIS (VisitID, DiagnosisText) VALUES (?, ?)', [$visitId, $diagnosisText]);
            }
            if ($notes !== '' || $consultationCost > 0) {
                db_execute($conn, 'INSERT INTO CONSULTATION (Notes, VisitID, DoctorID, Cost) VALUES (?, ?, ?, ?)', [$notes, $visitId, $_SESSION['user_id'], $consultationCost]);
            }
            if ($labResult !== '' || $labCost > 0) {
                db_execute($conn, 'INSERT INTO LAB_TEST (Result, VisitID, Cost) VALUES (?, ?, ?)', [$labResult, $visitId, $labCost]);
            }
            if ($medicationId) {
                db_execute($conn, 'INSERT INTO PRESCRIPTION (VisitID) VALUES (?)', [$visitId]);
                $prescriptionId = db_insert_id($conn);
                db_execute($conn, 'INSERT INTO PRESCRIPTION_ITEM (PrescriptionID, MedicationID, Dosage, Quantity) VALUES (?, ?, ?, ?)', [$prescriptionId, $medicationId, $dosage, $quantity]);
            }

            db_commit($conn);
            $inTx = false;
            $message = 'Clinical records saved successfully.';
        } catch (Throwable $exception) {
            if ($inTx) {
                db_rollback($conn);
            }
            $error = $exception->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Clinical Record Entry — PreMed</title>
    <meta name="description" content="Enter visit records including diagnosis, consultation notes, lab results, and prescriptions.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="card" style="max-width:660px;margin:clamp(10px,5vw,42px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Doctor Panel</p>
            <h2>Clinical Record Entry</h2>
            <p class="page-subtitle">Create a new visit record with diagnosis, consultation notes, lab results, and prescriptions.</p>
        </div>
        <span class="role-pill role-doctor">Doctor</span>
    </div>
    <?php if ($message): ?><p class="notice success"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <?php if ($error):   ?><p class="notice error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

    <form method="POST" style="margin-top:6px;">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <label for="patient_id">Patient</label>
        <select name="patient_id" id="patient_id" required>
            <option value="">Select a patient…</option>
            <?php foreach ($patients as $patient): ?>
                <option value="<?= (int)$patient['UserID'] ?>"><?= htmlspecialchars($patient['Name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="diagnosis_text">Diagnosis</label>
        <textarea id="diagnosis_text" name="diagnosis_text" maxlength="500"
                  style="min-height:70px;resize:vertical;" placeholder="Enter the full clinical diagnosis…"></textarea>

        <label for="notes">Consultation notes</label>
        <textarea id="notes" name="notes"
                  style="min-height:90px;resize:vertical;" placeholder="Findings, observations, treatment plan…"></textarea>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div>
                <label for="consultation_cost">Consultation cost ($)</label>
                <input type="number" id="consultation_cost" name="consultation_cost" min="0" step="0.01" value="0">
            </div>
            <div>
                <label for="lab_cost">Lab cost ($)</label>
                <input type="number" id="lab_cost" name="lab_cost" min="0" step="0.01" value="0">
            </div>
        </div>

        <label for="lab_result">Lab result</label>
        <textarea id="lab_result" name="lab_result"
                  style="min-height:70px;resize:vertical;" placeholder="Lab test findings and values…"></textarea>

        <div style="margin-top:18px;padding-top:16px;border-top:1px solid var(--line);">
            <p class="eyebrow" style="margin-bottom:10px;">Prescription (optional)</p>
            <label for="medication_id">Medication</label>
            <select id="medication_id" name="medication_id">
                <option value="">No medication prescribed</option>
                <?php foreach ($medications as $medication): ?>
                    <option value="<?= (int)$medication['MedicationID'] ?>">
                        <?= htmlspecialchars($medication['MedicationName'] . ' (' . $medication['StockQuantity'] . ' in stock)') ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <div style="display:grid;grid-template-columns:2fr 1fr;gap:12px;">
                <div>
                    <label for="dosage">Dosage instructions</label>
                    <input type="text" id="dosage" name="dosage" placeholder="e.g. 1 tablet twice daily">
                </div>
                <div>
                    <label for="quantity">Quantity</label>
                    <input type="number" id="quantity" name="quantity" min="1" step="1" value="1">
                </div>
            </div>
        </div>

        <button type="submit" style="margin-top:18px;">Save Clinical Record</button>
    </form>

    <div class="bottom-actions">
        <a href="dashboard.php">← Back to Dashboard</a>
        <a href="billing.php">Generate Invoice</a>
        <a href="record_management.php">Manage records</a>
    </div>
</div>
<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>

