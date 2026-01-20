<?php
$conn = new mysqli("localhost", "root", "", "coretransac");
if ($conn->connect_error) {
    die("Database connection failed");
}