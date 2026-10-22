<?php
// ---------- 1. CONNECTION + SESSION ----------
require_once "../config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- 2. ADMIN AUTHENTICATION ----------
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit();
}

$message = "";
$message_type = "";

// ---------- 3. HANDLE UPDATE ACTION ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "update_resort_info") {
    $resort_name = trim($_POST["resort_name"]);
    $tagline = trim($_POST["tagline"]);
    $contact_number = trim($_POST["contact_number"]);
    $email = trim($_POST["email"]);
    $address = trim($_POST["address"]);
    $description = trim($_POST["description"]);

    // Check if a record already exists in the resort_info table
    $check = $conn->query("SELECT id FROM resort_info LIMIT 1");
    
    if ($check->num_rows > 0) {
        // Update existing record
        $stmt = $conn->prepare("UPDATE resort_info SET resort_name = ?, tagline = ?, contact_number = ?, email = ?, address = ?, description = ? LIMIT 1");
        $stmt->bind_param("ssssss", $resort_name, $tagline, $contact_number, $email, $address, $description);
    } else {
        // Insert if none exists
        $stmt = $conn->prepare("INSERT INTO resort_info (resort_name, tagline, contact_number, email, address, description) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssss", $resort_name, $tagline, $contact_number, $email, $address, $description);
    }

    if ($stmt->execute()) {
        $message = "Resort information successfully updated.";
        $message_type = "success";
    } else {
        $message = "Failed to update resort information.";
        $message_type = "error";
    }
    $stmt->close();
}

// ---------- 4. HELPERS ----------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------- 5. FETCH EXISTING RESORT INFO ----------
$resort = [
    "resort_name" => "Pergola Resort",
    "tagline" => "Resort Management System",
    "contact_number" => "",
    "email" => "",
    "address" => "",
    "description" => ""
];

$result = $conn->query("SELECT * FROM resort_info LIMIT 1");
if ($result && $result->num_rows > 0) {
    $resort = $result->fetch_assoc();
}

$current = "resort";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resort Info | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
</head>
<body>

<!-- ============ SIDEBAR ============ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h2>Pergola</h2>
        <p>Resort Management</p>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="reservations.php">Reservations</a>
        <a href="calendar.php">Calendar</a>
        <a href="accommodations.php">Accommodations</a>
        <a href="customers.php">Customers</a>
        <a href="resort.php" class="active">Resort Info</a>
        <a href="reports.php">Reports</a>
        <a href="settings.php">Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-link">Sign Out</a>
    </div>
</aside>

<!-- ============ MAIN CONTENT ============ -->
<main class="main-content">

    <header class="topbar">
        <div class="topbar-title">
            <span class="section-label">CONFIGURATION</span>
            <h1>Resort Information</h1>
            <p>Manage resort details, contact information, and overview descriptions.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- Resort Info Form Card -->
    <section class="card content-card full-width-card">
        <div class="card-header-flex">
            <h3>Resort Profile Settings</h3>
        </div>
        <form method="POST" action="resort.php" class="admin-form" style="margin-top: 20px;">
            <input type="hidden" name="action" value="update_resort_info">

            <div class="form-grid-2">
                <div class="form-group">
                    <label for="resort_name">Resort Name</label>
                    <input type="text" id="resort_name" name="resort_name" required value="<?= e($resort["resort_name"] ?? "") ?>">
                </div>
                <div class="form-group">
                    <label for="tagline">Tagline or Subtitle</label>
                    <input type="text" id="tagline" name="tagline" value="<?= e($resort["tagline"] ?? "") ?>">
                </div>
            </div>

            <div class="form-grid-2" style="margin-top: 15px;">
                <div class="form-group">
                    <label for="contact_number">Contact Number</label>
                    <input type="text" id="contact_number" name="contact_number" value="<?= e($resort["contact_number"] ?? "") ?>" placeholder="e.g. +63 912 345 6789">
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" value="<?= e($resort["email"] ?? "") ?>" placeholder="e.g. info@pergolaresort.com">
                </div>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label for="address">Location / Address</label>
                <input type="text" id="address" name="address" value="<?= e($resort["address"] ?? "") ?>" placeholder="Enter the complete resort address">
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label for="description">Resort Description (About Us)</label>
                <textarea id="description" name="description" rows="5" placeholder="Write a brief overview of the resort..."><?= e($resort["description"] ?? "") ?></textarea>
            </div>

            <div class="form-actions" style="margin-top: 25px;">
                <button type="submit" class="admin-btn primary">Save Changes</button>
            </div>
        </form>
    </section>

</main>
</body>
</html>