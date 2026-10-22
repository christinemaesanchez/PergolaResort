<?php
// =============================================================
// check_install.php - run in your browser:  http://localhost/Resort/check_install.php
// Shows exactly what is missing (files, PHP extensions, database tables/columns, folders).
// LOCAL MACHINE ONLY. Delete this file before putting the site online.
// =============================================================
if (!in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true)) { http_response_code(403); exit("Local access only."); }
ini_set("display_errors", "1"); error_reporting(E_ALL);
$root = __DIR__;
$rows = [];
function chk(string $label, bool $ok, string $fix = ""): void { global $rows; $rows[] = [$label, $ok, $fix]; }

chk("PHP version " . PHP_VERSION . " (needs 7.4 or newer)", version_compare(PHP_VERSION, "7.4.0", ">="), "Update XAMPP.");
foreach (["mysqli", "mbstring", "fileinfo", "json", "session"] as $ext) {
    chk("PHP extension: $ext", extension_loaded($ext), "In XAMPP Control Panel > Apache > Config > php.ini, remove the ';' before extension=$ext, then restart Apache.");
}
chk("PHP extension: gd or fileinfo for image checks", extension_loaded("gd") || extension_loaded("fileinfo"), "Enable extension=gd in php.ini.");
chk("upload_max_filesize = " . ini_get("upload_max_filesize") . " (5M or more recommended)", (int)ini_get("upload_max_filesize") >= 5, "Raise upload_max_filesize and post_max_size in php.ini.");

// required files (every file the new code depends on)
$files = ["config.php", "payment_lib.php", "notifications.php", "js/app.js", "css/ui.css", "css/admin.css", "css/customer.css",
          "login.php", "logout.php", "admin/logout.php", "book.php", "accommodations.php", "admin/accommodations.php",
          "admin/reservations.php", "admin/payments.php", "customer/my_bookings.php", "api/paypal_capture_order.php"];
foreach ($files as $f) chk("File exists: $f", is_file("$root/$f"), "Copy this file from the zip into your Resort folder (keep the same sub-folder).");

// folders
foreach (["images/accommodations", "uploads/receipts"] as $d) {
    chk("Folder writable: $d", is_dir("$root/$d") && is_writable("$root/$d"), "Create the folder and make it writable.");
}

// database
$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_OFF);
    require_once "$root/config.php";
    chk("Database connection (config.php)", isset($conn) && $conn instanceof mysqli && !$conn->connect_errno, "Check host/user/password/database name in config.php, and that MySQL is running in XAMPP.");
} catch (Throwable $ex) {
    chk("Database connection (config.php)", false, "config.php error: " . $ex->getMessage());
}
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_errno) {
    $need = [
        "users" => ["id", "role", "status", "password"],
        "accommodations" => ["id", "name", "price", "image", "is_available"],
        "accommodation_images" => ["id", "accommodation_id", "image_path", "sort_order"],
        "reservations" => ["id", "user_id", "accommodation_id", "check_in", "check_out", "status", "total_amount", "cancelled_at"],
        "payments" => ["id", "reservation_id", "amount", "status", "receipt_image"],
        "addons" => ["id", "name", "price", "description", "is_active", "pricing_method"],
        "reservation_addons" => ["id", "reservation_id", "addon_id", "price", "line_total", "pricing_method"],
        "resort_info" => ["resort_name", "location"],
        "notifications" => ["id", "user_id", "title", "message", "is_read", "dedupe_key"],
    ];
    foreach ($need as $t => $cols) {
        $r = @$conn->query("SHOW COLUMNS FROM `$t`");
        if (!$r) { chk("Table `$t`", false, $t === "notifications" || $t === "accommodation_images" ? "Open any customer or admin page once (it is created automatically) or import migration_optional.sql in phpMyAdmin." : "Table missing: re-import your database."); continue; }
        $have = array_column($r->fetch_all(MYSQLI_ASSOC), "Field");
        $miss = array_diff($cols, $have);
        $auto = in_array($t, ["addons", "reservation_addons"], true);
        chk("Table `$t`" . ($miss ? " - missing columns: " . implode(", ", $miss) : ""), !$miss,
            $auto ? "Open book.php once: the missing columns are added automatically." : "Run the ALTER TABLE in migration_optional.sql or re-import your database.");
    }
    // picture files that the database mentions but are not on disk
    $missing = [];
    $r = @$conn->query("SELECT image_path FROM accommodation_images UNION SELECT image FROM accommodations WHERE image IS NOT NULL AND image <> ''");
    if ($r) while ($p = $r->fetch_row()) if ($p[0] && !preg_match('#^https?://#i', $p[0]) && !is_file("$root/" . ltrim($p[0], "/"))) $missing[] = $p[0];
    chk("Accommodation picture files present on disk" . ($missing ? " - MISSING: " . implode(", ", $missing) : ""), !$missing,
        "Copy those files into images/accommodations/, or in Admin > Accommodations > Edit, use Replace/Remove and upload a new picture.");
}
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pergola Resort - Install Check</title>
<style>body{font-family:Arial,sans-serif;background:#f5f3eb;color:#333;margin:0;padding:24px}.w{max-width:900px;margin:auto}
h1{color:#344a30}table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;overflow:hidden}
td{padding:10px 14px;border-bottom:1px solid #eee;font-size:14px;vertical-align:top}.ok{color:#2e6b2e;font-weight:700}.no{color:#a02a35;font-weight:700}.fix{color:#777;font-size:13px}</style></head>
<body><div class="w"><h1>Pergola Resort - Install Check</h1>
<?php $bad = count(array_filter($rows, fn($r) => !$r[1])); ?>
<p><strong><?= $bad === 0 ? "Everything checked is OK." : $bad . " problem(s) found - see the red rows." ?></strong> Delete this file when you are done.</p>
<table><?php foreach ($rows as [$l, $ok, $fix]): ?>
<tr><td class="<?= $ok ? "ok" : "no" ?>"><?= $ok ? "OK" : "FIX" ?></td><td><?= htmlspecialchars($l) ?><?php if (!$ok && $fix): ?><div class="fix"><?= htmlspecialchars($fix) ?></div><?php endif; ?></td></tr>
<?php endforeach; ?></table></div></body></html>
