<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Doctor') {
    header('Location: login.php');
    exit();
}

$message = '';
$error = '';
// Get current doctor's department
$myDeptId = db_fetch_column($conn, 'SELECT DeptID FROM DOCTOR WHERE UserID = ?', [$_SESSION['user_id']]);

// Only show doctors in the same department
$doctors = db_fetch_all($conn, "SELECT D.UserID, U.Name, Dep.DeptName FROM DOCTOR D JOIN `USER` U ON D.UserID = U.UserID JOIN DEPARTMENT Dep ON D.DeptID = Dep.DeptID WHERE D.UserID <> ? AND D.DeptID = ? ORDER BY U.Name", [$_SESSION['user_id'], $myDeptId]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $newDoctorId = filter_input(INPUT_POST, 'new_doctor_id', FILTER_VALIDATE_INT);
    if (!$newDoctorId) {
        $error = 'Select a receiving doctor.';
    } else {
        $inTx = false;
        try {
            db_begin_transaction($conn);
            $inTx = true;
            $outgoingDept = db_fetch_column($conn, 'SELECT DeptID FROM DOCTOR WHERE UserID = ?', [$_SESSION['user_id']]);
            $incomingDept = db_fetch_column($conn, 'SELECT DeptID FROM DOCTOR WHERE UserID = ?', [$newDoctorId]);
            if (!$outgoingDept || $outgoingDept !== $incomingDept) {
                throw new RuntimeException('The receiving doctor must be in the same department.');
            }

            $conflict = db_fetch_one($conn, "SELECT A.AppointmentID FROM APPOINTMENT A JOIN APPOINTMENT Existing ON Existing.DoctorID = ? AND Existing.AppointmentDate = A.AppointmentDate WHERE A.DoctorID = ? AND A.AppointmentDate >= NOW() LIMIT 1", [$newDoctorId, $_SESSION['user_id']]);
            if ($conflict) {
                throw new RuntimeException('Transfer stopped because the receiving doctor has a conflicting appointment.');
            }

            db_execute($conn, 'UPDATE APPOINTMENT SET DoctorID = ? WHERE DoctorID = ? AND AppointmentDate >= NOW()', [$newDoctorId, $_SESSION['user_id']]);
            $count = db_affected_rows($conn);
            db_execute($conn, 'UPDATE PATIENT SET AssignedDoctorID = ? WHERE AssignedDoctorID = ?', [$newDoctorId, $_SESSION['user_id']]);
            db_commit($conn);
            $inTx = false;
            $message = $count . ' active appointment(s) transferred successfully.';
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
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Transfer Patients — PreMed</title>
    <meta name="description" content="Safely transfer all active patients to another doctor in the same department using a SQL transaction.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
</head>
<body>
<?php include __DIR__ . '/includes/nav.php'; ?>
<div class="card" style="max-width:540px;margin:clamp(10px,5vw,42px) auto;">
    <div class="page-header">
        <div class="page-header-left">
            <p class="eyebrow">Doctor Panel</p>
            <h2>Transfer Active Patients</h2>
            <p class="page-subtitle">Moves all patients and future appointments atomically to another doctor in your department.</p>
        </div>
        <span class="role-pill role-doctor">Transaction</span>
    </div>
    <?php if ($message): ?><p class="notice success"><?php echo htmlspecialchars($message); ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?php echo htmlspecialchars($error); ?></p><?php endif; ?>
    <div class="callout callout-warn" style="margin-top:14px;">
        <strong>Same department only.</strong> Only doctors in your department are shown. The transfer is atomic — both patient assignments and future appointments move together, or nothing changes.
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <label for="new_doctor_id">Receiving doctor</label>
        <?php if ($doctors): ?>
        <select name="new_doctor_id" id="new_doctor_id" required>
            <option value="">Select a doctor in your department…</option>
            <?php foreach ($doctors as $doctor): ?>
                <option value="<?= (int)$doctor['UserID'] ?>">
                    <?= htmlspecialchars(format_doctor_name($doctor['Name'])) ?> — <?= htmlspecialchars($doctor['DeptName']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php else: ?>
            <p class="notice warning">⚠ No other doctors are available in your department to receive patients.</p>
        <?php endif; ?>
        <button type="submit" id="transfer_btn"
                data-confirm-title="Confirm Clinical Transfer"
                data-confirm-message="Are you sure you want to transfer all your assigned patients and future appointments to the selected doctor? This action moves all active patient care records atomically."
                data-confirm-btn="Transfer All Patients"
                data-confirm-type="warning">Transfer Appointments &amp; Patients</button>
    </form>
    <div style="margin-top:16px;"><a class="text-link" href="dashboard.php">← Back to Dashboard</a></div>
</div>
<?php include __DIR__ . '/includes/footer_nav.php'; ?>
</body>
</html>
