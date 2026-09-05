<?php
require_once dirname(__DIR__) . '/backend/db.php';
session_start();

$departments = db_fetch_all($conn, 'SELECT DeptID, DeptName FROM DEPARTMENT ORDER BY DeptName');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name          = trim($_POST['name'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $passwordInput = $_POST['password'] ?? '';
    $address       = trim($_POST['address'] ?? '');
    $role          = $_POST['role'] ?? 'Patient';

    $bloodGroup    = $_POST['blood_group'] ?? '';
    $gender        = $_POST['gender'] ?? '';
    $dob           = $_POST['dob'] ?? '';
    $deptId        = filter_input(INPUT_POST, 'dept_id', FILTER_VALIDATE_INT);
    $title         = trim($_POST['title'] ?? '');
    $patientId     = filter_input(INPUT_POST, 'patient_id', FILTER_VALIDATE_INT);
    $phone         = trim($_POST['phone'] ?? '');

    $allowedRoles       = ['Patient', 'Doctor', 'Staff', 'Guardian'];
    $allowedBloodGroups = ['A+','A-','B+','B-','O+','O-','AB+','AB-','Unknown'];
    $allowedGenders     = ['Male','Female','Other','Prefer not to say'];

    $inTx = false;
    try {
        require_csrf();
        if (!in_array($role, $allowedRoles, true)) {
            throw new RuntimeException('Please select a valid account role.');
        }
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($passwordInput) < 8) {
            throw new RuntimeException('Enter a valid full name, email address, and a password with at least 8 characters.');
        }

        $password = password_hash($passwordInput, PASSWORD_DEFAULT);
        db_begin_transaction($conn);
        $inTx = true;

        db_execute($conn, "INSERT INTO `USER` (Name, Email, Password, Address) VALUES (?, ?, ?, ?)", [$name, $email, $password, $address]);
        $userId = db_insert_id($conn);

        if ($role === 'Patient') {
            if (!in_array($bloodGroup, $allowedBloodGroups, true)) {
                throw new RuntimeException('Please select a valid blood group.');
            }
            if (!in_array($gender, $allowedGenders, true)) {
                throw new RuntimeException('Please select a valid gender option.');
            }
            $dobValue = null;
            if ($dob !== '') {
                $dobParsed = DateTime::createFromFormat('Y-m-d', $dob);
                if (!$dobParsed || $dobParsed > new DateTime()) {
                    throw new RuntimeException('Please enter a valid date of birth.');
                }
                $dobValue = $dob;
            }
            db_execute($conn, "INSERT INTO PATIENT (UserID, BloodGroup, Gender, DateOfBirth) VALUES (?, ?, ?, ?)", [$userId, $bloodGroup, $gender, $dobValue]);
            // Auto-generate readable Patient Code
            $code = 'PRE-' . str_pad($userId, 5, '0', STR_PAD_LEFT);
            db_execute($conn, "UPDATE PATIENT SET PatientCode = ? WHERE UserID = ?", [$code, $userId]);

        } elseif ($role === 'Doctor') {
            if (!$deptId) {
                throw new RuntimeException('Please select a medical department for the doctor account.');
            }
            db_execute($conn, "INSERT INTO DOCTOR (UserID, DeptID) VALUES (?, ?)", [$userId, $deptId]);

        } elseif ($role === 'Staff') {
            if (!$deptId) {
                throw new RuntimeException('Please select a department for the staff account.');
            }
            db_execute($conn, "INSERT INTO STAFF (UserID, DeptID, Title) VALUES (?, ?, ?)", [$userId, $deptId, $title ?: 'Operations Staff']);

        } elseif ($role === 'Guardian') {
            $patientCode = strtoupper(trim($_POST['patient_code'] ?? ''));
            if (!preg_match('/^PRE-\d{5}$/', $patientCode)) {
                throw new RuntimeException('Please enter a valid Patient Code in the format PRE-00004.');
            }
            // Look up patient by readable code
            $patientRow = db_fetch_one($conn, "SELECT UserID FROM PATIENT WHERE PatientCode = ?", [$patientCode]);
            if (!$patientRow) {
                throw new RuntimeException('No patient found with code ' . htmlspecialchars($patientCode) . '. Please ask the patient to share their Patient Code from their dashboard.');
            }
            $patientId = (int) $patientRow['UserID'];
            if ($phone === '') {
                throw new RuntimeException('Please provide a guardian contact phone number.');
            }
            // Check if patient already has a guardian
            if (db_fetch_column($conn, "SELECT 1 FROM GUARDIAN WHERE PatientID = ?", [$patientId])) {
                throw new RuntimeException('Patient ' . htmlspecialchars($patientCode) . ' already has a registered guardian profile.');
            }
            db_execute($conn, "INSERT INTO GUARDIAN (PatientID, GuardianUserID, GuardianName, Phone) VALUES (?, ?, ?, ?)", [$patientId, $userId, $name, $phone]);
        }

        db_commit($conn);
        $inTx = false;
        header("Location: login.php?registered=1");
        exit();
    } catch (Exception $e) {
        if ($inTx) {
            db_rollback($conn);
        }
        $error = $e instanceof RuntimeException ? $e->getMessage() : "Registration failed. The email address may already be in use.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Register Account — PreMed</title>
    <meta name="description" content="Create your PreMed account as a Patient, Doctor, Staff, or Guardian.">
    <link rel="stylesheet" href="../resources/css/style.css?v=<?= filemtime(dirname(__DIR__) . '/resources/css/style.css') ?>">
    <style>
        .role-conditional { display: none; }
        .role-conditional.active { display: block; }

        .auth-panel form {
            display: flex !important;
            flex-direction: column !important;
            width: 100% !important;
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
        }
        .auth-panel label {
            display: block !important;
            width: 100% !important;
            margin: 14px 0 4px !important;
            color: var(--muted) !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            letter-spacing: .05em !important;
            text-transform: uppercase !important;
        }
        .auth-panel input, .auth-panel select {
            display: block !important;
            width: 100% !important;
            box-sizing: border-box !important;
            margin: 0 0 6px !important;
            padding: 12px 14px !important;
            border: 1px solid #263d54 !important;
            border-radius: 8px !important;
            background: #0d1f31 !important;
            color: #f0f7ff !important;
            font-size: 14px !important;
        }
        .auth-panel input:focus, .auth-panel select:focus {
            border-color: var(--teal) !important;
            outline: none !important;
        }
        .auth-panel button[type="submit"], .auth-panel button {
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            width: 100% !important;
            margin-top: 18px !important;
            padding: 12px 16px !important;
            background: linear-gradient(135deg, #12c8e4, #0898b5) !important;
            color: #03111e !important;
            font-size: 14px !important;
            font-weight: 700 !important;
            border: none !important;
            border-radius: 8px !important;
            cursor: pointer !important;
            transition: filter .15s !important;
        }
        .auth-panel button:hover {
            filter: brightness(1.1) !important;
        }
    </style>
</head>
<body class="auth-page">
<main class="auth-panel" style="width:min(100%, 460px);">
    <div class="brand">
        <span class="brand-mark">+</span>
        <span>PreMed <small>Patient care, clearly organized</small></span>
    </div>
    <p class="eyebrow">Create an Account</p>
    <h2>Join the Care Network</h2>
    <p class="auth-copy">Select your account role to get started with dedicated portal access.</p>
    
    <nav class="auth-tabs">
        <a href="login.php">Login</a>
        <a class="active" href="signup.php">Register</a>
    </nav>

    <form method="POST" id="register_form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

        <?php if (isset($error)): ?>
            <p class="notice error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <!-- Clean Role Selection Dropdown (No Brackets) -->
        <label for="role" style="color:var(--teal);font-weight:700;">Account Role</label>
        <select id="role" name="role" required style="border-color:var(--teal);background:#0d2338;color:#e8f4fb;">
            <option value="Patient"  <?= (($_POST['role'] ?? 'Patient') === 'Patient')  ? 'selected' : '' ?>>Patient</option>
            <option value="Doctor"   <?= (($_POST['role'] ?? '') === 'Doctor')   ? 'selected' : '' ?>>Doctor</option>
            <option value="Staff"    <?= (($_POST['role'] ?? '') === 'Staff')    ? 'selected' : '' ?>>Staff</option>
            <option value="Guardian" <?= (($_POST['role'] ?? '') === 'Guardian') ? 'selected' : '' ?>>Guardian</option>
        </select>

        <!-- Common User Information -->
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" placeholder="e.g. Alex Rahman or Dr. Sarah Chen" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>

        <label for="email">Email address</label>
        <input type="email" id="email" name="email" placeholder="your@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autocomplete="email">

        <label for="password">Password <span style="font-weight:400;text-transform:none;color:var(--muted);">(min. 8 characters)</span></label>
        <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="new-password">

        <!-- PATIENT SPECIFIC FIELDS -->
        <div id="fields_patient" class="role-conditional active">
            <label for="dob">Date of Birth</label>
            <input type="date" id="dob" name="dob" max="<?= date('Y-m-d') ?>" value="<?= htmlspecialchars($_POST['dob'] ?? '') ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                <div>
                    <label for="blood_group">Blood Group</label>
                    <select id="blood_group" name="blood_group">
                        <option value="">Select blood group…</option>
                        <?php foreach (['A+','A-','B+','B-','O+','O-','AB+','AB-','Unknown'] as $bg): ?>
                            <option value="<?= $bg ?>" <?= (($_POST['blood_group'] ?? '') === $bg) ? 'selected' : '' ?>><?= $bg ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="gender">Gender</label>
                    <select id="gender" name="gender">
                        <option value="">Select gender…</option>
                        <?php foreach (['Male','Female','Other','Prefer not to say'] as $g): ?>
                            <option value="<?= $g ?>" <?= (($_POST['gender'] ?? '') === $g) ? 'selected' : '' ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- DOCTOR SPECIFIC FIELDS -->
        <div id="fields_doctor" class="role-conditional">
            <label for="dept_id_doctor">Medical Department</label>
            <select id="dept_id_doctor" name="dept_id">
                <option value="">Select department…</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= (int)$dept['DeptID'] ?>" <?= (isset($_POST['dept_id']) && (int)$_POST['dept_id'] === (int)$dept['DeptID']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['DeptName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- STAFF SPECIFIC FIELDS -->
        <div id="fields_staff" class="role-conditional">
            <label for="dept_id_staff">Department</label>
            <select id="dept_id_staff" name="dept_id">
                <option value="">Select department…</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= (int)$dept['DeptID'] ?>">
                        <?= htmlspecialchars($dept['DeptName']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="title">Job Title</label>
            <input type="text" id="title" name="title" placeholder="e.g. Care Operations Specialist" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>">
        </div>

        <!-- GUARDIAN SPECIFIC FIELDS (PATIENT CODE ENTRY) -->
        <div id="fields_guardian" class="role-conditional">
            <label for="patient_code">Patient Code
                <span style="font-weight:400;text-transform:none;color:var(--muted);font-size:12px;">
                    — ask the patient to share their code from their dashboard
                </span>
            </label>
            <input type="text" id="patient_code" name="patient_code"
                   placeholder="e.g. PRE-00004"
                   pattern="PRE-\d{5}"
                   style="text-transform:uppercase;letter-spacing:.05em;font-family:monospace;"
                   value="<?= htmlspecialchars($_POST['patient_code'] ?? '') ?>">

            <label for="phone">Guardian Phone Number</label>
            <input type="text" id="phone" name="phone" placeholder="+880-1711-XXXXXX" value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
        </div>

        <!-- Address -->
        <label for="address">Address <span style="font-weight:400;text-transform:none;color:var(--muted);">(optional)</span></label>
        <input type="text" id="address" name="address" placeholder="Residential or workplace address" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>">

        <button type="submit" id="submit_btn" style="margin-top:14px;">Create Account</button>
        <p class="auth-footer">Already registered? <a href="login.php">Sign in to your account</a></p>
    </form>
</main>

<script>
const roleSelect = document.getElementById('role');
const fieldsPatient  = document.getElementById('fields_patient');
const fieldsDoctor   = document.getElementById('fields_doctor');
const fieldsStaff    = document.getElementById('fields_staff');
const fieldsGuardian = document.getElementById('fields_guardian');
const submitBtn      = document.getElementById('submit_btn');

function updateRoleFields() {
    const role = roleSelect.value;
    
    // Hide all
    fieldsPatient.classList.remove('active');
    fieldsDoctor.classList.remove('active');
    fieldsStaff.classList.remove('active');
    fieldsGuardian.classList.remove('active');

    // Disable non-active fields so required/data attributes don't block submit
    document.querySelectorAll('.role-conditional input, .role-conditional select').forEach(el => el.disabled = true);

    if (role === 'Patient') {
        fieldsPatient.classList.add('active');
        fieldsPatient.querySelectorAll('input, select').forEach(el => el.disabled = false);
        submitBtn.textContent = 'Create Patient Account';
    } else if (role === 'Doctor') {
        fieldsDoctor.classList.add('active');
        fieldsDoctor.querySelectorAll('input, select').forEach(el => el.disabled = false);
        submitBtn.textContent = 'Create Doctor Account';
    } else if (role === 'Staff') {
        fieldsStaff.classList.add('active');
        fieldsStaff.querySelectorAll('input, select').forEach(el => el.disabled = false);
        submitBtn.textContent = 'Create Staff Account';
    } else if (role === 'Guardian') {
        fieldsGuardian.classList.add('active');
        fieldsGuardian.querySelectorAll('input, select').forEach(el => el.disabled = false);
        submitBtn.textContent = 'Create Guardian Account';
    }
}

// Auto-uppercase the patient code as user types
document.addEventListener('DOMContentLoaded', () => {
    const codeInput = document.getElementById('patient_code');
    if (codeInput) {
        codeInput.addEventListener('input', () => {
            const cur = codeInput.selectionStart;
            codeInput.value = codeInput.value.toUpperCase();
            codeInput.setSelectionRange(cur, cur);
        });
    }
});

roleSelect.addEventListener('change', updateRoleFields);
updateRoleFields();
</script>
</body>
</html>