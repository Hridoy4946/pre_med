<?php
session_start();
if (isset($_SESSION['user_id'])) {
    header('Location: frontend/dashboard.php');
} else {
    header('Location: frontend/login.php');
}
exit();

