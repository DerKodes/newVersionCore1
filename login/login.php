<?php
session_start();
include "../api/db.php";

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
      $_SESSION['login_attempts'][$key] = 0; // Reset after lockout expires
    }
  }
}

// Handle Form Submit
if ($_SERVER["REQUEST_METHOD"] === "POST" && empty($errorMsg)) {

  // 1. Verify CSRF Token
  if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    die("❌ Security Error: Invalid CSRF Token. Please refresh the page.");
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
      if (hash('sha256', $password) === $user['password']) {
        // SUCCESS
        $_SESSION['login_attempts'][$key] = 0;
        $_SESSION['last_attempt_time'][$key] = 0;
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['Email'] = $user['email'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['login_success'] = true;

        header("Location: ../public/dashboard.php?login_success=1");
        exit();
      } else {
        // FAIL: Wrong Password
        $_SESSION['login_attempts'][$key]++;
        $_SESSION['last_attempt_time'][$key] = time();
        $errorMsg = "Invalid email or password.";
      }
    } else {
      // FAIL: User Not Found (Increment attempt anyway to prevent enumeration)
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

    /* Layout */
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

    /* Welcome Panel */
    .welcome-panel {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 2.5rem;
      background: linear-gradient(135deg, rgba(0, 114, 255, 0.2), rgba(0, 198, 255, 0.2));
      position: relative;
      overflow: hidden;
    }

    .welcome-panel::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: url('../assets/pattern.png');
      opacity: 0.1;
      /* Optional Pattern */
    }

    .welcome-panel h1 {
      font-size: 2.5rem;
      font-weight: 800;
      color: #ffffff;
      text-shadow: 0.125rem 0.125rem 0.5rem rgba(0, 0, 0, 0.6);
      text-align: center;
      letter-spacing: 1px;
      z-index: 2;
    }

    /* Login Panel */
    .login-panel {
      width: 28rem;
      padding: 4rem 3rem;
      background: rgba(22, 33, 49, 0.95);
      position: relative;
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
      color: #ffffff;
      font-size: 1.5rem;
      font-weight: 600;
      letter-spacing: 0.5px;
    }

    /* Error Alert */
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

    /* Inputs & Icons */
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
      pointer-events: none;
      transition: color 0.3s;
    }

    .input-group input {
      width: 100%;
      padding: 0.9rem 1rem 0.9rem 2.8rem;
      /* Space for icon */
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 0.375rem;
      color: white;
      font-size: 1rem;
      transition: all 0.3s ease;
    }

    .input-group input:focus {
      outline: none;
      border-color: #00c6ff;
      background: rgba(255, 255, 255, 0.1);
      box-shadow: 0 0 0 4px rgba(0, 198, 255, 0.1);
    }

    .input-group input:focus+i.icon-start {
      color: #00c6ff;
    }

    /* Toggle Password Eye */
    .toggle-password {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: rgba(255, 255, 255, 0.5);
      cursor: pointer;
      transition: color 0.2s;
    }

    .toggle-password:hover {
      color: white;
    }

    /* Button */
    button {
      width: 100%;
      padding: 0.9rem;
      margin-top: 0.5rem;
      background: linear-gradient(to right, #0072ff, #00c6ff);
      border: none;
      border-radius: 0.375rem;
      font-weight: 600;
      font-size: 1rem;
      color: white;
      cursor: pointer;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    button:hover {
      background: linear-gradient(to right, #0052cc, #009ee3);
      transform: translateY(-1px);
      box-shadow: 0 4px 15px rgba(0, 198, 255, 0.3);
    }

    button:disabled {
      opacity: 0.7;
      cursor: wait;
      pointer-events: none;
    }

    /* Loading Spinner */
    .spinner {
      display: inline-block;
      width: 1.2rem;
      height: 1.2rem;
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 50%;
      border-top-color: #fff;
      animation: spin 0.8s ease-in-out infinite;
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

    /* Footer */
    footer {
      text-align: center;
      padding: 1.5rem;
      color: rgba(255, 255, 255, 0.5);
      font-size: 0.8rem;
    }

    /* Responsive */
    @media (max-width: 48rem) {
      .login-container {
        flex-direction: column;
        max-width: 90%;
      }

      .login-panel {
        width: 100%;
        padding: 3rem 2rem;
      }

      .welcome-panel {
        padding: 3rem 1rem;
      }
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
            <div class="error-alert">
              <i class="bi bi-exclamation-triangle-fill"></i> <?= $errorMsg ?>
            </div>
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

            <button type="submit" id="btnSubmit">
              <span id="btnText">Log In</span>
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <footer>
    &copy; <span id="currentYear"></span> SLATE Freight Management System. All rights reserved.
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script>
    document.getElementById('currentYear').textContent = new Date().getFullYear();

    // 1. Password Toggle Logic
    function togglePassword() {
      const input = document.getElementById('passwordInput');
      const icon = document.querySelector('.toggle-password');

      if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('bi-eye-slash-fill');
        icon.classList.add('bi-eye-fill');
      } else {
        input.type = 'password';
        icon.classList.remove('bi-eye-fill');
        icon.classList.add('bi-eye-slash-fill');
      }
    }

    // 2. Loading State on Submit
    document.getElementById('loginForm').addEventListener('submit', function() {
      const btn = document.getElementById('btnSubmit');
      const text = document.getElementById('btnText');

      btn.disabled = true;
      text.innerHTML = '<span class="spinner"></span> Logging in...';
    });

    // 3. Logout Toast
    <?php if (isset($_SESSION['logout_success']) && $_SESSION['logout_success']): ?>
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Logged out successfully',
        showConfirmButton: false,
        timer: 3000,
        timerProgressBar: true,
        background: '#1f2a38',
        color: '#fff'
      });
      <?php unset($_SESSION['logout_success']); ?>
    <?php endif; ?>

    // 4. Lockout Countdown
    <?php if (!empty($errorMsg) && $remaining > 0): ?>
      let remaining = <?= $remaining ?>;
      const alertBox = document.querySelector(".error-alert");
      const btn = document.getElementById('btnSubmit');

      // Disable button immediately
      btn.disabled = true;
      btn.style.opacity = "0.5";

      const countdown = setInterval(() => {
        if (remaining <= 0) {
          clearInterval(countdown);
          location.reload();
        } else {
          if (alertBox) {
            alertBox.innerHTML = `<i class="bi bi-clock-history"></i> Too many attempts. Wait ${remaining}s`;
          }
          remaining--;
        }
      }, 1000);
    <?php endif; ?>
  </script>
</body>

</html>