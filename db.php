<?php
$host = 'localhost';
$db   = 'PRE_MED';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    http_response_code(500);
    die("Database connection failed. Check that MySQL is running and PRE_MED exists.");
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('require_csrf')) {
    function require_csrf(): void
    {
        if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
            http_response_code(419);
            exit('Invalid form session. Please go back and try again.');
        }
    }
}

if (!function_exists('format_doctor_name')) {
    function format_doctor_name(?string $name): string
    {
        if ($name === null || $name === '') {
            return '';
        }
        $trimmed = trim($name);
        if (preg_match('/^Dr\.?\s+/i', $trimmed)) {
            return $trimmed;
        }
        return 'Dr. ' . $trimmed;
    }
}