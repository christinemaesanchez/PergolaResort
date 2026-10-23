<?php
// Start session if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require connection configuration from root directory
require_once __DIR__ . '/../config.php';

// Set standard response header to JSON
header('Content-Type: application/json; charset=UTF-8');

// Check user login session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit();
}