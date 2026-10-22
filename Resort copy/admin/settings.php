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
$admin_id = (int)$_SESSION["user_id"];

// ---------- 3. HANDLE PASSWORD UPDATE ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"]) && $_POST["action"] === "update_credentials") {
    $current_password = $_POST["current_password"] ?? "";
    $new_password = $_POST["new_password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $message = "All password fields are required.";
        $message_type = "error";
    } elseif ($new_password !== $confirm_password) {
        $message = "New passwords do not match.";
        $message_type = "error";
    } elseif (strlen($new_password) < 6) {
        $message = "New password must be at least 6 characters long.";
        $message_type = "error";
    } else {
        // Verify current password from DB
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $admin_id);
        $stmt->execute();
        $res = $stmt->get_result();
        
        if ($res->num_rows === 1) {
            $user_row = $res->fetch_assoc();
            if (password_verify($current_password, $user_row["password"])) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                $update_stmt->bind_param("si", $hashed_password, $admin_id);
                
                if ($update_stmt->execute()) {
                    $message = "Admin password successfully updated.";
                    $message_type = "success";
                } else {
                    $message = "Failed to update password. Please try again.";
                    $message_type = "error";
                }
                $update_stmt->close();
            } else {
                $message = "Incorrect current password entered.";
                $message_type = "error";
            }
        }
        $stmt->close();
    }
}

// ---------- 4. HELPERS ----------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

// Fetch current admin profile details
$admin_stmt = $conn->prepare("SELECT full_name, email, created_at FROM users WHERE id = ? LIMIT 1");
$admin_stmt->bind_param("i", $admin_id);
$admin_stmt->execute();
$admin_info = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

$current = "settings";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Settings | Pergola Resort Admin</title>
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
        <a href="resort.php">Resort Info</a>
        <a href="reports.php">Reports</a>
        <a href="settings.php" class="active">Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-link">Sign Out</a>
    </div>
</aside>

<!-- ============ MAIN CONTENT ============ -->
<main class="main-content">

    <header class="topbar">
        <div class="topbar-title">
            <span class="section-label">SYSTEM</span>
            <h1>Admin Settings</h1>
            <p>Manage your account credentials and security preferences.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="grid-2">
        <!-- Account Profile Summary Card -->
        <div class="card content-card">
            <h3>Administrator Profile</h3>
            <div class="admin-profile-details" style="margin-top: 20px;">
                <p><strong>Name:</strong> <?= e($admin_info["full_name"] ?? "Administrator") ?></p>
                <p style="margin-top: 10px;"><strong>Email Address:</strong> <?= e($admin_info["email"] ?? "") ?></p>
                <p style="margin-top: 10px;"><strong>Account Role:</strong> System Administrator</p>
                <p style="margin-top: 10px;"><strong>Member Since:</strong> <?= e(date("F d, Y", strtotime($admin_info["created_at"] ?? ""))) ?></p>
            </div>
        </div>

        <!-- Security & Password Update Card -->
        <div class="card content-card">
            <h3>Change Password</h3>
            <form method="POST" action="settings.php" class="admin-form" style="margin-top: 20px;">
                <input type="hidden" name="action" value="update_credentials">

                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="current_password">Current Password</label>
                    <input type="password" id="current_password" name="current_password" required placeholder="Enter current password">
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" required placeholder="At least 6 characters">
                </div>

                <div class="form-group" style="margin-bottom: 20px;">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required placeholder="Re-type new password">
                </div>

                <div class="form-actions">
                    <button type="submit" class="admin-btn primary">Update Password</button>
                </div>
            </form>
        </div>
    </section>

</main>
</body>
</html>