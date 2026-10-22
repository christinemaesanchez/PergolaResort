<?php
// Secure receipt viewer: ADMIN ONLY.
// Place this file at:  htdocs/Resort/admin/receipt.php
require_once "../config.php";
require_once "../payment_lib.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    http_response_code(403);
    exit("Forbidden");
}

/** Friendly admin-only error page (HTTP 200 so Apache doesn't replace it with its own 404 page). */
function receipt_error(string $title, array $details = []): void {
    header("Content-Type: text/html; charset=utf-8");
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Receipt</title></head>"
       . "<body style='font-family:Arial,sans-serif;max-width:720px;margin:40px auto;padding:0 20px;'>"
       . "<h2>" . e($title) . "</h2>";
    if ($details) {
        echo "<p style='color:#555;'>Details for the admin (not shown to customers):</p><ul style='font-size:14px;color:#333;'>";
        foreach ($details as $k => $v) {
            echo "<li><strong>" . e($k) . ":</strong> " . e($v) . "</li>";
        }
        echo "</ul>";
    }
    echo "<p><a href='payments.php'>&larr; Back to Payments</a></p></body></html>";
    exit();
}

$id = (int)($_GET["id"] ?? 0);
$s = $conn->prepare("SELECT * FROM payments WHERE id = ? LIMIT 1");
$s->bind_param("i", $id);
$s->execute();
$row = $s->get_result()->fetch_assoc();
$s->close();

if (!$row) {
    receipt_error("Payment #{$id} was not found.");
}

// Find the receipt column (supports the usual names)
$rel = "";
foreach (["receipt_image", "receipt_path", "receipt", "proof_of_payment", "proof_image", "proof"] as $col) {
    if (!empty($row[$col])) { $rel = trim((string)$row[$col]); break; }
}
if ($rel === "") {
    receipt_error("No receipt was uploaded for payment #{$id}.");
}
$rel = ltrim(str_replace("\\", "/", $rel), "/");

// Possible project roots (handles payment_lib.php being placed in a different folder)
$roots = array_unique(array_filter([
    PERGOLA_ROOT,
    dirname(__DIR__),
    isset($_SERVER["DOCUMENT_ROOT"]) ? rtrim($_SERVER["DOCUMENT_ROOT"], "/") . "/Resort" : null,
]));

$checked = [];
$abs = null;
foreach ($roots as $root) {
    $candidate = $root . "/" . $rel;
    $checked[] = $candidate;
    $real = realpath($candidate);
    $uploads = realpath($root . "/uploads");
    // The file must exist AND live inside the project's uploads folder
    if ($real && $uploads && is_file($real) && strpos($real, $uploads . DIRECTORY_SEPARATOR) === 0) {
        $abs = $real;
        break;
    }
}

if (!$abs) {
    receipt_error("The receipt file could not be found on the server.", [
        "Payment ID" => $id,
        "Path saved in database" => $rel,
        "Locations checked" => implode("  |  ", $checked),
        "Fix" => "Make sure the file exists in your project's uploads/receipts/ folder.",
    ]);
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs);
if (!isset(PAY_RECEIPT_MIMES[$mime])) {
    receipt_error("Unsupported receipt file type.", ["Detected type" => $mime]);
}

while (ob_get_level()) { ob_end_clean(); }
header("Content-Type: " . $mime);
header("Content-Length: " . filesize($abs));
header("Content-Disposition: inline; filename=\"receipt_" . $id . "." . PAY_RECEIPT_MIMES[$mime] . "\"");
header("X-Content-Type-Options: nosniff");
header("Cache-Control: private, no-store");
readfile($abs);
exit();