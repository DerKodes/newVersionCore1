<?php
session_start();
include "../api/db.php";
require '../vendor/autoload.php'; // Ensure this path is correct

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email']);
    $password = $_POST['password'];

    // 1. Check if user exists
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 1) {
        $user = $result->fetch_assoc();

        // 2. Verify Password
        // NOTE: Ensure your registration uses hash('sha256', $pass) or password_hash()
        // Based on your register code, you used hash('sha256'):
        if (hash('sha256', $password) === $user['password']) {
            
            // 3. Generate OTP
            $otp = rand(100000, 999999);
            $_SESSION['temp_user'] = $user; // Store user momentarily
            $_SESSION['otp_code'] = $otp;
            $_SESSION['otp_expiry'] = time() + 300; // 5 minutes

            // 4. SEND EMAIL (The Fix)
            $mail = new PHPMailer(true);
            try {
                // $mail->SMTPDebug = 2; // Uncomment to see error log on screen
                $mail->isSMTP();
                
                // --- CRITICAL FIX FOR DELAY ---
                // Force IPv4 to prevent 10-minute IPv6 timeout
                $mail->Host       = gethostbyname('smtp.gmail.com'); 
                
                $mail->SMTPAuth   = true;
                $mail->Username   = 'viscaderrickjamesbucad@gmail.com'; 
                $mail->Password   = 'znvp gfcj nhdn xmdp'; // Make sure there are no spaces/newlines
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // Use SSL
                $mail->Port       = 465; // Port 465 is faster/more reliable than 587

                // Optional: Disable strict SSL if on localhost/XAMPP and getting certificate errors
                $mail->SMTPOptions = array(
                    'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    )
                );

                $mail->setFrom('viscaderrickjamesbucad@gmail.com', 'SLATE System');
                $mail->addAddress($user['email']);

                $mail->isHTML(true);
                $mail->Subject = 'SLATE Verification Code';
                $mail->Body    = "Your login verification code is: <b style='font-size: 20px;'>$otp</b>";
                $mail->AltBody = "Your login verification code is: $otp";

                $mail->send();
                
                // Redirect to OTP page
                header("Location: verify_otp.php");
                exit();

            } catch (Exception $e) {
                // Login failed due to mail error
                header("Location: login.php?error=mail_failed");
            }

        } else {
            header("Location: login.php?error=invalid_password");
        }
    } else {
        header("Location: login.php?error=user_not_found");
    }
} else {
    header("Location: login.php");
}
?>