<?php
/**
 * AUTH CHECK
 * Blocks access to protected pages if user is not logged in
 */

function requireStaff() {
    if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'staff'])) {
        die("Access denied: Staff or admin required.");
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: ../login/login.php");
    exit();
}
