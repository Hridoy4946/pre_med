<?php
require 'db.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email         = trim($_POST['email'] ?? '');
    $password      = $_POST['password'] ?? '';
    $stmt          = $pdo->prepare("SELECT * FROM `USER` WHERE Email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['Password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['UserID'];
        $_SESSION['name']    = $user['Name'];

        // Resolve role by checking each role table
        $resolvedRole = 'Patient';
        $docCheck = $pdo->prepare("SELECT 1 FROM DOCTOR WHERE UserID = ? LIMIT 1");
        $docCheck->execute([$user['UserID']]);
        if ($docCheck->fetchColumn()) {
            $resolvedRole = 'Doctor';
        } else {
            $staffCheck = $pdo->prepare("SELECT 1 FROM STAFF WHERE UserID = ? LIMIT 1");
            $staffCheck->execute([$user['UserID']]);
            if ($staffCheck->fetchColumn()) {
                $resolvedRole = 'Staff';
            } else {
                $guardianCheck = $pdo->prepare("SELECT 1 FROM GUARDIAN WHERE GuardianUserID = ? LIMIT 1");
                $guardianCheck->execute([$user['UserID']]);
                if ($guardianCheck->fetchColumn()) {
                    $resolvedRole = 'Guardian';
                }
            }
        }
        $_SESSION['role'] = $resolvedRole;
        header("Location: dashboard.php");
        exit();
    } else {
        $error = "Invalid email or password.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login — PreMed</title>
    <meta name="description" content="Sign in to your PreMed care portal as a Patient, Doctor, Staff, or Guardian.">
    <link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
    <style>
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
        margin-top: 16px !important;
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
<main class="auth-panel" style="width:min(100%, 440px);">
    <div class="brand">
        <span class="brand-mark">+</span>
        <span>PreMed <small>Patient care, clearly organized</small></span>
    </div>
    <p class="eyebrow">Welcome Back</p>
    <h2>Sign in to Care Portal</h2>
    <p class="auth-copy">Track symptoms, appointments, and clinical progression in one secure place.</p>
    
    <nav class="auth-tabs">
        <a class="active" href="login.php">Login</a>
        <a href="signup.php">Register</a>
    </nav>

    <form method="POST">
        <?php if (isset($_GET['registered'])): ?>
            <p class="notice success">✓ Registration successful! Please sign in with your new account.</p>
        <?php endif; ?>
        <?php if (isset($error)): ?>
            <p class="notice error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <label for="email">Email address</label>
        <input type="email" id="email" name="email" placeholder="your@email.com" required autocomplete="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">

        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="••••••••" required autocomplete="current-password">

        <button type="submit">Log In to Portal</button>
        <p class="auth-footer">Need a new account? <a href="signup.php">Register here</a></p>
    </form>
</main>
</body>
</html>