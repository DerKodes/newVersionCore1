<?php
// Force secure cookie settings
ini_set('session.cookie_httponly', 1); // Prevent JavaScript access to session
ini_set('session.use_only_cookies', 1); // Disallow session ID in URL
ini_set('session.use_strict_mode', 1);  // Reject uninitialized session IDs

// If your site runs on HTTPS, enforce secure cookies
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
  ini_set('session.cookie_secure', 1);
}

// Start session
session_start();

// OPTIONAL: regenerate ID on each request after login
if (!isset($_SESSION["initiated"])) {
  session_regenerate_id(true);
  $_SESSION["initiated"] = true;
}

// Require login
if (!isset($_SESSION["Email"])) {
  header("Location: login/login.php");
  exit();
}
