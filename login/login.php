<?php
session_start();
include "../api/db.php";

// 1. IMPORT PHPMAILER CLASSES
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// 2. LOAD COMPOSER AUTOLOADER
// Path assumes login.php is in core1/login/ and vendor is in project root
require '../vendor/autoload.php';

// ================== SECURITY: CSRF TOKEN ==================
if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$Email = $password = "";
$errorMsg = "";

// Security Config
$maxAttempts = 4;
$lockoutTime = 60;
$clientIP = $_SERVER['REMOTE_ADDR'];

// Initialize Session Arrays
if (!isset($_SESSION['login_attempts'])) {
  $_SESSION['login_attempts'] = [];
  $_SESSION['last_attempt_time'] = [];
}

function attemptKey($email, $ip)
{
  return md5($email . '|' . $ip);
}

// Check Lockout
$remaining = 0;
if (!empty($_POST['Email'])) {
  $key = attemptKey($_POST['Email'], $clientIP);
  $_SESSION['login_attempts'][$key] ??= 0;
  $_SESSION['last_attempt_time'][$key] ??= 0;

  if ($_SESSION['login_attempts'][$key] >= $maxAttempts) {
    $elapsed = time() - $_SESSION['last_attempt_time'][$key];
    if ($elapsed < $lockoutTime) {
      $remaining = $lockoutTime - $elapsed;
      $errorMsg = "⛔ Too many failed attempts. Please wait {$remaining} seconds.";
    } else {
      $_SESSION['login_attempts'][$key] = 0;
    }
  }
}

