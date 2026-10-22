<?php
// Diagnostic page: local machine only (never expose DB details to visitors). Delete before going live.
if (!in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true)) { http_response_code(403); exit("Local access only."); }
require_once "config.php";

echo "<h2>Database Connection & Table Check</h2>";

// Test connection
if ($conn) {
    echo "<p style='color: green;'>Successfully connected to database: <strong>" . $conn->host_info . "</strong></p>";
}

// Test table accessibility
$tables = ['users', 'accommodations', 'reservations', 'payments', 'addons'];
echo "<ul>";
foreach ($tables as $table) {
    $result = $conn->query("SHOW TABLES LIKE '$table'");
    if ($result->num_rows > 0) {
        echo "<li>Table <code>$table</code> exists. </li>";
    } else {
        echo "<li style='color: red;'> Table <code>$table</code> is missing!</li>";
    }
}
echo "</ul>";
?>