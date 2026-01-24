<?php
session_start();
$_SESSION = array(); // Clear all session variables
session_destroy();
session_start();
$_SESSION['logout_success'] = true;
header("Location: login.php");
exit();
?>