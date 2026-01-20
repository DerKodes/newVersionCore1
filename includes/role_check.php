<?php
if (!isset($_SESSION['role'])) {
    header("Location: ../login/login.php");
    exit();
}

function requireAdmin() {
    if ($_SESSION['role'] !== 'ADMIN') {
        http_response_code(403);
        die("Access Denied: Admin only.");
    }
}
