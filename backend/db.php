<?php
/**
 * Database Connection & Procedural MySQLi Helpers
 * PreMed Clinic Portal
 */

$host = 'localhost';
$db   = 'PRE_MED';
$user = 'root';
$pass = '';
$charset = 'utf8mb4';

// Procedural connection
$conn = mysqli_connect($host, $user, $pass, $db);
if (!$conn) {
    http_response_code(500);
    die("Database connection failed. Check that MySQL is running and PRE_MED exists: " . mysqli_connect_error());
}
mysqli_set_charset($conn, $charset);

/**
 * Procedural helper to prepare and execute a parameterized SQL query.
 *
 * @param mysqli $conn
 * @param string $sql
 * @param array $params
 * @return mysqli_stmt
 */
if (!function_exists('db_prepare_execute')) {
    function db_prepare_execute($conn, string $sql, array $params = [])
    {
        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            throw new RuntimeException("SQL Prepare Error: " . mysqli_error($conn) . " in SQL: " . $sql);
        }

        if (!empty($params)) {
            $types = '';
            $bindArgs = [];
            foreach ($params as $p) {
                if (is_int($p)) {
                    $types .= 'i';
                } elseif (is_float($p)) {
                    $types .= 'd';
                } else {
                    $types .= 's';
                }
                $bindArgs[] = $p;
            }
            mysqli_stmt_bind_param($stmt, $types, ...$bindArgs);
        }

        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            throw new RuntimeException("SQL Execute Error: " . $err . " in SQL: " . $sql);
        }

        return $stmt;
    }
}

/**
 * Fetch all matching rows as an array of associative arrays.
 */
if (!function_exists('db_fetch_all')) {
    function db_fetch_all($conn, string $sql, array $params = []): array
    {
        $stmt = db_prepare_execute($conn, $sql, $params);
        $result = mysqli_stmt_get_result($stmt);
        $data = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $data[] = $row;
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        return $data;
    }
}

/**
 * Fetch a single matching row as an associative array, or null if not found.
 */
if (!function_exists('db_fetch_one')) {
    function db_fetch_one($conn, string $sql, array $params = []): ?array
    {
        $stmt = db_prepare_execute($conn, $sql, $params);
        $result = mysqli_stmt_get_result($stmt);
        $row = null;
        if ($result) {
            $row = mysqli_fetch_assoc($result) ?: null;
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        return $row;
    }
}

/**
 * Fetch a single scalar value from the first column of the first matching row.
 */
if (!function_exists('db_fetch_column')) {
    function db_fetch_column($conn, string $sql, array $params = [], int $colIndex = 0)
    {
        $stmt = db_prepare_execute($conn, $sql, $params);
        $result = mysqli_stmt_get_result($stmt);
        $val = null;
        if ($result) {
            $row = mysqli_fetch_row($result);
            if ($row && array_key_exists($colIndex, $row)) {
                $val = $row[$colIndex];
            }
            mysqli_free_result($result);
        }
        mysqli_stmt_close($stmt);
        return $val;
    }
}

/**
 * Execute an INSERT / UPDATE / DELETE statement.
 */
if (!function_exists('db_execute')) {
    function db_execute($conn, string $sql, array $params = []): bool
    {
        $stmt = db_prepare_execute($conn, $sql, $params);
        mysqli_stmt_close($stmt);
        return true;
    }
}

/**
 * Returns the auto-generated ID from the last INSERT.
 */
if (!function_exists('db_insert_id')) {
    function db_insert_id($conn): int
    {
        return (int)mysqli_insert_id($conn);
    }
}

/**
 * Returns number of affected rows in previous MySQL operation.
 */
if (!function_exists('db_affected_rows')) {
    function db_affected_rows($conn): int
    {
        return mysqli_affected_rows($conn);
    }
}

/**
 * Transaction helpers (procedural)
 */
if (!function_exists('db_begin_transaction')) {
    function db_begin_transaction($conn): bool
    {
        return mysqli_begin_transaction($conn);
    }
}

if (!function_exists('db_commit')) {
    function db_commit($conn): bool
    {
        return mysqli_commit($conn);
    }
}

if (!function_exists('db_rollback')) {
    function db_rollback($conn): bool
    {
        return mysqli_rollback($conn);
    }
}

// Global helper functions
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