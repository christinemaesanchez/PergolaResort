<?php
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