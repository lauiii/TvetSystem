<?php
// Root index — redirect to proper page
require_once __DIR__ . '/config.php';

if (function_exists('isLoggedIn') && isLoggedIn()) {
    $role = $_SESSION['role'] ?? '';
    if ($role === 'admin') {
        header('Location: admin/dashboard.php');
    } elseif ($role === 'instructor') {
        header('Location: instructor/dashboard.php');
    } elseif ($role === 'student') {
        header('Location: student/dashboard.php');
    } else {
        header('Location: login.php');
    }
} else {
    header('Location: login.php');
}
exit;
