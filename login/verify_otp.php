<?php  // qxoj leme xjau rfsf
session_start();

// Redirect back if no OTP process is active
if (!isset($_SESSION['otp_code'])) {
    header("Location: login.php");
    exit();
}

$errorMsg = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $user_code = trim($_POST['otp']);

    // Check if code is expired (5 minutes)
    if (time() > $_SESSION['otp_expiry']) {
        $errorMsg = "Verification code expired. Please login again.";
        unset($_SESSION['otp_code'], $_SESSION['temp_user'], $_SESSION['otp_expiry']);
    } 
    // Check if code matches
    elseif ($user_code == $_SESSION['otp_code']) {
        // SUCCESS: Transfer temporary user data to active session
        $user = $_SESSION['temp_user'];
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['Email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_success'] = true;

        // Cleanup OTP data
        unset($_SESSION['otp_code'], $_SESSION['temp_user'], $_SESSION['otp_expiry']);

        header("Location: ../public/dashboard.php?login_success=1");
        exit();
    } else {
        $errorMsg = "Invalid verification code. Please check your email.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify OTP - SLATE System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="../assets/login.css"> <style>
        /* Minimal override for the OTP input */
        .otp-input {
            letter-spacing: 0.5rem;
            text-align: center;
            font-size: 1.5rem !important;
            font-weight: bold;
        }
    </style>
</head>
<body style="background: linear-gradient(135deg, #0f2027, #203a43, #2c5364); font-family: sans-serif; color: white;">

<div style="height: 100vh; display: flex; align-items: center; justify-content: center;">
    <div style="background: rgba(22, 33, 49, 0.95); padding: 3rem; border-radius: 0.75rem; width: 100%; max-width: 400px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0.5);">
        <img src="../assets/slate.png" alt="Logo" style="width: 5rem; margin-bottom: 1rem;">
        <h2 style="margin-bottom: 0.5rem;">Two-Step Verification</h2>
        <p style="color: rgba(255,255,255,0.7); margin-bottom: 2rem; font-size: 0.9rem;">
            Enter the 6-digit code sent to your Gmail.
        </p>

        <?php if ($errorMsg): ?>
            <div style="background: rgba(220, 53, 69, 0.1); border: 1px solid #dc3545; color: #ff6b6b; padding: 0.75rem; border-radius: 0.375rem; margin-bottom: 1.5rem; font-size: 0.9rem;">
                <i class="bi bi-exclamation-triangle-fill"></i> <?= $errorMsg ?>
            </div>
        <?php endif; ?>

        <form action="verify_otp.php" method="POST">
            <div style="margin-bottom: 1.5rem;">
                <input type="text" name="otp" class="otp-input" maxlength="6" placeholder="000000" required 
                       style="width: 100%; padding: 0.9rem; background: rgba(255, 255, 255, 0.05); border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 0.375rem; color: white; outline: none;">
            </div>

            <button type="submit" style="width: 100%; padding: 0.9rem; background: linear-gradient(to right, #0072ff, #00c6ff); border: none; border-radius: 0.375rem; color: white; font-weight: 600; cursor: pointer;">
                Verify & Login
            </button>
        </form>

        <p style="margin-top: 1.5rem; font-size: 0.8rem; color: rgba(255,255,255,0.5);">
            Didn't get the code? <a href="login.php" style="color: #00c6ff; text-decoration: none;">Try again</a>
        </p>
    </div>
</div>

</body>
</html>