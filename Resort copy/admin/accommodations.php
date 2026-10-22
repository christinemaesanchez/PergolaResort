<?php
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

// Throw exceptions on DB errors so we can show the real message (PHP 8.1+ does this by default)
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

/* ---------- Inspect the table & make sure an image column exists ---------- */
$columns = [];
try {
    $res = $conn->query("SHOW COLUMNS FROM accommodations");
    while ($c = $res->fetch_assoc()) {
        $columns[$c["Field"]] = $c;
    }

    $img_col = null;
    foreach (["image_url", "image"] as $candidate) {
        if (isset($columns[$candidate])) { $img_col = $candidate; break; }
    }
    if ($img_col === null) {
        $conn->query("ALTER TABLE accommodations ADD COLUMN image_url VARCHAR(255) NULL");
        $columns["image_url"] = ["Field" => "image_url", "Type" => "varchar(255)", "Null" => "YES", "Default" => null, "Extra" => ""];
        $img_col = "image_url";
    }
    if (!isset($columns["capacity"])) {
        $conn->query("ALTER TABLE accommodations ADD COLUMN capacity INT NOT NULL DEFAULT 2");
        $columns["capacity"] = ["Field" => "capacity", "Type" => "int(11)", "Null" => "NO", "Default" => "2", "Extra" => ""];
    }
    $has_type = isset($columns["type"]);
} catch (mysqli_sql_exception $ex) {
    die("Database setup error: " . e($ex->getMessage()));
}

/* ---------- Flash message ---------- */
$message = $_SESSION["flash_msg"] ?? "";
$message_type = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_msg"], $_SESSION["flash_type"]);

/* ---------- Handle POST ---------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // File bigger than post_max_size => PHP drops all POST data
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        respond("Upload too large for the server (post_max_size = " . ini_get("post_max_size") . "). Use a smaller image.", "error");
    }

    $action = $_POST["action"] ?? "";

    if (!csrf_valid($_POST["csrf"] ?? null)) {
        respond("Your session expired. Please refresh the page and try again.", "error");
    }

    /* ===== SAVE ===== */
    if ($action === "save_accommodation") {
        $id = (int)($_POST["id"] ?? 0);
        $name = trim($_POST["name"] ?? "");
        $description = trim($_POST["description"] ?? "");
        $price = filter_var($_POST["price"] ?? 0, FILTER_VALIDATE_FLOAT);
        $capacity = filter_var($_POST["capacity"] ?? 0, FILTER_VALIDATE_INT);
        $image = trim($_POST["existing_image"] ?? "");
        $is_available = (($_POST["is_available"] ?? "1") === "0") ? 0 : 1;
        $type_val = trim($_POST["type"] ?? "");
        if ($type_val === "") { $type_val = "Room"; }
        $new_file_path = null;
        $error = "";

        if ($name === "") {
            $error = "Accommodation name is required.";
        } elseif ($price === false || $price <= 0) {
            $error = "Please enter a valid price greater than zero.";
        } elseif ($capacity === false || $capacity <= 0) {
            $error = "Please enter a valid guest capacity.";
        }

        // ----- Optional image upload -----
        if ($error === "" && isset($_FILES["image_file"]) && $_FILES["image_file"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $f = $_FILES["image_file"];

            if ($f["error"] !== UPLOAD_ERR_OK) {
                $error = ($f["error"] === UPLOAD_ERR_INI_SIZE || $f["error"] === UPLOAD_ERR_FORM_SIZE)
                    ? "Image exceeds the server upload limit (" . ini_get("upload_max_filesize") . ")."
                    : "Upload failed with error code: " . $f["error"];
            } elseif ($f["size"] > 5 * 1024 * 1024) {
                $error = "Image is larger than 5MB limit.";
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $f["tmp_name"]);
                finfo_close($finfo);

                $mime_to_ext = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];
                if (!isset($mime_to_ext[$mime])) {
                    $error = "Invalid image type. Only JPG, PNG, and WEBP are accepted.";
                } else {
                    $upload_dir = __DIR__ . "/../images/accommodations/";
                    if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
                        $error = "Could not create upload folder: images/accommodations/";
                    } elseif (!is_writable($upload_dir)) {
                        $error = "Upload folder is not writable. Check folder permissions.";
                    } else {
                        $new_name = "accommodation_" . time() . "_" . mt_rand(1000, 9999) . "." . $mime_to_ext[$mime];
                        if (move_uploaded_file($f["tmp_name"], $upload_dir . $new_name)) {
                            $image = "images/accommodations/" . $new_name;
                            $new_file_path = $upload_dir . $new_name;
                        } else {
                            $error = "Failed to move uploaded image into folder.";
                        }
                    }
                }
            }
        }

        if ($error !== "") {
            respond($error, "error");
        }

        // ----- Save to database -----
        try {
            $fields = ["name", "description", "price", "capacity", $img_col];
            $values = [$name, $description, (string)$price, (string)$capacity, $image];
            if ($has_type) { $fields[] = "type"; $values[] = $type_val; }
            if (isset($columns["is_available"])) { $fields[] = "is_available"; $values[] = (string)$is_available; }

            if ($id > 0) {
                $set_sql = implode(", ", array_map(function ($f) { return "`$f` = ?"; }, $fields));
                $stmt = $conn->prepare("UPDATE accommodations SET $set_sql WHERE id = ?");
                $values[] = (string)$id;
                $stmt->bind_param(str_repeat("s", count($values)), ...$values);
                $stmt->execute();
                $stmt->close();
            } else {
                // Fill any OTHER required column that has no default
                $known = array_merge(["id"], $fields);
                foreach ($columns as $field => $meta) {
                    if (in_array($field, $known, true)) continue;
                    $extra = strtolower($meta["Extra"] ?? "");
                    $required = ($meta["Null"] === "NO" && $meta["Default"] === null
                        && strpos($extra, "auto_increment") === false
                        && strpos($extra, "generated") === false);
                    if (!$required) continue;

                    $t = strtolower($meta["Type"]);
                    if (preg_match("/^enum\\('([^']*)'/", $t, $m)) {
                        $val = $m[1];
                    } elseif (preg_match("/int|decimal|float|double|numeric/", $t)) {
                        $val = "0";
                    } elseif (strpos($t, "datetime") !== false || strpos($t, "timestamp") !== false) {
                        $val = date("Y-m-d H:i:s");
                    } elseif (strpos($t, "date") !== false) {
                        $val = date("Y-m-d");
                    } else {
                        $val = "";
                    }
                    $fields[] = $field;
                    $values[] = $val;
                }

                $col_sql = "`" . implode("`, `", $fields) . "`";
                $ph_sql = implode(", ", array_fill(0, count($fields), "?"));
                $stmt = $conn->prepare("INSERT INTO accommodations ($col_sql) VALUES ($ph_sql)");
                $stmt->bind_param(str_repeat("s", count($fields)), ...$values);
                $stmt->execute();
                $stmt->close();
            }

            respond("Accommodation saved successfully!", "success");

        } catch (mysqli_sql_exception $ex) {
            // Remove the orphan photo we just uploaded
            if ($new_file_path && file_exists($new_file_path)) {
                @unlink($new_file_path);
            }
            respond("Database error: " . $ex->getMessage(), "error");
        }

    /* ===== DELETE ===== */
    } elseif ($action === "delete_accommodation") {
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
}

