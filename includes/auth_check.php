<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if the user has completed BOTH steps
// login_success is only set to TRUE inside verify_otp.php
if (!isset($_SESSION['user_id']) || !isset($_SESSION['login_success'])) {
    // If they only have temp_user, they are still in OTP stage
    if (isset($_SESSION['temp_user'])) {
        header("Location: ../login/verify_otp.php");
    } else {
        header("Location: ../login/login.php");
    }
    exit();
}
?>