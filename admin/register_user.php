<?php
// 1. Include Database First
include "../api/db.php"; //

// 2. CHECK: Is this the first run? (System Setup Mode)
$result = $conn->query("SELECT COUNT(*) as total FROM users");
$row = $result->fetch_assoc();
$is_setup_mode = ($row['total'] == 0);

// 3. Security Logic
if ($is_setup_mode) {
    // ALLOW ACCESS: No users exist yet.
    $pageTitle = "System Setup - Create First Admin";
    $allowedRole = 'admin'; // First user MUST be admin
} else {
    // RESTRICT ACCESS: Users exist, so we require login security.
    // require '../includes/auth_check.php'; //
    
    // Optional: Add Role Check here if you have it
    // require '../includes/role_check.php'; 
    
    $pageTitle = "Admin Panel - Register User";
    $allowedRole = ''; // Admin can select any role
}

$message = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $full_name = trim($_POST['full_name']);
    $email     = trim($_POST['email']);
    $password  = $_POST['password'];
    
    // Force role to 'admin' if in setup mode, otherwise take selection
    $role = $is_setup_mode ? 'admin' : $_POST['role'];

    // 4. Hash Password (SHA-256 matches your login.php)
    $hashedPassword = hash('sha256', $password);

    // 5. Check duplicate email
    $checkEmail = $conn->prepare("SELECT email FROM users WHERE email = ?");
    $checkEmail->bind_param("s", $email);
    $checkEmail->execute();
    
    if ($checkEmail->get_result()->num_rows > 0) {
        $message = "<div class='alert error'>Error: Email already registered.</div>";
    } else {
        // 6. Create User
        $stmt = $conn->prepare("INSERT INTO users (full_name, email, password, role) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("ssss", $full_name, $email, $hashedPassword, $role);

        if ($stmt->execute()) {
            if ($is_setup_mode) {
                // Redirect to login if this was the setup account
                header("Location: ../login/login.php?setup_success=1");
                exit();
            } else {
                $message = "<div class='alert success'>User registered successfully!</div>";
            }
        } else {
            $message = "<div class='alert error'>Registration failed: " . $conn->error . "</div>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $pageTitle ?></title>
    <link rel="stylesheet" href="../assets/style.css"> 
    <style>
        body { font-family: sans-serif; background: #0f2027; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .reg-container { width: 100%; max-width: 450px; padding: 2rem; background: #1f2a38; border-radius: 8px; color: white; box-shadow: 0 4px 15px rgba(0,0,0,0.5); }
        .form-group { margin-bottom: 15px; }
        h2 { text-align: center; margin-bottom: 1.5rem; color: #00c6ff; }
        label { display: block; margin-bottom: 5px; font-size: 0.9rem; color: #ccc; }
        input, select { width: 100%; padding: 10px; border-radius: 4px; border: 1px solid #444; background: #2c3e50; color: white; box-sizing: border-box; }
        button { width: 100%; padding: 12px; background: linear-gradient(to right, #0072ff, #00c6ff); border: none; color: white; font-weight: bold; cursor: pointer; border-radius: 4px; margin-top: 10px; }
        button:hover { opacity: 0.9; }
        .alert { padding: 10px; margin-bottom: 15px; border-radius: 4px; text-align: center; }
        .success { background: rgba(39, 174, 96, 0.2); border: 1px solid #27ae60; color: #2ecc71; }
        .error { background: rgba(192, 57, 43, 0.2); border: 1px solid #c0392b; color: #e74c3c; }
        .setup-badge { background: #e67e22; color: white; padding: 4px 8px; border-radius: 4px; font-size: 0.8rem; display: inline-block; margin-bottom: 10px; }
    </style>
</head>
<body>
    <div class="reg-container">
        <?php if ($is_setup_mode): ?>
            <div style="text-align: center;"><span class="setup-badge">⚠️ SETUP MODE</span></div>
        <?php endif; ?>
        
        <h2><?= $pageTitle ?></h2>
        <?= $message ?>
        
        <form method="POST">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="Ex: System Admin" required>
            </div>
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="admin@slate.com" required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" required>
            </div>
            
            <div class="form-group">
                <label>User Role</label>
                <?php if ($is_setup_mode): ?>
                    <input type="text" value="Administrator" disabled style="opacity: 0.7; cursor: not-allowed;">
                    <input type="hidden" name="role" value="admin">
                    <small style="color: #e67e22;">First account must be an Administrator.</small>
                <?php else: ?>
                    <select name="role">
                        <option value="customer">Customer</option>
                        <option value="staff">Staff / Encoder</option>
                        <option value="admin">Administrator</option>
                    </select>
                <?php endif; ?>
            </div>
            
            <button type="submit">
                <?= $is_setup_mode ? 'Create First Admin' : 'Register User' ?>
            </button>
        </form>

        <?php if (!$is_setup_mode): ?>
            <p style="text-align: center; margin-top: 15px;">
                <a href="../public/dashboard.php" style="color: #00c6ff; text-decoration: none;">&larr; Back to Dashboard</a>
            </p>
        <?php else: ?>
             <p style="text-align: center; margin-top: 15px;">
                <a href="../login/login.php" style="color: #aaa; text-decoration: none; font-size: 0.9rem;">Already have an account? Login</a>
            </p>
        <?php endif; ?>
    </div>
</body>
</html>