// Handle Form Submit
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($errorMsg)) {

  if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("❌ Security Error: Invalid CSRF Token.");
  }

  $Email = trim($_POST["Email"]);
  $password = trim($_POST["password"]);

  if (empty($Email) || empty($password)) {
    $errorMsg = "Please enter both email and password.";
  } else {
    $key = attemptKey($Email, $clientIP);

    $stmt = $conn->prepare("SELECT user_id, full_name, email, password, role FROM users WHERE email=?");
    $stmt->bind_param("s", $Email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
      // Check password (matching your existing sha256 hash)
      if (hash('sha256', $password) === $user['password']) {

        // --- START OTP LOGIC ---
        $_SESSION['login_attempts'][$key] = 0;
        $_SESSION['last_attempt_time'][$key] = 0;

        $otp = rand(100000, 999999);
        $_SESSION['temp_user'] = $user;
        $_SESSION['otp_code'] = $otp;
        $_SESSION['otp_expiry'] = time() + 300; // 5 minute expiry

        $mail = new PHPMailer(true);
        try {
          $mail->isSMTP();
          $mail->Host       = 'smtp.gmail.com';
          $mail->SMTPAuth   = true;
          $mail->Username   = 'viscaderrickjamesbucad@gmail.com'; // Your Gmail address
          $mail->Password   = 'znvp gfcj nhdn xmdp
';   // Your Google App Password
          $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
          $mail->Port       = 587;

          $mail->setFrom('viscaderrickjamesbucad@gmail.com', 'SLATE System');
          $mail->addAddress($user['email'], $user['full_name']);

          $mail->isHTML(true);
          $mail->Subject = 'SLATE Login Verification Code';
          $mail->Body    = "<h3>Security Verification</h3>
                              <p>Hello {$user['full_name']},</p>
                              <p>Your verification code is: <b style='font-size: 20px;'>$otp</b></p>
                              <p>This code expires in 5 minutes.</p>";

          $mail->send();
          header("Location: verify_otp.php");
          exit();
        } catch (Exception $e) {
          $errorMsg = "Email could not be sent. Mailer Error: {$mail->ErrorInfo}";
        }
        // --- END OTP LOGIC ---

      } else {
        $_SESSION['login_attempts'][$key]++;
        $_SESSION['last_attempt_time'][$key] = time();
        $errorMsg = "Invalid email or password.";
      }
    } else {
      $_SESSION['login_attempts'][$key]++;
      $_SESSION['last_attempt_time'][$key] = time();
      $errorMsg = "Invalid email or password.";
    }
  }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Login - SLATE System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
  <link rel="shortcut icon" href="../assets/slate.png" type="image/x-icon">
  <style>
    /* Base Styles */
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Segoe UI', system-ui, sans-serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
      color: white;
      line-height: 1.6;
    }

    .main-container {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2rem;
    }

    .login-container {
      width: 100%;
      max-width: 75rem;
      display: flex;
      background: rgba(31, 42, 56, 0.8);
      border-radius: 0.75rem;
      overflow: hidden;
      box-shadow: 0 0.625rem 1.875rem rgba(0, 0, 0, 0.3);
      animation: fadeIn 0.8s ease-out;
    }

    .welcome-panel {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2.5rem;
      background: linear-gradient(135deg, rgba(0, 114, 255, 0.2), rgba(0, 198, 255, 0.2));
      position: relative;
    }

    .welcome-panel h1 {
      font-size: 2.5rem;
      font-weight: 800;
      text-align: center;
      z-index: 2;
    }

    .login-panel {
      width: 28rem;
      padding: 4rem 3rem;
      background: rgba(22, 33, 49, 0.95);
    }

    .login-box {
      width: 100%;
      text-align: center;
    }

    .login-box img {
      width: 5rem;
      margin-bottom: 1rem;
    }

    .login-box h2 {
      margin-bottom: 1.5rem;
      font-size: 1.5rem;
    }

    .error-alert {
      background-color: rgba(220, 53, 69, 0.1);
      border: 1px solid #dc3545;
      color: #ff6b6b;
      padding: 0.75rem;
      border-radius: 0.375rem;
      margin-bottom: 1.5rem;
      font-size: 0.9rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }

    .input-group {
      position: relative;
      margin-bottom: 1.25rem;
    }

    .input-group i.icon-start {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: rgba(255, 255, 255, 0.5);
    }

    .input-group input {
      width: 100%;
      padding: 0.9rem 1rem 0.9rem 2.8rem;
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 0.375rem;
      color: white;
      transition: all 0.3s ease;
    }

    .input-group input:focus {
      outline: none;
      border-color: #00c6ff;
      background: rgba(255, 255, 255, 0.1);
    }

    .toggle-password {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      cursor: pointer;
      color: rgba(255, 255, 255, 0.5);
    }

    button {
      width: 100%;
      padding: 0.9rem;
      background: linear-gradient(to right, #0072ff, #00c6ff);
      border: none;
      border-radius: 0.375rem;
      font-weight: 600;
      color: white;
      cursor: pointer;
      transition: 0.3s;
    }

    button:hover {
      transform: translateY(-1px);
      box-shadow: 0 4px 15px rgba(0, 198, 255, 0.3);
    }

    .spinner {
      display: inline-block;
      width: 1.2rem;
      height: 1.2rem;
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 50%;
      border-top-color: #fff;
      animation: spin 0.8s linear infinite;
      margin-right: 8px;
      vertical-align: middle;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
        transform: translateY(20px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    footer {
      text-align: center;
      padding: 1.5rem;
      color: rgba(255, 255, 255, 0.5);
      font-size: 0.8rem;
    }
  </style>
</head>

<body>
  <div class="main-container">
    <div class="login-container">
      <div class="welcome-panel">
        <h1>FREIGHT MANAGEMENT SYSTEM</h1>
      </div>
      <div class="login-panel">
        <div class="login-box">
          <img src="../assets/slate.png" alt="SLATE Logo">
          <h2>SLATE Login</h2>
          <?php if (!empty($errorMsg)): ?>
            <div class="error-alert"><i class="bi bi-exclamation-triangle-fill"></i> <?= $errorMsg ?></div>
          <?php endif; ?>
          <form action="login.php" method="POST" id="loginForm">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <div class="input-group">
              <input type="text" name="Email" placeholder="Email Address" value="<?= htmlspecialchars($Email) ?>" required>
              <i class="bi bi-envelope-fill icon-start"></i>
            </div>
            <div class="input-group">
              <input type="password" name="password" id="passwordInput" placeholder="Password" required>
              <i class="bi bi-lock-fill icon-start"></i>
              <i class="bi bi-eye-slash-fill toggle-password" onclick="togglePassword()"></i>
            </div>
            <button type="submit" id="btnSubmit"><span id="btnText">Log In</span></button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <footer>&copy; <span id="currentYear"></span> SLATE Freight Management System. All rights reserved.</footer>
  <script>
    document.getElementById('currentYear').textContent = new Date().getFullYear();

    function togglePassword() {
      const input = document.getElementById('passwordInput');
      const icon = document.querySelector('.toggle-password');
      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye-slash-fill', 'bi-eye-fill');
      } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-fill', 'bi-eye-slash-fill');
      }
    }
    document.getElementById('loginForm').addEventListener('submit', function() {
      const btn = document.getElementById('btnSubmit');
      const text = document.getElementById('btnText');
      btn.disabled = true;
      text.innerHTML = '<span class="spinner"></span> Sending Code...';
    });
  </script>
</body>

</html>