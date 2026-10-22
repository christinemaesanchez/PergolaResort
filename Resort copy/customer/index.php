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
    $location = trim($_POST["location"]);
    $description = trim($_POST["description"]);

    // Handle Hero Image Upload
    $hero_image = null;
    if (isset($_FILES["hero_image"]) && $_FILES["hero_image"]["error"] === UPLOAD_ERR_OK) {
        $file_tmp = $_FILES["hero_image"]["tmp_name"];
        $file_name = time() . "_" . basename($_FILES["hero_image"]["name"]);
        $upload_dir = "../uploads/";
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        if (move_uploaded_file($file_tmp, $upload_dir . $file_name)) {
            $hero_image = $file_name;
        }
    }

    // Check if record exists
    $check = $conn->query("SELECT id, hero_image FROM resort_info LIMIT 1");
    
    if ($check && $check->num_rows > 0) {
        $existing = $check->fetch_assoc();
        if (!$hero_image) {
            $hero_image = $existing["hero_image"]; // Keep old image if none uploaded
        }

        $stmt = $conn->prepare("UPDATE resort_info SET resort_name = ?, tagline = ?, contact_number = ?, email = ?, location = ?, description = ?, hero_image = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param("sssssss", $resort_name, $tagline, $contact_number, $email, $location, $description, $hero_image);
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO resort_info (resort_name, tagline, contact_number, email, location, description, hero_image) VALUES (?, ?, ?, ?, ?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sssssss", $resort_name, $tagline, $contact_number, $email, $location, $description, $hero_image);
        }
    }

    if ($stmt && $stmt->execute()) {
        $message = "Resort information and pictures successfully updated.";
        $message_type = "success";
    } else {
        $message = "Database Error: " . $conn->error;
        $message_type = "error";
    }
    if ($stmt) $stmt->close();
}

// ---------- 4. HELPERS ----------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// ---------- 5. FETCH EXISTING RESORT INFO ----------
$resort = [
    "resort_name" => "Pergola Gardens & Resort",
    "tagline" => "Experience Tranquility & Luxury",
    "contact_number" => "",
    "email" => "",
    "location" => "",
    "description" => "",
    "hero_image" => ""
];

$result = $conn->query("SELECT * FROM resort_info LIMIT 1");
if ($result && $result->num_rows > 0) {
    $fetched = $result->fetch_assoc();
    foreach ($fetched as $k => $v) {
        if ($v !== null) $resort[$k] = $v;
    }
}
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
        <a href="payments.php">Payments</a>
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
            <h1>Resort Information & Media</h1>
            <p>Manage resort profile details, contact info, and homepage banner images.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>" style="padding: 12px; margin-bottom: 20px; border-radius: 4px; background: <?= $message_type === 'success' ? '#d4edda' : '#f8d7da' ?>; color: <?= $message_type === 'success' ? '#155724' : '#721c24' ?>;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="card content-card full-width-card" style="background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.05);">
        <div class="card-header-flex">
            <h3>Resort Profile Settings</h3>
        </div>
        <form method="POST" action="resort.php" enctype="multipart/form-data" class="admin-form" style="margin-top: 20px;">
            <input type="hidden" name="action" value="update_resort_info">

            <div class="form-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label for="resort_name"><strong>Resort Name</strong></label>
                    <input type="text" id="resort_name" name="resort_name" required value="<?= e($resort["resort_name"]) ?>" style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div class="form-group">
                    <label for="tagline"><strong>Tagline or Subtitle</strong></label>
                    <input type="text" id="tagline" name="tagline" value="<?= e($resort["tagline"] ?? "") ?>" placeholder="e.g. Experience Tranquility & Luxury" style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <div class="form-grid-2" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 15px;">
                <div class="form-group">
                    <label for="contact_number"><strong>Contact Number</strong></label>
                    <input type="text" id="contact_number" name="contact_number" value="<?= e($resort["contact_number"] ?? "") ?>" placeholder="e.g. +63 912 345 6789" style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
                <div class="form-group">
                    <label for="email"><strong>Email Address</strong></label>
                    <input type="email" id="email" name="email" value="<?= e($resort["email"] ?? "") ?>" placeholder="e.g. info@pergolaresort.com" style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;">
                </div>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label for="location"><strong>Location / Address</strong></label>
                <input type="text" id="location" name="location" value="<?= e($resort["location"] ?? "") ?>" placeholder="Enter complete resort location" style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;">
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label for="hero_image"><strong>Homepage Hero / Banner Picture</strong></label>
                <?php if (!empty($resort["hero_image"])): ?>
                    <div style="margin: 10px 0;">
                        <img src="../uploads/<?= e($resort["hero_image"]) ?>" alt="Hero Image" style="max-height: 140px; border-radius: 6px; border: 1px solid #ddd;">
                    </div>
                <?php endif; ?>
                <input type="file" id="hero_image" name="hero_image" accept="image/*" style="margin-top: 5px; display: block;">
                <small style="color: #666;">Upload a high-quality picture for the main website header background.</small>
            </div>

            <div class="form-group" style="margin-top: 15px;">
                <label for="description"><strong>Resort Overview (About Us Section)</strong></label>
                <textarea id="description" name="description" rows="5" placeholder="Write an overview of the resort..." style="width: 100%; padding: 10px; margin-top: 5px; border: 1px solid #ccc; border-radius: 4px;"><?= e($resort["description"] ?? "") ?></textarea>
            </div>

            <div class="form-actions" style="margin-top: 25px;">
                <button type="submit" class="admin-btn primary" style="background: #2a5298; color: #fff; padding: 12px 24px; border: none; border-radius: 4px; font-weight: bold; cursor: pointer;">Save Changes</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>