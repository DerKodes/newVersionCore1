<?php
session_start();
session_unset();   // remove all session variables
session_destroy(); // destroy the session

// Optional: add success flag for SweetAlert
session_start();
$_SESSION['logout_success'] = true;

header("Location: login.php");
exit();
