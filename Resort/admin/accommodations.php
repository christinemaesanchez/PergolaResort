<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";   // csrf_token(), csrf_valid(), pay_badge_css()

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

function respond($msg, $type, $redirect = "accommodations.php") {
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
    header("Location: " . $redirect);
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

    // The table customers book from (created only if missing)
    $conn->query("CREATE TABLE IF NOT EXISTS addons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(50) NOT NULL,
        price DECIMAL(10,2) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        description VARCHAR(255) NULL,
        pricing_method VARCHAR(20) NOT NULL DEFAULT 'per_booking'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

} catch (mysqli_sql_exception $ex) {
    die("Database setup error: " . e($ex->getMessage()));
}

/* ---------- Which table do add-ons live in? ----------
   The customer booking form charges from the `addons` table (name, price, description,
   pricing_method, is_active). Add-ons are saved THERE so they actually show up when
   customers book. The old `resort_addons` table is only used if `addons` doesn't exist. */
$addon_table = "resort_addons";
$addon_type_col = "type";
$addon_has_active = false;
$addon_one_time = "one_time";
try {
    $ac = $conn->query("SHOW COLUMNS FROM addons");
    $af = [];
    while ($x = $ac->fetch_assoc()) { $af[$x["Field"]] = $x; }
    if (isset($af["name"], $af["price"], $af["pricing_method"])) {
        $addon_table = "addons";
        $addon_type_col = "pricing_method";
        $addon_has_active = isset($af["is_active"]);
        // Find the value this table uses for "one-time" charges
        if (preg_match('/^enum\((.*)\)$/i', (string)$af["pricing_method"]["Type"], $m)) {
            preg_match_all("/'([^']*)'/", $m[1], $vals);
            foreach ($vals[1] as $v) { if ($v !== "per_night") { $addon_one_time = $v; break; } }
        }
    }
} catch (Throwable $ex) { /* no `addons` table: keep using resort_addons */ }

// One-time move of add-ons created earlier in resort_addons into the table customers actually use
if ($addon_table === "addons") {
    try {
        $cnt = (int)$conn->query("SELECT COUNT(*) AS c FROM resort_addons")->fetch_assoc()["c"];
        if ($cnt > 0) {
            $ot = $conn->real_escape_string($addon_one_time);
            $cols_sql = "name, price, pricing_method, description" . ($addon_has_active ? ", is_active" : "");
            $sel_sql  = "r.name, r.price, CASE WHEN r.type = 'per_night' THEN 'per_night' ELSE '$ot' END, r.description" . ($addon_has_active ? ", 1" : "");
            $conn->query("INSERT INTO addons ($cols_sql) SELECT $sel_sql FROM resort_addons r
                          WHERE NOT EXISTS (SELECT 1 FROM addons a WHERE a.name = r.name)");
            $conn->query("DELETE FROM resort_addons");
        }
    } catch (Throwable $ex) { error_log("accommodations.php addon migration: " . $ex->getMessage()); }
}

/* ---------- Add-on categories ---------- */
$ADDON_CATEGORIES = ["Swimming", "Cooking", "Equipment", "Guest Services", "Time Extensions"];
$ADDON_ALL_CATEGORIES = array_merge($ADDON_CATEGORIES, ["Other"]);
$addon_has_category = false;

/* Runs ONCE, the first time the `category` column is created.
   - Existing add-ons are placed in a category (Extra Table -> Tables, Extra Chairs -> Chairs).
   - Missing add-ons are created DISABLED with a placeholder price, so a guessed price is
     never charged to customers: set the real price here, then switch the add-on on. */
function addon_seed_defaults($conn, $has_active) {
    // [category, name, placeholder price, charge type, description, older names to reuse]
    $seed = [
        ["Swimming", "Big Pool Access", 500, "one_time", "Use of the main swimming pool", []],
        ["Swimming", "Kiddie Pool Access", 250, "one_time", "Use of the shallow pool for small children", []],
        ["Cooking", "Kitchen Use", 500, "one_time", "Use of the kitchen and cookware", []],
        ["Cooking", "Grill Rental", 300, "one_time", "Grill set for outdoor cooking", []],
        ["Equipment", "Tables", 100, "one_time", "Additional table for dining or gatherings", ["Extra Table"]],
        ["Equipment", "Chairs", 50, "one_time", "Additional seating for guests", ["Extra Chairs"]],
        ["Equipment", "Extra Mattress", 150, "one_time", "Additional sleeping space", []],
        ["Equipment", "Videoke", 500, "one_time", "Videoke set for your stay", []],
        ["Guest Services", "Extra Person", 200, "one_time", "Additional guest beyond the allowed capacity", []],
        ["Guest Services", "Towel Rental", 50, "one_time", "Clean bath towel", []],
        ["Guest Services", "Drinking Water", 50, "one_time", "Drinking water for your stay", []],
        ["Time Extensions", "Early Check-in", 300, "one_time", "Check in earlier than the standard time", []],
        ["Time Extensions", "Late Check-out", 300, "one_time", "Check out later than the standard time", []],
        ["Time Extensions", "Additional Hours", 200, "one_time", "Extra hours added to your stay", []],
    ];
    foreach ($seed as $s) {
        list($cat, $nm, $pr, $pm, $ds, $aliases) = $s;
        $found = 0;
        foreach (array_merge([$nm], $aliases) as $try) {
            $q = $conn->prepare("SELECT id FROM addons WHERE name = ? LIMIT 1");
            $q->bind_param("s", $try);
            $q->execute();
            $r = $q->get_result()->fetch_assoc();
            $q->close();
            if ($r) { $found = (int)$r["id"]; break; }
        }
        if ($found > 0) {
            $u = $conn->prepare("UPDATE addons SET category = ? WHERE id = ?");
            $u->bind_param("si", $cat, $found);
            $u->execute();
            $u->close();
        } else {
            $sql = "INSERT INTO addons (name, price, pricing_method, description, category" . ($has_active ? ", is_active" : "") . ")
                    VALUES (?, ?, ?, ?, ?" . ($has_active ? ", 0" : "") . ")";
            $i = $conn->prepare($sql);
            $i->bind_param("sdsss", $nm, $pr, $pm, $ds, $cat);
            $i->execute();
            $i->close();
        }
    }
    // Tidy a garbled description from an older import
    $conn->query("UPDATE addons SET description = 'Additional guest beyond the allowed capacity' WHERE name = 'Extra Person' AND description LIKE '%Early Check-in%'");
}

if ($addon_table === "addons") {
    try {
        if (!isset($af["category"])) {
            $conn->query("ALTER TABLE addons ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'Other'");
            addon_seed_defaults($conn, $addon_has_active);
        }
        $addon_has_category = true;
    } catch (Throwable $ex) { error_log("accommodations.php addon categories: " . $ex->getMessage()); }
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
    $addons_url = "accommodations.php?tab=addons";

    if (!csrf_valid($_POST["csrf"] ?? null)) {
        respond("Your session expired. Please refresh the page and try again.", "error", in_array($action, ["save_addon", "save_addon_changes", "delete_addon", "toggle_addon"], true) ? $addons_url : "accommodations.php");
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

        // Photos the admin kept (never trust paths from the form blindly)
        $existing_images = json_decode($_POST["existing_images_json"] ?? "[]", true);
        $existing_images = is_array($existing_images) ? $existing_images : [];
        $existing_images = array_values(array_filter($existing_images, function ($p) {
            return is_string($p) && $p !== "" && strpos($p, "..") === false;
        }));

        $uploaded_files = [];
        $error = "";

        if ($name === "") {
            $error = "Accommodation name is required.";
        } elseif ($price === false || $price <= 0) {
            $error = "Please enter a valid price greater than zero.";
        } elseif ($capacity === false || $capacity <= 0) {
            $error = "Please enter a valid guest capacity.";
        }

        // Handle image uploads (max 4 photos in total)
        if ($error === "" && isset($_FILES["image_files"]) && is_array($_FILES["image_files"]["name"])) {
            $files = $_FILES["image_files"];
            $file_count = count($files["name"]);

            $new_count = 0;
            for ($i = 0; $i < $file_count; $i++) {
                if ($files["error"][$i] === UPLOAD_ERR_OK) $new_count++;
            }
            if (count($existing_images) + $new_count > 4) {
                $error = "A room can have up to 4 photos. It already has " . count($existing_images) . "; remove some or upload fewer.";
            }

            if ($error === "" && $new_count > 0) {
                $upload_dir = __DIR__ . "/../images/accommodations/";
                if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true)) {
                    $error = "Could not create upload folder: images/accommodations/";
                }

                for ($i = 0; $error === "" && $i < $file_count; $i++) {
                    if ($files["error"][$i] !== UPLOAD_ERR_OK) continue;
                    if ($files["size"][$i] > 5 * 1024 * 1024) {
                        $error = "One or more images exceed the 5MB limit.";
                        break;
                    }
                    $tmp  = $files["tmp_name"][$i];
                    $orig = $files["name"][$i];
                    $ext_in = strtolower(pathinfo($orig, PATHINFO_EXTENSION));

                    // Detect the real type using several methods (finfo may be missing/unreliable on XAMPP)
                    $mime = "";
                    if (function_exists("finfo_open")) {
                        $finfo = @finfo_open(FILEINFO_MIME_TYPE);
                        if ($finfo) { $mime = (string)@finfo_file($finfo, $tmp); finfo_close($finfo); }
                    }
                    $mime_to_ext = ["image/jpeg" => "jpg", "image/jpg" => "jpg", "image/pjpeg" => "jpg", "image/png" => "png", "image/x-png" => "png", "image/webp" => "webp"];
                    if (!isset($mime_to_ext[$mime])) {
                        $gi = @getimagesize($tmp);
                        if ($gi && isset($gi["mime"])) $mime = $gi["mime"];
                    }
                    // Last resort: check file signature (magic bytes)
                    if (!isset($mime_to_ext[$mime])) {
                        $head = (string)@file_get_contents($tmp, false, null, 0, 12);
                        if (substr($head, 0, 3) === "\xFF\xD8\xFF") $mime = "image/jpeg";
                        elseif (substr($head, 0, 8) === "\x89PNG\r\n\x1A\n") $mime = "image/png";
                        elseif (substr($head, 0, 4) === "RIFF" && substr($head, 8, 4) === "WEBP") $mime = "image/webp";
                        elseif (substr($head, 4, 4) === "ftyp") $mime = "image/heic"; // iPhone/Mac photo with wrong extension
                    }

                    $new_name = "acc_" . time() . "_" . mt_rand(1000, 9999) . "_" . $i;

                    // HEIC/HEIF (iPhone photos, even if named .jpg): convert to JPG if Imagick is available
                    if (in_array($mime, ["image/heic", "image/heif", "image/heic-sequence"], true)) {
                        if (class_exists("Imagick")) {
                            try {
                                $im = new Imagick($tmp);
                                $im->setImageFormat("jpeg");
                                $im->setImageCompressionQuality(88);
                                $im->autoOrient();
                                $im->writeImage($upload_dir . $new_name . ".jpg");
                                $im->clear();
                                $uploaded_files[] = "images/accommodations/" . $new_name . ".jpg";
                                continue;
                            } catch (Throwable $ex) {
                                $error = "This photo is in HEIC format and could not be converted. Please export it as JPG first.";
                                break;
                            }
                        }
                        // Fallback: ImageMagick command line, if installed
                        $out_path = $upload_dir . $new_name . ".jpg";
                        foreach (["magick", "convert"] as $bin) {
                            if (!function_exists("exec")) break;
                            @exec($bin . " " . escapeshellarg($tmp) . " -auto-orient -quality 88 " . escapeshellarg("jpg:" . $out_path) . " 2>&1", $o, $rc);
                            if ($rc === 0 && is_file($out_path) && filesize($out_path) > 0) {
                                $uploaded_files[] = "images/accommodations/" . $new_name . ".jpg";
                                continue 2;
                            }
                        }
                        $error = "\"" . $orig . "\" is actually a HEIC photo (iPhone format) renamed to ." . $ext_in . ". Please convert it to a real JPG/PNG (e.g. open in Preview > File > Export > JPEG) and upload again.";
                        break;
                    }

                    if (!isset($mime_to_ext[$mime])) {
                        $error = "Invalid image type for \"" . $orig . "\" (detected: " . ($mime !== "" ? $mime : "unknown") . "). Only JPG, PNG, and WEBP are accepted.";
                        break;
                    }

                    $new_name .= "." . $mime_to_ext[$mime];
                    if (move_uploaded_file($tmp, $upload_dir . $new_name)) {
                        $uploaded_files[] = "images/accommodations/" . $new_name;
                    } else {
                        $error = "Could not save \"" . $orig . "\". Check that images/accommodations/ is writable.";
                        break;
                    }
                }

                // Don't leave half-uploaded files behind if something failed
                if ($error !== "") {
                    foreach ($uploaded_files as $uf) { @unlink(__DIR__ . "/../" . $uf); }
                    $uploaded_files = [];
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
                // Photos the admin removed: delete their files too
                $old_stmt = $conn->prepare("SELECT image_urls FROM accommodations WHERE id = ?");
                $old_stmt->bind_param("i", $id);
                $old_stmt->execute();
                $old_row = $old_stmt->get_result()->fetch_assoc();
                $old_stmt->close();
                $old_images = json_decode((string)($old_row["image_urls"] ?? "[]"), true);
                $old_images = is_array($old_images) ? $old_images : [];

                $stmt = $conn->prepare("UPDATE accommodations SET name = ?, description = ?, price = ?, capacity = ?, image_urls = ?, type = ?, is_available = ? WHERE id = ?");
                $stmt->bind_param("ssdissii", $name, $description, $price, $capacity, $images_json, $type_val, $is_available, $id);
                $stmt->execute();
                $stmt->close();

                foreach (array_diff($old_images, $final_images) as $gone) {
                    if (is_string($gone) && strpos($gone, "images/accommodations/") === 0 && strpos($gone, "..") === false) {
                        @unlink(__DIR__ . "/../" . $gone);
                    }
                }
            } else {
                $stmt = $conn->prepare("INSERT INTO accommodations (name, description, price, capacity, image_urls, type, is_available) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param("ssdissi", $name, $description, $price, $capacity, $images_json, $type_val, $is_available);
                $stmt->execute();
                $stmt->close();
            }
            respond("Accommodation saved successfully!", "success");
        } catch (mysqli_sql_exception $ex) {
            foreach ($uploaded_files as $uf) { @unlink(__DIR__ . "/../" . $uf); }
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
            respond("Unable to delete accommodation; it may be tied to reservations. Set it to Unavailable instead.", "error");
        }
    }

    /* ===== C. SAVE ADD-ON / FEE (new custom add-on) ===== */
    if ($action === "save_addon") {
        $addon_name = trim($_POST["addon_name"] ?? "");
        $addon_price = filter_var($_POST["addon_price"] ?? 0, FILTER_VALIDATE_FLOAT);
        $addon_type = (($_POST["addon_type"] ?? "") === "per_night") ? "per_night" : $addon_one_time;
        $addon_desc = trim($_POST["addon_description"] ?? "");
        $addon_cat = $_POST["addon_category"] ?? "Other";
        if (!in_array($addon_cat, $ADDON_ALL_CATEGORIES, true)) { $addon_cat = "Other"; }

        if ($addon_name === "" || $addon_price === false || $addon_price < 0) {
            respond("Please provide a valid add-on name and price.", "error", $addons_url);
        }

        $cols_sql = "name, price, `$addon_type_col`, description";
        $ph_sql = "?, ?, ?, ?";
        $types = "sdss";
        $vals = [$addon_name, $addon_price, $addon_type, $addon_desc];
        if ($addon_has_active) { $cols_sql .= ", is_active"; $ph_sql .= ", 1"; }
        if ($addon_has_category) { $cols_sql .= ", category"; $ph_sql .= ", ?"; $types .= "s"; $vals[] = $addon_cat; }

        $stmt = $conn->prepare("INSERT INTO `$addon_table` ($cols_sql) VALUES ($ph_sql)");
        $stmt->bind_param($types, ...$vals);
        $stmt->execute();
        $stmt->close();
        respond("Add-on / Fee added successfully!", "success", $addons_url);
    }

    /* ===== C2. SAVE PRICES / CHARGE TYPE / ON-OFF FOR EVERY ADD-ON AT ONCE ===== */
    if ($action === "save_addon_changes") {
        $rows = $_POST["addon"] ?? [];
        if (!is_array($rows)) { $rows = []; }
        $clean = [];
        foreach ($rows as $aid => $r) {
            $aid = (int)$aid;
            if ($aid <= 0 || !is_array($r)) continue;
            $p = filter_var($r["price"] ?? "", FILTER_VALIDATE_FLOAT);
            if ($p === false || $p < 0) {
                respond("Please enter a valid price (0 or more) for every add-on.", "error", $addons_url);
            }
            $clean[] = [
                $aid,
                round($p, 2),
                (($r["method"] ?? "") === "per_night") ? "per_night" : $addon_one_time,
                (($r["active"] ?? "1") === "0") ? 0 : 1,
            ];
        }
        try {
            $conn->begin_transaction();
            $sql = "UPDATE `$addon_table` SET price = ?, `$addon_type_col` = ?" . ($addon_has_active ? ", is_active = ?" : "") . " WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $bp = 0.0; $bm = ""; $ba = 1; $bi = 0;
            if ($addon_has_active) { $stmt->bind_param("dsii", $bp, $bm, $ba, $bi); }
            else { $stmt->bind_param("dsi", $bp, $bm, $bi); }
            foreach ($clean as $c) {
                list($bi, $bp, $bm, $ba) = $c;
                $stmt->execute();
            }
            $stmt->close();
            $conn->commit();
            respond("Add-on prices and availability saved.", "success", $addons_url);
        } catch (mysqli_sql_exception $ex) {
            $conn->rollback();
            respond("Could not save the add-ons: " . $ex->getMessage(), "error", $addons_url);
        }
    }

    /* ===== D. SHOW / HIDE ADD-ON (hidden add-ons can't be picked by customers) ===== */
    if ($action === "toggle_addon" && $addon_has_active) {
        $addon_id = (int)($_POST["addon_id"] ?? 0);
        $stmt = $conn->prepare("UPDATE `$addon_table` SET is_active = 1 - is_active WHERE id = ?");
        $stmt->bind_param("i", $addon_id);
        $stmt->execute();
        $stmt->close();
        respond("Add-on visibility updated.", "success", $addons_url);
    }

    /* ===== E. DELETE ADD-ON / FEE ===== */
    if ($action === "delete_addon") {
        $addon_id = (int)($_POST["addon_id"] ?? 0);
        try {
            $stmt = $conn->prepare("DELETE FROM `$addon_table` WHERE id = ?");
            $stmt->bind_param("i", $addon_id);
            $stmt->execute();
            $stmt->close();
            respond("Add-on removed successfully.", "success", $addons_url);
        } catch (mysqli_sql_exception $ex) {
            respond("This add-on is used in existing reservations and can't be deleted. Hide it instead.", "error", $addons_url);
        }
    }
}

/* ---------- Load Data ---------- */
$edit_data = null;
$existing_imgs = [];
if (isset($_GET["edit"])) {
    $edit_id = (int)$_GET["edit"];
    $edit_stmt = $conn->prepare("SELECT * FROM accommodations WHERE id = ?");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $edit_data = $edit_stmt->get_result()->fetch_assoc();
    $edit_stmt->close();

    if ($edit_data) {
        $existing_imgs = json_decode((string)($edit_data["image_urls"] ?? "[]"), true);
        $existing_imgs = is_array($existing_imgs) ? $existing_imgs : [];
        if (empty($existing_imgs) && !empty($edit_data["image"])) { $existing_imgs = [$edit_data["image"]]; }
        $existing_imgs = array_values(array_filter($existing_imgs, 'is_string'));
    }
}

$accommodations = $conn->query("SELECT * FROM accommodations ORDER BY id DESC");
$addons = $conn->query("SELECT id, name, price, `$addon_type_col` AS ptype, description"
    . ($addon_has_active ? ", is_active" : ", 1 AS is_active")
    . ($addon_has_category ? ", category" : ", 'Other' AS category")
    . " FROM `$addon_table` ORDER BY id ASC");
$addon_groups = array_fill_keys($ADDON_ALL_CATEGORIES, []);
$addon_total = 0;
while ($ad = $addons->fetch_assoc()) {
    $grp = isset($addon_groups[$ad["category"]]) ? $ad["category"] : "Other";
    $addon_groups[$grp][] = $ad;
    $addon_total++;
}
$tab = (($_GET["tab"] ?? "") === "addons") ? "addons" : "accommodations";
$current = "accommodations";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Accommodations &amp; Fees | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/ui.css">
    <style>
        <?= pay_badge_css() ?>

        /* FORCE HIDE ALL ICONS, SB-TOGGLE, AND NOTIFICATION BELLS */
        .sidebar-nav a i, .sidebar-nav a svg, .sidebar-nav span i,
        .sb-toggle, .sidebar-collapse-btn, .ui-ico,
        .nf-wrap, .nf-admin, .topbar-bell, .notification-bell, .topbar-icons,
        .badge svg, .pbadge svg, .badge i, .pbadge i {
            display: none !important;
        }

        /* Status badges (same look as Reservations / Dashboard) */
        .badge, .pbadge {
            display: inline-block;
            border-radius: 20px !important;
            padding: 5px 12px !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .b-pending   { background: #fcf6df !important; color: #8a6d3b !important; }
        .b-confirmed { background: #e8f4e8 !important; color: #256030 !important; }
        .b-cancelled { background: #fbeae8 !important; color: #a02a35 !important; }
        .b-off       { background: #efece2 !important; color: #6b6f66 !important; }

        .page-tabs { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 1px solid #e3dec9; padding-bottom: 15px; }
        .page-tab-btn { padding: 10px 20px; font-weight: 600; text-decoration: none; border-radius: 8px; color: #555; background: #f0ede4; transition: all 0.2s; font-size: 13.5px; }
        .page-tab-btn.active { background: #6f8761; color: #fff; }

        /* ===== Table action buttons: same style as the Reservations page ===== */
        .table-actions { display: flex; gap: 6px; align-items: center; flex-wrap: nowrap; }
        .table-actions form { margin: 0; }
        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 14px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
            font-family: inherit;
            border-radius: 6px;
            border: 1px solid transparent;
            text-align: center;
            text-decoration: none;
            white-space: nowrap;
            cursor: pointer;
            transition: background-color 0.2s, border-color 0.2s, color 0.2s;
        }
        .action-btn.edit   { background: #fff; color: #344a30; border-color: #6f8761; }
        .action-btn.edit:hover { background: #eef2ea; }
        .action-btn.toggle { background: #f0ede4; color: #344a30; border-color: #d6cfba; }
        .action-btn.toggle:hover { background: #e6e1d3; }
        .action-btn.delete { background: #fff; color: #9c2e25; border-color: #e3b3ad; }
        .action-btn.delete:hover { background: #fbeae8; }

        /* Photo manager */
        .photo-edit { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 10px; }
        .photo-item { position: relative; }
        .photo-item img { width: 72px; height: 56px; object-fit: cover; border-radius: 6px; border: 1px solid #d6cfba; display: block; }
        .photo-x {
            position: absolute; top: -7px; right: -7px; width: 20px; height: 20px; padding: 0;
            border-radius: 50%; border: 1px solid #e3b3ad; background: #fff; color: #9c2e25;
            font-size: 13px; line-height: 1; font-weight: 700; cursor: pointer;
        }
        .photo-x:hover { background: #fbeae8; }

        .table-thumb-wrap { display: flex; gap: 4px; }
        .table-thumb { width: 45px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #e3dec9; }
        .thumb-placeholder { font-size: 10px; color: #888; background: #f7f5ee; padding: 8px; border-radius: 4px; text-align: center; }

        .progress-container { display: none; margin-top: 15px; background: #f7f5ee; border-radius: 8px; padding: 15px; border: 1px solid #e3dec9; }
        .progress-bar-track { width: 100%; background: #e3dec9; border-radius: 6px; height: 12px; overflow: hidden; margin-top: 8px; }
        .progress-bar-fill { width: 0%; height: 100%; background: #6f8761; transition: width 0.3s ease; }
        .progress-status-text { font-size: 13px; font-weight: 600; color: #344a30; }

        /* Add-ons by category */
        .addon-cat h3 { margin: 0; }
        .price-input, .method-select {
            padding: 8px 10px; border: 1px solid #d6cfba; border-radius: 6px;
            background: #fff; color: #344a30; font: inherit; font-size: 13.5px; box-sizing: border-box;
        }
        .price-input { width: 130px; font-weight: 700; }
        .price-input:focus, .method-select:focus { outline: none; border-color: #6f8761; }
        .addon-row.changed { background: #fcf6df; }
        .switch { position: relative; display: inline-block; width: 42px; height: 24px; vertical-align: middle; margin: 0; }
        .switch input[type=checkbox] { opacity: 0; width: 0; height: 0; position: absolute; }
        .switch .slider { position: absolute; inset: 0; background: #cfc8b3; border-radius: 24px; cursor: pointer; transition: background .2s; }
        .switch .slider:before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; top: 3px; background: #fff; border-radius: 50%; transition: transform .2s; }
        .switch input:checked + .slider { background: #6f8761; }
        .switch input:checked + .slider:before { transform: translateX(18px); }
        .switch input:focus-visible + .slider { outline: 2px solid #344a30; outline-offset: 2px; }
        .switch-label { margin-left: 10px; font-size: 12.5px; font-weight: 700; color: #344a30; vertical-align: middle; }
        .addon-savebar {
            position: sticky; bottom: 0; z-index: 5; display: flex; justify-content: space-between; align-items: center; gap: 12px;
            background: #fff; border: 1px solid #e3dec9; border-radius: 10px; padding: 12px 18px; margin-bottom: 30px;
            box-shadow: 0 -4px 18px rgba(52, 74, 48, 0.08);
        }
    </style>
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
            <span class="section-label">INVENTORY &amp; FEES</span>
            <h1>Accommodations &amp; Add-ons Management</h1>
            <p>Configure rooms, suites, cottages, photo galleries, and extra resort fees.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- Tabs for Accommodations vs Add-ons -->
    <div class="page-tabs">
        <a href="accommodations.php?tab=accommodations" class="page-tab-btn <?= $tab !== 'addons' ? 'active' : '' ?>">Accommodations Inventory</a>
        <a href="accommodations.php?tab=addons" class="page-tab-btn <?= $tab === 'addons' ? 'active' : '' ?>">Add-ons &amp; Fees</a>
    </div>

    <?php if ($tab === 'addons'): ?>
        <!-- ================= ADD-ONS & FEES TAB ================= -->
        <section class="card content-card" style="margin-bottom: 20px;">
            <div class="card-header-flex">
                <h3>Add-ons &amp; Fees by Category</h3>
                <span class="record-count"><?= (int)$addon_total ?> items</span>
            </div>
            <p class="sub-text" style="margin-top: 6px;">
                Edit any price or charge type, and switch an add-on off to hide it from customers. Nothing is saved until you press <strong>Save Changes</strong>.
                Add-ons switched off do not appear on the customer booking page.
            </p>
        </section>

        <form id="addonPriceForm" method="POST" action="accommodations.php?tab=addons" class="admin-form">
            <input type="hidden" name="action" value="save_addon_changes">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <?php foreach ($addon_groups as $cat_name => $items): ?>
                <?php if ($cat_name === "Other" && empty($items)) continue; ?>
                <section class="card content-card addon-cat" style="margin-bottom: 20px;">
                    <div class="card-header-flex">
                        <h3><?= e($cat_name) ?></h3>
                        <span class="record-count"><?= count($items) ?> items</span>
                    </div>
                    <div class="table-wrap">
                    <?php if (!empty($items)): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>Add-on</th>
                                    <th>Price (₱)</th>
                                    <th>Charge Type</th>
                                    <th>Available to Customers</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $ad): $aid = (int)$ad["id"]; $is_on = ((int)$ad["is_active"] === 1); ?>
                                <tr class="addon-row">
                                    <td>
                                        <strong><?= e($ad["name"]) ?></strong>
                                        <?php if (!empty($ad["description"])): ?><span class="sub-text" style="display:block;"><?= e($ad["description"]) ?></span><?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" class="price-input" step="0.01" min="0" required
                                               name="addon[<?= $aid ?>][price]"
                                               value="<?= e(number_format((float)$ad["price"], 2, ".", "")) ?>"
                                               aria-label="Price for <?= e($ad["name"]) ?>">
                                    </td>
                                    <td>
                                        <select class="method-select" name="addon[<?= $aid ?>][method]" aria-label="Charge type for <?= e($ad["name"]) ?>">
                                            <option value="one_time" <?= $ad["ptype"] !== "per_night" ? "selected" : "" ?>>One-time fee</option>
                                            <option value="per_night" <?= $ad["ptype"] === "per_night" ? "selected" : "" ?>>Per night</option>
                                        </select>
                                    </td>
                                    <td>
                                        <?php if ($addon_has_active): ?>
                                            <label class="switch">
                                                <input type="hidden" name="addon[<?= $aid ?>][active]" value="0">
                                                <input type="checkbox" name="addon[<?= $aid ?>][active]" value="1" <?= $is_on ? "checked" : "" ?> aria-label="Enable <?= e($ad["name"]) ?>">
                                                <span class="slider"></span>
                                            </label>
                                            <span class="switch-label"><?= $is_on ? "Active" : "Hidden" ?></span>
                                        <?php else: ?>
                                            <span class="badge b-confirmed">Active</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="table-actions">
                                            <button type="button" class="action-btn delete" data-id="<?= $aid ?>" data-name="<?= e($ad["name"]) ?>" onclick="deleteAddon(this)">Delete</button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="empty">No add-ons in this category yet. Add one below.</p>
                    <?php endif; ?>
                    </div>
                </section>
            <?php endforeach; ?>

            <div class="addon-savebar">
                <span class="sub-text" id="addonDirty">No unsaved changes</span>
                <button type="submit" class="admin-btn primary" id="addonSaveBtn">Save Changes</button>
            </div>
        </form>

        <!-- Separate form used by the Delete buttons (forms can't be nested) -->
        <form id="delAddonForm" method="POST" action="accommodations.php?tab=addons" style="display:none;">
            <input type="hidden" name="action" value="delete_addon">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="addon_id" value="">
        </form>

        <section class="card content-card" style="margin-bottom: 30px;">
            <h3 style="color: #344a30; margin-bottom: 20px;">Add a New Add-on or Fee</h3>
            <form method="POST" action="accommodations.php?tab=addons" class="admin-form">
                <input type="hidden" name="action" value="save_addon">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="addon_name">Add-on / Fee Name</label>
                        <input type="text" id="addon_name" name="addon_name" required maxlength="50" placeholder="e.g. Bonfire Set">
                    </div>
                    <div class="form-group">
                        <label for="addon_category">Category</label>
                        <select id="addon_category" name="addon_category">
                            <?php foreach ($ADDON_ALL_CATEGORIES as $cn): ?>
                                <option value="<?= e($cn) ?>"><?= e($cn) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-grid-2" style="margin-top: 15px;">
                    <div class="form-group">
                        <label for="addon_price">Price (₱)</label>
                        <input type="number" step="0.01" min="0" id="addon_price" name="addon_price" required placeholder="e.g. 500">
                    </div>
                    <div class="form-group">
                        <label for="addon_type">Charge Type</label>
                        <select id="addon_type" name="addon_type">
                            <option value="one_time">One-time Fee</option>
                            <option value="per_night">Per Night</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" style="margin-top: 15px;">
                    <label for="addon_description">Description (Optional)</label>
                    <input type="text" id="addon_description" name="addon_description" maxlength="255" placeholder="Brief details...">
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" class="admin-btn primary">Add Add-on</button>
                </div>
            </form>
        </section>

        <script>
        (function () {
            var form = document.getElementById('addonPriceForm');
            if (!form) return;
            var dirty = document.getElementById('addonDirty');

            function refresh() {
                var n = form.querySelectorAll('tr.addon-row.changed').length;
                dirty.textContent = n ? (n + ' unsaved change' + (n > 1 ? 's' : '')) : 'No unsaved changes';
                dirty.style.color = n ? '#8a6d3b' : '';
                dirty.style.fontWeight = n ? '700' : '';
            }

            form.querySelectorAll('tr.addon-row').forEach(function (tr) {
                var price = tr.querySelector('.price-input');
                var method = tr.querySelector('.method-select');
                var sw = tr.querySelector('input[type=checkbox]');
                var lbl = tr.querySelector('.switch-label');
                var orig = { p: parseFloat(price.value), m: method.value, a: sw ? sw.checked : null };

                function update() {
                    if (sw && lbl) lbl.textContent = sw.checked ? 'Active' : 'Hidden';
                    var changed = parseFloat(price.value) !== orig.p || method.value !== orig.m || (sw && sw.checked !== orig.a);
                    tr.classList.toggle('changed', !!changed);
                    refresh();
                }
                price.addEventListener('input', update);
                method.addEventListener('change', update);
                if (sw) sw.addEventListener('change', update);
            });

            window.deleteAddon = function (btn) {
                if (!confirm('Delete "' + btn.dataset.name + '"? This cannot be undone. (Use the switch to hide it instead if you may need it again.)')) return;
                var f = document.getElementById('delAddonForm');
                f.querySelector('[name=addon_id]').value = btn.dataset.id;
                f.submit();
            };
        })();
        </script>

    <?php else: ?>
        <!-- ================= ACCOMMODATIONS TAB ================= -->
        <section class="card content-card" style="margin-bottom: 30px;">
            <h3 style="color: #344a30; margin-bottom: 20px;"><?= $edit_data ? "Edit Accommodation #" . (int)$edit_data["id"] : "Add New Accommodation (Up to 4 Photos)" ?></h3>
            <form id="accommodationForm" method="POST" action="accommodations.php" enctype="multipart/form-data" class="admin-form">
                <input type="hidden" name="action" value="save_accommodation">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <?php if ($edit_data): ?>
                    <input type="hidden" name="id" value="<?= (int)$edit_data["id"] ?>">
                <?php endif; ?>
                <input type="hidden" name="existing_images_json" id="existingImagesJson" value="<?= e(json_encode($existing_imgs)) ?>">

                <div class="form-grid-2">
                    <div class="form-group">
                        <label for="name">Accommodation Name</label>
                        <input type="text" id="name" name="name" required value="<?= e($edit_data["name"] ?? "") ?>" placeholder="e.g. Standard Room">
                    </div>
                    <div class="form-group">
                        <label for="price">Price per Night (₱)</label>
                        <input type="number" step="0.01" min="0" id="price" name="price" required value="<?= e($edit_data["price"] ?? "") ?>" placeholder="e.g. 3800">
                    </div>
                </div>

                <div class="form-grid-2" style="margin-top: 15px;">
                    <div class="form-group">
                        <label for="capacity">Max Capacity (Guests)</label>
                        <input type="number" min="1" id="capacity" name="capacity" required value="<?= e($edit_data["capacity"] ?? "") ?>" placeholder="e.g. 2">
                    </div>
                    <div class="form-group">
                        <label for="image_files">Upload Photos (up to 4 in total)</label>
                        <input type="file" id="image_files" name="image_files[]" accept="image/png, image/jpeg, image/webp, image/heic, image/heif, .heic, .heif" multiple class="file-input">
                        <?php if (!empty($existing_imgs)): ?>
                            <div class="photo-edit" id="photoEdit">
                                <?php foreach ($existing_imgs as $ei): ?>
                                    <div class="photo-item" data-path="<?= e($ei) ?>">
                                        <img src="../<?= e($ei) ?>" alt="Saved photo">
                                        <button type="button" class="photo-x" title="Remove this photo" aria-label="Remove this photo">&times;</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <span class="sub-text" style="display:block; margin-top:6px;" id="photoHint">
                            <?= $edit_data ? "Saved photos are shown above. Click &times; to remove one, or upload new ones (4 maximum)." : "JPG, PNG or WEBP, max 5MB each." ?>
                        </span>
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
                            $row_imgs = json_decode((string)($row["image_urls"] ?? "[]"), true);
                            $row_imgs = is_array($row_imgs) ? $row_imgs : [];
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
                            <td><?= ((int)($row["is_available"] ?? 1) === 1) ? '<span class="badge b-confirmed">Available</span>' : '<span class="badge b-off">Unavailable</span>' ?></td>
                            <td><span class="desc-preview"><?= e($row["description"]) ?></span></td>
                            <td>
                                <div class="table-actions">
                                    <a href="accommodations.php?edit=<?= (int)$row["id"] ?>" class="action-btn edit">Edit</a>
                                    <form method="POST" action="accommodations.php" onsubmit="return confirm('Delete this accommodation?');">
                                        <input type="hidden" name="action" value="delete_accommodation">
                                        <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$row["id"] ?>">
                                        <button type="submit" class="action-btn delete">Delete</button>
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
// ----- Remove saved photos (keeps the hidden list in sync) -----
(function () {
    var box = document.getElementById('photoEdit');
    var hidden = document.getElementById('existingImagesJson');
    if (!box || !hidden) return;
    box.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.photo-x');
        if (!btn) return;
        btn.closest('.photo-item').remove();
        var left = Array.prototype.map.call(box.querySelectorAll('.photo-item'), function (el) { return el.dataset.path; });
        hidden.value = JSON.stringify(left);
    });
})();

// ----- Save with an upload progress bar -----
document.getElementById('accommodationForm')?.addEventListener('submit', function (e) {
    e.preventDefault();

    const form = this;
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
    progressStatusText.innerText = 'Preparing photos...';

    // Any ISO-BMFF file (HEIC/HEIF/AVIF...) has "ftyp" at bytes 4-8,
    // even if the file is named .jpg/.jpeg
    async function isHeic(file) {
        const buf = new Uint8Array(await file.slice(0, 16).arrayBuffer());
        return String.fromCharCode.apply(null, buf.slice(4, 8)) === 'ftyp';
    }

    // Try the browser's own decoder first (works in Safari). Returns a JPEG Blob or null.
    async function nativeToJpeg(file) {
        try {
            const bmp = await createImageBitmap(file);
            const canvas = document.createElement('canvas');
            canvas.width = bmp.width;
            canvas.height = bmp.height;
            canvas.getContext('2d').drawImage(bmp, 0, 0);
            return await new Promise(function (res) { canvas.toBlob(res, 'image/jpeg', 0.9); });
        } catch (err) { return null; }
    }

    (async function () {
        const formData = new FormData(form);
        const input = document.getElementById('image_files');
        let currentName = '';
        try {
            if (input && input.files && input.files.length) {
                formData.delete('image_files[]');
                for (const f of input.files) {
                    currentName = f.name;
                    if (await isHeic(f)) {
                        progressStatusText.innerText = 'Converting iPhone photo to JPG...';
                        let out = await nativeToJpeg(f);
                        if (!out) {
                            if (typeof heic2any === 'undefined') throw new Error('HEIC converter not loaded (check internet connection).');
                            out = await heic2any({ blob: f, toType: 'image/jpeg', quality: 0.9 });
                            if (Array.isArray(out)) out = out[0];
                        }
                        const newName = f.name.replace(/\.[^.]+$/, '') + '.jpg';
                        formData.append('image_files[]', new File([out], newName, { type: 'image/jpeg' }));
                    } else {
                        formData.append('image_files[]', f, f.name);
                    }
                }
            }
        } catch (err) {
            const detail = (err && err.message) ? err.message : (typeof err === 'object' ? JSON.stringify(err) : err);
            fail('Could not convert "' + currentName + '": ' + detail + ' — please export it as JPG (Preview > File > Export) and try again.');
            return;
        }
        progressStatusText.innerText = 'Uploading... 0%';
        sendForm(formData);
    })();

    function sendForm(formData) {
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
                const raw = xhr.responseText.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().substring(0, 300);
                fail('Server error (' + xhr.status + '): ' + (raw || 'no details.'));
            }
        };

        xhr.onerror = function () { fail('Network error. Please try again.'); };

        xhr.send(formData);
    }
});
</script>
<script src="https://cdn.jsdelivr.net/npm/heic2any@0.0.4/dist/heic2any.min.js"></script>
<script src="../js/app.js" data-base="../" data-role="admin"></script>
<script src="../js/responsive.js"></script>
</body>
</html>