/* ---------- Load data for display ---------- */
$edit_data = null;
if (isset($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];
    $edit_stmt = $conn->prepare("SELECT * FROM accommodations WHERE id = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_data = $edit_stmt->get_result()->fetch_assoc();
    $edit_stmt->close();
}

$result = $conn->query("SELECT * FROM accommodations ORDER BY id DESC");
$current = "accommodations";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accommodations | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        .progress-container { display: none; margin-top: 15px; background: #f1f1f1; border-radius: 6px; padding: 15px; border: 1px solid #ddd; }
        .progress-bar-track { width: 100%; background: #e0e0e0; border-radius: 4px; height: 16px; overflow: hidden; margin-top: 8px; }
        .progress-bar-fill { width: 0%; height: 100%; background: #4caf50; transition: width 0.3s ease; }
        .progress-status-text { font-size: 13px; font-weight: 600; color: #333; }
    </style>
</head>
<body>

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

<main class="main-content">
    <header class="topbar">
        <div class="topbar-title">
            <span class="section-label">INVENTORY</span>
            <h1>Accommodations Management</h1>
            <p>Add, edit, or configure rooms, suites, and cottages.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <!-- Resort Promotional Hero Banner -->
    <section class="resort-hero-banner" style="margin-bottom: 25px;">
        <div class="hero-overlay-content">
            <span class="hero-tag">Pergola Resort Experience</span>
            <h2>Crafting Unforgettable Stays</h2>
            <p>Manage your exclusive rooms, suites, and cottages to deliver top-tier guest satisfaction.</p>
        </div>
    </section>
    
    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>" style="padding:12px; margin-bottom:20px; border-radius:6px; background:<?= $message_type === 'success' ? '#dff0d8' : '#f2dede' ?>; color:<?= $message_type === 'success' ? '#3c763d' : '#a94442' ?>;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <section class="card content-card" style="margin-bottom: 30px;">
        <h3><?= $edit_data ? "Edit Accommodation #" . (int)$edit_data["id"] : "Add New Accommodation" ?></h3>
        <form id="accommodationForm" method="POST" action="accommodations.php" enctype="multipart/form-data" class="admin-form" style="margin-top: 20px;">
            <input type="hidden" name="action" value="save_accommodation">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <?php if ($edit_data): ?>
                <input type="hidden" name="id" value="<?= (int)$edit_data["id"] ?>">
                <input type="hidden" name="existing_image" value="<?= e($edit_data[$img_col] ?? "") ?>">
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
                    <label for="image_file">Upload Accommodation Photo</label>
                    <input type="file" id="image_file" name="image_file" accept="image/png, image/jpeg, image/webp" class="file-input">
                    <?php if (!empty($edit_data[$img_col])): ?>
                        <span class="sub-text" style="display:block; margin-top:5px;">Current: <?= e(basename($edit_data[$img_col])) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (isset($columns["is_available"])): ?>
            <div class="form-group" style="margin-top: 15px;">
                <label for="is_available">Availability for Booking</label>
                <?php $cur_avail = (int)($edit_data["is_available"] ?? 1); ?>
                <select id="is_available" name="is_available">
                    <option value="1" <?= $cur_avail === 1 ? "selected" : "" ?>>Available</option>
                    <option value="0" <?= $cur_avail === 0 ? "selected" : "" ?>>Unavailable (customers cannot book)</option>
                </select>
            </div>
            <?php endif; ?>

            <?php if ($has_type): ?>
            <div class="form-group" style="margin-top: 15px;">
                <label for="type">Type</label>
                <?php $cur_type = $edit_data["type"] ?? "Room"; ?>
                <select id="type" name="type">
                    <?php foreach (["Room", "Cottage", "Suite", "Villa", "Function Hall", "Other"] as $opt): ?>
                        <option value="<?= e($opt) ?>" <?= $cur_type === $opt ? "selected" : "" ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                    <?php if (!in_array($cur_type, ["Room", "Cottage", "Suite", "Villa", "Function Hall", "Other"], true)): ?>
                        <option value="<?= e($cur_type) ?>" selected><?= e($cur_type) ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <?php endif; ?>

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
            <span class="record-count"><?= $result->num_rows ?> items listed</span>
        </div>
        <div class="table-wrap">
        <?php if ($result->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Photo</th>
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
                    <?php while ($row = $result->fetch_assoc()): ?>
                    <tr>
                        <td>
                            <?php if (!empty($row[$img_col])): ?>
                                <img src="../<?= e($row[$img_col]) ?>" alt="Preview" class="table-thumb" style="width: 60px; height: 50px; object-fit: cover; border-radius: 4px;">
                            <?php else: ?>
                                <div class="thumb-placeholder" style="font-size: 11px; color: #888;">No Image</div>
                            <?php endif; ?>
                        </td>
                        <td>#<?= (int)$row["id"] ?></td>
                        <td><strong><?= e($row["name"]) ?></strong></td>
                        <td><?= (int)$row["capacity"] ?> Persons</td>
                        <td><strong><?= peso($row["price"]) ?></strong></td>
                        <td><?= ((int)($row["is_available"] ?? 1) === 1) ? "Available" : "Unavailable" ?></td>
                        <td><span class="desc-preview"><?= e($row["description"]) ?></span></td>
                        <td>
                            <div class="table-actions">
                                <a href="accommodations.php?edit=<?= (int)$row["id"] ?>" class="action-link edit">Edit</a>
                                <form method="POST" action="accommodations.php" onsubmit="return confirm('Are you sure you want to delete this accommodation?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_accommodation">
                                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                    <input type="hidden" name="id" value="<?= (int)$row["id"] ?>">
                                    <button type="submit" class="action-link delete" style="background:none; border:none; color:red; cursor:pointer;">Delete</button>
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
</main>

<script>
document.getElementById('accommodationForm').addEventListener('submit', function (e) {
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
        progressBarFill.style.backgroundColor = '#d9534f';
    }

    submitBtn.disabled = true;
    progressContainer.style.display = 'block';
    progressBarFill.style.backgroundColor = '#4caf50';
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
            progressStatusText.innerText = 'Saved! Reloading...';
            window.location.href = 'accommodations.php';
        } else if (data) {
            fail(data.message || 'Save failed.');
        } else {
            // Not JSON: a PHP fatal error. Show the start of it so it can be diagnosed.
            const raw = xhr.responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
            fail('Server error (' + xhr.status + '): ' + (raw || 'no details. Check the PHP error log (xampp/apache/logs/error.log).'));
        }
    };

    xhr.onerror = function () { fail('Network error. Please try again.'); };

    xhr.send(formData);
});
</script>
</body>
</html>
