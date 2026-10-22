<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";   // csrf_token(), csrf_valid()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit();
}

ob_start();
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

/* ---------- Helpers ---------- */
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('peso')) {
    function peso($n) { return "₱" . number_format((float)$n, 2); }
}

$is_xhr = (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "XMLHttpRequest");

function respond($msg, $type) {
    global $is_xhr;
    if ($is_xhr) {
        while (ob_get_level()) { ob_end_clean(); }
        header("Content-Type: application/json");
        if ($type === "error") {
            echo json_encode(["ok" => false, "message" => $msg]);
        } else {
            $_SESSION["flash_msg"] = $msg;
            $_SESSION["flash_type"] = $type;
            echo json_encode(["ok" => true, "message" => $msg]);
        }
        exit();
    }
    $_SESSION["flash_msg"] = $msg;
    $_SESSION["flash_type"] = $type;
    header("Location: accommodations.php");
    exit();
}

/* ---------- Database Schema Setup (Multi-Images & Add-ons) ---------- */
try {
    $cols = [];
    $res = $conn->query("SHOW COLUMNS FROM accommodations");
    while ($c = $res->fetch_assoc()) { $cols[$c["Field"]] = true; }

    if (!isset($cols["image_urls"])) {
        $conn->query("ALTER TABLE accommodations ADD COLUMN image_urls TEXT NULL");
    }
    if (!isset($cols["capacity"])) {
        $conn->query("ALTER TABLE accommodations ADD COLUMN capacity INT NOT NULL DEFAULT 2");
    }
    if (!isset($cols["type"])) {
        $conn->query("ALTER TABLE accommodations ADD COLUMN type VARCHAR(50) DEFAULT 'Room'");
    }
    if (!isset($cols["is_available"])) {
        $conn->query("ALTER TABLE accommodations ADD COLUMN is_available TINYINT DEFAULT 1");
    }

    $conn->query("CREATE TABLE IF NOT EXISTS resort_addons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        type ENUM('per_night', 'one_time') DEFAULT 'one_time',
        description VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch (mysqli_sql_exception $ex) {
    die("Database setup error: " . e($ex->getMessage()));
}

/* ---------- Flash message ---------- */
$message = $_SESSION["flash_msg"] ?? "";
$message_type = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_msg"], $_SESSION["flash_type"]);

/* ---------- Handle POST Actions ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        respond("Upload too large for the server (post_max_size = " . ini_get("post_max_size") . "). Use smaller images.", "error");
    }

    $action = $_POST["action"] ?? "";

    if (!csrf_valid($_POST["csrf"] ?? null)) {
        respond("Your session expired. Please refresh the page and try again.", "error");
    }

    /* ===== A. SAVE ACCOMMODATION (1 to 4 Images) ===== */
    if ($action === "save_accommodation") {
        $id = (int)($_POST["id"] ?? 0);
        $name = trim($_POST["name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $price = filter_var($_POST["price"] ?? 0, FILTER_VALIDATE_FLOAT);
        $capacity = filter_var($_POST["capacity"] ?? 0, FILTER_VALIDATE_INT);
        $is_available = (($_POST["is_available"] ?? "1") === "0") ? 0 : 1;
        $type_val = trim($_POST["type"] ?? "Room");
        
        $existing_images = json_decode($_POST["existing_images_json"] ?? "[]", true) ?: [];
        $uploaded_files = [];
        $error = "";

        if ($name === "") {
            $error = "Accommodation name is required.";
        } elseif ($price === false || $price <= 0) {
            $error = "Please enter a valid price greater than zero.";
        } elseif ($capacity === false || $capacity <= 0) {
            $error = "Please enter a valid guest capacity.";
        }

        // Handle up to 4 image files upload
        if ($error === "" && isset($_FILES["image_files"])) {
            $upload_dir = __DIR__ . "/../images/accommodations/";
            if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
                $error = "Could not create upload folder: images/accommodations/";
            }

            $files = $_FILES["image_files"];
            $file_count = count($files["name"]);

            for ($i = 0; $i < $file_count && $i < 4; $i++) {
                if ($files["error"][$i] === UPLOAD_ERR_OK) {
                    if ($files["size"][$i] > 5 * 1024 * 1024) {
                        $error = "One or more images exceed the 5MB limit.";
                        break;
                    }
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = finfo_file($finfo, $files["tmp_name"][$i]);
                    finfo_close($finfo);

                    $mime_to_ext = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
                    if (!isset($mime_to_ext[$mime])) {
                        $error = "Invalid image type. Only JPG, PNG, and WEBP are accepted.";
                        break;
                    }

                    $new_name = "acc_" . time() . "_" . mt_rand(1000, 9999) . "." . $mime_to_ext[$mime];
                    if (move_uploaded_file($files["tmp_name"][$i], $upload_dir . $new_name)) {
                        $uploaded_files[] = "images/accommodations/" . $new_name;
                    }
                }
            }
        }

        if ($error !== "") {
            respond($error, "error");
        }

        $final_images = array_slice(array_merge($existing_images, $uploaded_files), 0, 4);
        $images_json = json_encode($final_images);

        try {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE accommodations SET name = ?, description = ?, price = ?, capacity = ?, image_urls = ?, type = ?, is_available = ? WHERE id = ?");
                $stmt->bind_param("ssdissii", $name, $description, $price, $capacity, $images_json, $type_val, $is_available, $id);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("INSERT INTO accommodations (name, description, price, capacity, image_urls, type, is_available) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdsssi", $name, $description, $price, $capacity, $images_json, $type_val, $is_available);
                $stmt->execute();
                $stmt->close();
            }
            respond("Accommodation saved successfully!", "success");
        } catch (mysqli_sql_exception $ex) {
            respond("Database error: " . $ex->getMessage(), "error");
        }
    }

    /* ===== B. DELETE ACCOMMODATION ===== */
    if ($action === "delete_accommodation") {
        $id = (int)($_POST["id"] ?? 0);
        try {
            $stmt = $conn->prepare("DELETE FROM accommodations WHERE id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            respond("Accommodation deleted successfully.", "success");
        } catch (mysqli_sql_exception $ex) {
            respond("Unable to delete accommodation; it may be tied to active reservations.", "error");
        }
    }

    /* ===== C. SAVE ADD-ON / FEE ===== */
    if ($action === "save_addon") {
        $addon_name = trim($_POST["addon_name"] ?? "");
        $addon_price = filter_var($_POST["addon_price"] ?? 0, FILTER_VALIDATE_FLOAT);
        $addon_type = $_POST["addon_type"] ?? "one_time";
        $addon_desc = trim($_POST["addon_description"] ?? "");

        if ($addon_name === "" || $addon_price === false || $addon_price < 0) {
            respond("Please provide a valid add-on name and price.", "error");
        }

        $stmt = $conn->prepare("INSERT INTO resort_addons (name, price, type, description) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sdss", $addon_name, $addon_price, $addon_type, $addon_desc);
        $stmt->execute();
        $stmt->close();
        respond("Add-on / Fee added successfully!", "success");
    }

    /* ===== D. DELETE ADD-ON / FEE ===== */
    if ($action === "delete_addon") {
        $addon_id = (int)($_POST["addon_id"] ?? 0);
        $stmt = $conn->prepare("DELETE FROM resort_addons WHERE id = ?");
        $stmt->bind_param("i", $addon_id);
        $stmt->execute();
        $stmt->close();
        respond("Add-on removed successfully.", "success");
    }
}

/* ---------- Load Data ---------- */
$edit_data = null;
if (isset($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];
    $edit_stmt = $conn->prepare("SELECT * FROM accommodations WHERE id = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_data = $edit_stmt->get_result()->fetch_assoc();
    $edit_stmt->close();
}

$accommodations = $conn->query("SELECT * FROM accommodations ORDER BY id DESC");
$addons = $conn->query("SELECT * FROM resort_addons ORDER BY id DESC");
$current = "accommodations";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accommodations & Fees | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        /* FORCE HIDE ALL ICONS, SB-TOGGLE, AND NOTIFICATION BELLS */
        .sidebar-nav a i, .sidebar-nav a svg, .sidebar-nav span i,
        .sb-toggle, .sidebar-collapse-btn, .ui-ico,
        .nf-wrap, .nf-admin, .topbar-bell, .notification-bell, .topbar-icons { 
            display: none !important; 
        }
        
        .progress-container { display: none; margin-top: 15px; background: #f7f5ee; border-radius: 8px; padding: 15px; border: 1px solid #e3dec9; }
        .progress-bar-track { width: 100%; background: #e3dec9; border-radius: 6px; height: 12px; overflow: hidden; margin-top: 8px; }
        .progress-bar-fill { width: 0%; height: 100%; background: #6f8761; transition: width 0.3s ease; }
        .progress-status-text { font-size: 13px; font-weight: 600; color: #344a30; }

        .table-thumb-wrap { display: flex; gap: 4px; }
        .table-thumb { width: 45px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #e3dec9; }
        .thumb-placeholder { font-size: 10px; color: #888; background: #f7f5ee; padding: 8px; border-radius: 4px; text-align: center; }
        
        .action-link { font-weight: 600; font-size: 13px; text-decoration: none; padding: 5px 10px; border-radius: 6px; transition: all 0.2s; }
        .action-link.edit { background: #f0ede4; color: #344a30; }
        .action-link.edit:hover { background: #e3dec9; }
        .action-link.delete { background: #fbeae8; color: #9c2e25; border: 1px solid #f5c7c2; }
        .action-link.delete:hover { background: #f7dcd7; }
        .table-actions { display: flex; gap: 8px; align-items: center; }

        .page-tabs { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 1px solid #e3dec9; padding-bottom: 15px; }
        .page-tab-btn { padding: 10px 20px; font-weight: 600; text-decoration: none; border-radius: 8px; color: #555; background: #f0ede4; transition: all 0.2s; font-size: 13.5px; }
        .page-tab-btn.active { background: #6f8761; color: #fff; }
    </style>
    <link rel="stylesheet" href="../css/ui.css">
    <script>
        // Prevent JS from re-injecting icons or toggle elements into sidebar/topbar
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('.sidebar-nav a i, .sidebar-nav a svg, .sb-toggle, .nf-wrap').forEach(el => el.remove());
        });
        try{if(localStorage.getItem("pergolaSidebar")==="1"&&window.innerWidth>768)document.documentElement.classList.add("sb-collapsed")}catch(e){}
    </script>
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
        <a href="accommodations.php" class="active">Accommodations</a>
        <a href="customers.php">Customers</a>
        <a href="resort.php">Resort Info</a>
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
            <span class="section-label">INVENTORY & FEES</span>
            <h1>Accommodations & Add-ons Management</h1>
            <p>Configure rooms, suites, cottages, photo galleries, and extra resort fees.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>" style="padding:12px; margin-bottom:20px; border-radius:8px; background:<?= $message_type === 'success' ? '#e5f4e7' : '#fbeae8' ?>; color:<?= $message_type === 'success' ? '#256030' : '#9c2e25' ?>; border: 1px solid <?= $message_type === 'success' ? '#c2e2c6' : '#f5c7c2' ?>;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- Tabs for Accommodations vs Add-ons -->
    <div class="page-tabs">
        <a href="accommodations.php?tab=accommodations" class="page-tab-btn <?= ($_GET['tab'] ?? '') !== 'addons' ? 'active' : '' ?>">Accommodations Inventory</a>
        <a href="accommodations.php?tab=addons" class="page-tab-btn <?= ($_GET['tab'] ?? '') === 'addons' ? 'active' : '' ?>">Add-ons & Fees</a>
    </div>

    <?php if (($_GET['tab'] ?? '') === 'addons'): ?>
        <!-- ================= ADD-ONS & FEES TAB ================= -->
        <section class="card content-card" style="margin-bottom: 30px;">
            <h3 style="color: #344a30; margin-bottom: 20px;">Add New Fee or Add-on</h3>
            <form method="POST" action="accommodations.php?tab=addons" class="admin-form">
                <input type="hidden" name="action" value="save_addon">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="addon_name">Add-on / Fee Name</label>
                        <input type="text" id="addon_name" name="addon_name" required placeholder="e.g. Extra Bed, Breakfast, Airport Pickup">
                    </div>
                    <div class="form-group">
                        <label for="addon_price">Price (₱)</label>
                        <input type="number" step="0.01" id="addon_price" name="addon_price" required placeholder="e.g. 500">
                    </div>
                </div>
                <div class="form-grid-2" style="margin-top: 15px;">
                    <div class="form-group">
                        <label for="addon_type">Charge Type</label>
                        <select id="addon_type" name="addon_type">
                            <option value="one_time">One-time Fee</option>
                            <option value="per_night">Per Night</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="addon_description">Description (Optional)</label>
                        <input type="text" id="addon_description" name="addon_description" placeholder="Brief details...">
                    </div>
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" class="admin-btn primary">Save Add-on / Fee</button>
                </div>
            </form>
        </section>

        <section class="card content-card full-width-card">
            <div class="card-header-flex">
                <h3>Resort Add-ons & Fees List</h3>
                <span class="record-count"><?= $addons->num_rows ?> items</span>
            </div>
            <div class="table-wrap">
            <?php if ($addons->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Price</th>
                            <th>Type</th>
                            <th>Description</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($ad = $addons->fetch_assoc()): ?>
                        <tr>
                            <td>#<?= (int)$ad["id"] ?></td>
                            <td><strong><?= e($ad["name"]) ?></strong></td>
                            <td><strong><?= peso($ad["price"]) ?></strong></td>
                            <td><?= $ad["type"] === 'per_night' ? 'Per Night' : 'One-time' ?></td>
                            <td><?= e($ad["description"]) ?></td>
                            <td>
                                <form method="POST" action="accommodations.php?tab=addons" onsubmit="return confirm('Delete this add-on?');" style="margin: 0;">
                                    <input type="hidden" name="action" value="delete_addon">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="addon_id" value="<?= (int)$ad["id"] ?>">
                                    <button type="submit" class="action-link delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No add-ons or fees configured yet.</p>
            <?php endif; ?>
            </div>
        </section>

    <?php else: ?>
        <!-- ================= ACCOMMODATIONS TAB ================= -->
        <section class="card content-card" style="margin-bottom: 30px;">
            <h3 style="color: #344a30; margin-bottom: 20px;"><?= $edit_data ? "Edit Accommodation #" . (int)$edit_data["id"] : "Add New Accommodation (Up to 4 Photos)" ?></h3>
            <form id="accommodationForm" method="POST" action="accommodations.php" enctype="multipart/form-data" class="admin-form">
                <input type="hidden" name="action" value="save_accommodation">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="id" value="<?= (int)$edit_data["id"] ?>">
                    <?php 
                        $existing_imgs = json_decode($edit_data["image_urls"] ?? "[]", true);
                        if (empty($existing_imgs) && !empty($edit_data["image"])) { $existing_imgs = [$edit_data["image"]]; }
                    ?>
                    <input type="hidden" name="existing_images_json" value="<?= e(json_encode($existing_imgs)) ?>">
                <?php endif; ?>

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="name">Accommodation Name</label>
                        <input type="text" id="name" name="name" required value="<?= e($edit_data["name"] ?? "") ?>" placeholder="e.g. Standard Room">
                    </div>
                    <div class="form-group">
                        <label for="price">Price per Night (₱)</label>
                        <input type="number" step="0.01" id="price" name="price" required value="<?= e($edit_data["price"] ?? "") ?>" placeholder="e.g. 3800">
                    </div>
                </div>

                <div class="form-grid-2" style="margin-top: 15px;">
                    <div class="form-group">
                        <label for="capacity">Max Capacity (Guests)</label>
                        <input type="number" id="capacity" name="capacity" required value="<?= e($edit_data["capacity"] ?? "") ?>" placeholder="e.g. 2">
                    </div>
                    <div class="form-group">
                        <label for="image_files">Upload Photos (Select up to 4 images)</label>
                        <input type="file" id="image_files" name="image_files[]" accept="image/png, image/jpeg, image/webp" multiple class="file-input">
                        <?php if ($edit_data && !empty($existing_imgs)): ?>
                            <div style="display: flex; gap: 8px; margin-top: 8px;">
                                <?php foreach ($existing_imgs as $ei): ?>
                                    <img src="../<?= e($ei) ?>" style="width: 50px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #ccc;">
                                <?php endforeach; ?>
                            </div>
                            <span class="sub-text" style="display:block; margin-top:4px;">New uploads will append or replace existing photos.</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="form-grid-2" style="margin-top: 15px;">
                    <div class="form-group">
                        <label for="is_available">Availability for Booking</label>
                        <?php $cur_avail = (int)($edit_data["is_available"] ?? 1); ?>
                        <select id="is_available" name="is_available">
                            <option value="1" <?= $cur_avail === 1 ? "selected" : "" ?>>Available</option>
                            <option value="0" <?= $cur_avail === 0 ? "selected" : "" ?>>Unavailable (customers cannot book)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="type">Type</label>
                        <?php $cur_type = $edit_data["type"] ?? "Room"; ?>
                        <select id="type" name="type">
                            <?php foreach (["Room", "Cottage", "Suite", "Villa", "Function Hall", "Other"] as $opt): ?>
                                <option value="<?= e($opt) ?>" <?= $cur_type === $opt ? "selected" : "" ?>><?= e($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 15px;">
                    <label for="description">Description</label>
                    <textarea id="description" name="description" rows="3" placeholder="Describe features, amenities, and layout..."><?= e($edit_data["description"] ?? "") ?></textarea>
                </div>

                <div class="progress-container" id="progressContainer">
                    <div class="progress-status-text" id="progressStatusText">Preparing upload...</div>
                    <div class="progress-bar-track">
                        <div class="progress-bar-fill" id="progressBarFill"></div>
                    </div>
                </div>

                <div class="form-actions" style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="submit" id="submitBtn" class="admin-btn primary"><?= $edit_data ? "Update Accommodation" : "Save Accommodation" ?></button>
                    <?php if ($edit_data): ?>
                        <a href="accommodations.php" class="admin-btn secondary">Cancel Edit</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>

        <section class="card content-card full-width-card">
            <div class="card-header-flex">
                <h3>Current Inventory</h3>
                <span class="record-count"><?= $accommodations->num_rows ?> items listed</span>
            </div>
            <div class="table-wrap">
            <?php if ($accommodations->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr>
                            <th>Photos (1-4)</th>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Capacity</th>
                            <th>Price / Night</th>
                            <th>Status</th>
                            <th>Description</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $accommodations->fetch_assoc()):
                            $row_imgs = json_decode($row["image_urls"] ?? "[]", true);
                            if (empty($row_imgs) && !empty($row["image"])) { $row_imgs = [$row["image"]]; }
                        ?>
                        <tr>
                            <td>
                                <?php if (!empty($row_imgs)): ?>
                                    <div class="table-thumb-wrap">
                                        <?php foreach ($row_imgs as $ri): ?>
                                            <img src="../<?= e($ri) ?>" alt="Preview" class="table-thumb">
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="thumb-placeholder">No Image</div>
                                <?php endif; ?>
                            </td>
                            <td><strong>#<?= (int)$row["id"] ?></strong></td>
                            <td><strong><?= e($row["name"]) ?></strong></td>
                            <td><?= (int)$row["capacity"] ?> Persons</td>
                            <td><strong><?= peso($row["price"]) ?></strong></td>
                            <td><?= ((int)($row["is_available"] ?? 1) === 1) ? "Available" : "Unavailable" ?></td>
                            <td><span class="desc-preview"><?= e($row["description"]) ?></span></td>
                            <td>
                                <div class="table-actions">
                                    <a href="accommodations.php?edit=<?= (int)$row["id"] ?>" class="action-link edit">Edit</a>
                                    <form method="POST" action="accommodations.php" onsubmit="return confirm('Delete this accommodation?');" style="margin: 0;">
                                        <input type="hidden" name="action" value="delete_accommodation">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$row["id"] ?>">
                                        <button type="submit" class="action-link delete">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No accommodations added yet.</p>
            <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</main>

<script>
document.getElementById('accommodationForm')?.addEventListener('submit', function (e) {
    e.preventDefault();

    const formData = new FormData(this);
    const submitBtn = document.getElementById('submitBtn');
    const progressContainer = document.getElementById('progressContainer');
    const progressBarFill = document.getElementById('progressBarFill');
    const progressStatusText = document.getElementById('progressStatusText');

    function fail(msg) {
        submitBtn.disabled = false;
        progressStatusText.innerText = msg;
        progressBarFill.style.width = '100%';
        progressBarFill.style.backgroundColor = '#9c2e25';
    }

    submitBtn.disabled = true;
    progressContainer.style.display = 'block';
    progressBarFill.style.backgroundColor = '#6f8761';
    progressBarFill.style.width = '5%';
    progressStatusText.innerText = 'Uploading... 0%';

    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'accommodations.php', true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

    xhr.upload.onprogress = function (ev) {
        if (ev.lengthComputable) {
            const pct = Math.round((ev.loaded / ev.total) * 90);
            progressBarFill.style.width = pct + '%';
            progressStatusText.innerText = 'Uploading... ' + pct + '%';
        }
    };

    xhr.onload = function () {
        let data = null;
        try {
            const t = xhr.responseText;
            data = JSON.parse(t.substring(Math.max(0, t.indexOf('{"ok"'))));
        } catch (err) {}

        if (data && data.ok) {
            progressBarFill.style.width = '100%';
            progressBarFill.style.text = 'Saved! Reloading...';
            window.location.href = 'accommodations.php';
        } else if (data) {
            fail(data.message || 'Save failed.');
        } else {
            const raw = xhr.responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
            fail('Server error (' + xhr.status + '): ' + (raw || 'no details.'));
        }
    };

    xhr.onerror = function () { fail('Network error. Please try again.'); };

    xhr.send(formData);
});
</script>
<script src="../js/app.js" data-base="../" data-role="admin"></script>
</body>
</html>