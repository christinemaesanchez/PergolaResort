<?php
// ==============================================================
// config.php
// Connects the system to the MySQL database, configures SMTP,
// and starts the session that every other page relies on.
// ==============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Database Credentials ----
$host     = "localhost";
$username = "root";
$password = "";
$database = "pergola_resort_db";

// ---- Open Database Connection ----
$conn = new mysqli($host, $username, $password, $database);

// Stop execution if connection fails
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

// Set charset to utf8mb4 for proper character encoding
$conn->set_charset("utf8mb4");

// ---- Mailer / SMTP Configuration ----
// Replace 'YOUR_GMAIL_APP_PASSWORD' with your generated Google App Password
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_USER', 'pergola.resort.system@gmail.com');
define('SMTP_PASS', 'nerq ltan bind pcka'); // Put your app password here
define('SMTP_PORT', 587);
define('SMTP_FROM_NAME', 'Pergola Resort');

?>