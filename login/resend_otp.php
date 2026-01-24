<?php
session_start();
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require '../vendor/autoload.php';

if (isset($_SESSION['temp_user']) && isset($_SESSION['otp_code'])) {
    $user = $_SESSION['temp_user'];
    $otp = $_SESSION['otp_code'];

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        
        // --- CRITICAL FIX FOR DELAY ---
        $mail->Host       = gethostbyname('smtp.gmail.com'); 
        
        $mail->SMTPAuth   = true;
        $mail->Username   = 'viscaderrickjamesbucad@gmail.com';
        $mail->Password   = 'znvp gfcj nhdn xmdp';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;

        // SSL Options for Localhost
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
        $mail->Subject = 'Resent: SLATE Verification Code';
        $mail->Body    = "Your verification code is: <b style='font-size: 20px;'>$otp</b>";
        $mail->AltBody = "Your verification code is: $otp";

        $mail->send();
        header("Location: verify_otp.php?resent=1");
    } catch (Exception $e) {
        header("Location: verify_otp.php?error=send_failed");
    }
} else {
    header("Location: login.php");
}
?>