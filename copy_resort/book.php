<?php
ob_start();   // swallow stray notices/warnings so AJAX replies are always clean JSON

// ---------- 1. CONNECTION + SESSION ----------
require_once "config.php";
require_once "payment_lib.php";

// PayPal is optional: if paypal_config.php is missing, GCash booking still works
if (is_file(__DIR__ . "/paypal_config.php")) { require_once __DIR__ . "/paypal_config.php"; }
if (!defined("PAYPAL_CLIENT_ID")) define("PAYPAL_CLIENT_ID", "");
if (!defined("PAYPAL_CURRENCY"))  define("PAYPAL_CURRENCY", "PHP");
$paypal_enabled = (PAYPAL_CLIENT_ID !== "");

if (!function_exists('e'))    { function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('peso')) { function peso($n) { return "₱" . number_format((float)$n, 2); } }

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- 2. AUTHENTICATION CHECK ----------
pay_no_cache_headers();
if (($_SESSION["role"] ?? "") === "customer") {
    // Confirms in the database that this customer account still exists and is active
    auth_guard($conn, "customer", "login.php?redirect=" . urlencode($_SERVER["REQUEST_URI"]));
}
if (empty($_SESSION["user_id"])) {
    header("Location: login.php?redirect=" . urlencode($_SERVER["REQUEST_URI"]));
    exit();
}
if (($_SESSION["role"] ?? "") !== "customer") {
    header("Location: accommodations.php");
    exit();
}

$user_id = (int)$_SESSION["user_id"];
$accommodation_id = isset($_GET["id"]) ? (int)$_GET["id"] : 0;

// Set to false once everything works, so customers never see technical errors
// Real error text is shown ONLY when running on your own computer (localhost); never to real customers
$PAY_DEBUG = in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1", "::1"], true);

ensure_payment_schema($conn);
$settings = pay_get_settings($conn);

// ---------- 3. ACCOMMODATION ----------
$stmt = $conn->prepare("SELECT * FROM accommodations WHERE id = ? LIMIT 1");
$stmt->bind_param("i", $accommodation_id);
$stmt->execute();
$accommodation = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$accommodation) {
    header("Location: accommodations.php");
    exit();
}

$max_capacity = (int)($accommodation["capacity"] ?? 1);

// ---------- 4. ADD-ONS (prices come from MySQL) ----------
$addons = [];
$addon_groups = [];
$addon_cat_order = ["Swimming", "Cooking", "Equipment", "Guest Services", "Time Extensions"];
$addon_cols = [];
$t = $conn->query("SHOW TABLES LIKE 'addons'");
if ($t && $t->num_rows > 0) {
    $rc = $conn->query("SHOW COLUMNS FROM addons");
    while ($c = $rc->fetch_assoc()) { $addon_cols[$c["Field"]] = true; }
}
$has_cat = isset($addon_cols["category"]);
if (isset($addon_cols["name"], $addon_cols["price"], $addon_cols["pricing_method"])) {
    $addon_sql = "SELECT id, name, price, pricing_method"
        . (isset($addon_cols["description"]) ? ", description" : ", '' AS description")
        . ($has_cat ? ", category" : "")
        . " FROM addons" . (isset($addon_cols["is_active"]) ? " WHERE is_active = 1" : "") . " ORDER BY id ASC";
    $ar = $conn->query($addon_sql);
    while ($ar && ($row = $ar->fetch_assoc())) {
        $addons[] = $row;
        $grp = ($has_cat && in_array($row["category"] ?? "", $addon_cat_order, true)) ? $row["category"] : "Other";
        $addon_groups[$grp][] = $row;
    }
}
// Show categories in a fixed order (Swimming, Cooking, Equipment, Guest Services, Time Extensions, Other)
$addon_groups_sorted = [];
foreach (array_merge($addon_cat_order, ["Other"]) as $c) {
    if (!empty($addon_groups[$c])) $addon_groups_sorted[$c] = $addon_groups[$c];
}

// ---------- 5. RESORT INFO ----------
$resort_name = "Pergola Resort";
$res_info = $conn->query("SELECT resort_name FROM resort_info LIMIT 1");
if ($res_info && $res_info->num_rows > 0) {
    $resort_name = $res_info->fetch_assoc()["resort_name"];
}

function json_out(array $data, int $code = 200): void {
    while (ob_get_level()) { ob_end_clean(); }
    http_response_code($code);
    header("Content-Type: application/json");
    echo json_encode($data);
    exit();
}

// ---------- 5a. ONLY AVAILABLE ACCOMMODATIONS CAN BE BOOKED ----------
$acc_status = $accommodation["status"] ?? null;
$acc_open = ((int)($accommodation["is_available"] ?? 1) === 1)
    && ($acc_status === null || $acc_status === "" || $acc_status === "active");
if (!$acc_open) {
    if ($_SERVER["REQUEST_METHOD"] === "POST" || isset($_GET["action"])) {
        json_out(["ok" => false, "error" => "This accommodation is currently not available for booking."]);
    }
    header("Location: accommodations.php");
    exit();
}

// ---------- 5b. AJAX: BOOKED DATE RANGES (for the availability calendar) ----------
if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["action"] ?? "") === "availability") {
    $ph = implode(",", array_fill(0, count(PAY_BLOCKING_STATUSES), "?"));
    $sqlA = "SELECT check_in, check_out FROM reservations
             WHERE accommodation_id = ? AND status IN ($ph) AND check_out > CURDATE()
             ORDER BY check_in";
    $typesA = "i" . str_repeat("s", count(PAY_BLOCKING_STATUSES));
    $paramsA = array_merge([$accommodation_id], PAY_BLOCKING_STATUSES);
    $stA = $conn->prepare($sqlA);
    $stA->bind_param($typesA, ...$paramsA);
    $stA->execute();
    $resA = $stA->get_result();
    $ranges = [];
    while ($rw = $resA->fetch_assoc()) {
        $ranges[] = [substr((string)$rw["check_in"], 0, 10), substr((string)$rw["check_out"], 0, 10)];
    }
    $stA->close();
    json_out(["ok" => true, "ranges" => $ranges]);
}

// ---------- 6. AJAX: QUOTE (availability + totals) and SUBMIT ----------
$act = $_GET["action"] ?? "";
if ($_SERVER["REQUEST_METHOD"] === "POST" && in_array($act, ["quote", "submit"], true)) {

    if (empty($_POST) && empty($_FILES) && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        json_out(["ok" => false, "error" => "The file is too large for the server (limit " . ini_get("post_max_size") . ")."]);
    }
    if (!csrf_valid($_POST["csrf"] ?? null)) {
        json_out(["ok" => false, "error" => "Your session expired. Please refresh the page and try again."], 403);
    }

    $check_in = trim($_POST["check_in"] ?? "");
    $check_out = trim($_POST["check_out"] ?? "");
    $guests = (int)($_POST["guests"] ?? $_POST["num_guests"] ?? 1);
    if ($guests < 1) $guests = 1;
    if ($guests > $max_capacity) {
        json_out(["ok" => false, "error" => "Number of guests cannot exceed the accommodation's maximum capacity of " . $max_capacity . "."]);
    }

    $dre = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($dre, $check_in) || !preg_match($dre, $check_out)) {
        json_out(["ok" => false, "error" => "Please choose both a check-in and a check-out date."]);
    }
    if ($check_in < date("Y-m-d")) {
        json_out(["ok" => false, "error" => "Check-in date cannot be in the past."]);
    }
    if ($check_out <= $check_in) {
        json_out(["ok" => false, "error" => "Check-out must be at least one day after check-in."]);
    }

    $sel = $_POST["addons"] ?? [];
    if (!is_array($sel)) $sel = [];

    try {
        $calc = pay_calc_booking($conn, $accommodation_id, $check_in, $check_out, $sel);
        if (!$calc["ok"]) json_out(["ok" => false, "error" => $calc["error"]]);

        if (!pay_is_available($conn, $accommodation_id, $check_in, $check_out)) {
            json_out(["ok" => false, "error" => "This accommodation is already booked for the selected dates. Please choose different dates."]);
        }

        if ($act === "quote") {
            $lines = [[
                "label" => "Accommodation (" . $calc["nights"] . " night" . ($calc["nights"] > 1 ? "s" : "") . " × " . peso($calc["unit_price"]) . ")",
                "amount" => peso($calc["base"]),
            ]];
            foreach ($calc["addons"] as $a) {
                $lbl = $a["name"] . ($a["pricing_method"] === "per_night" ? " (" . peso($a["price"]) . " × " . $calc["nights"] . " night" . ($calc["nights"] > 1 ? "s" : "") . ")" : "");
                $lines[] = ["label" => $lbl, "amount" => peso($a["line_total"])];
            }
            json_out([
                "ok" => true,
                "lines" => $lines,
                "checkin" => pay_fmt_dt($calc["check_in_dt"], "in", "D, M d, Y"),
                "checkout" => pay_fmt_dt($calc["check_out_dt"], "out", "D, M d, Y"),
                "nights" => $calc["nights"],
                "guests" => $guests,
                "total" => peso($calc["total"]),
                "percent" => pay_percent_display($calc["percent"]),
                "down" => peso($calc["down_payment"]),
                "remaining" => peso($calc["remaining"]),
            ]);
        }

        // ----- SUBMIT (GCash receipt upload) -----
        $up = pay_handle_upload(
            $_FILES["receipt_file"] ?? [],
            PAY_RECEIPT_MIMES,
            PERGOLA_ROOT . "/uploads/receipts/",
            "uploads/receipts/",
            "receipt_" . $user_id,
            "private"
        );
        if (!$up["ok"]) json_out(["ok" => false, "error" => $up["error"]]);

        $reservation_id = 0;
        $conn->begin_transaction();
        try {
            // Lock this accommodation so two customers can't book the same dates at once
            $lock = $conn->prepare("SELECT id FROM accommodations WHERE id = ? FOR UPDATE");
            $lock->bind_param("i", $accommodation_id);
            $lock->execute();
            $lock->close();

            if (!pay_is_available($conn, $accommodation_id, $check_in, $check_out)) {
                throw new RuntimeException("This accommodation was just booked for those dates. Please choose different dates.");
            }

            // Recalculate under lock (never trust the browser)
            $calc = pay_calc_booking($conn, $accommodation_id, $check_in, $check_out, $sel);
            if (!$calc["ok"]) throw new RuntimeException($calc["error"]);

            $total = $calc["total"];
            $down = $calc["down_payment"];
            $remaining = $calc["remaining"];
            $res_status = "pending_payment_verification";

            $reservation_id = pay_insert($conn, "reservations", [
                "user_id" => $user_id,
                "accommodation_id" => $accommodation_id,
                "check_in" => $calc["check_in_dt"],     // e.g. 2026-11-01 15:00:00 (3:00 PM)
                "check_out" => $calc["check_out_dt"],   // e.g. 2026-11-03 12:00:00 (12:00 PM noon)
                "total_amount" => $total,
                "status" => $res_status,
            ], ["created_at" => "NOW()"], [
                // values for any extra required columns your table may have
                "price" => $calc["unit_price"], "unit_price" => $calc["unit_price"], "room_price" => $calc["unit_price"],
                "rate" => $calc["unit_price"], "total_price" => $total, "subtotal" => $total, "total" => $total,
                "amount" => $total, "nights" => $calc["nights"], "num_nights" => $calc["nights"],
                "guests" => $guests, "num_guests" => $guests, "number_of_guests" => $guests, "adults" => $guests, "pax" => $guests,
            ]);

            if (!empty($calc["addons"])) {
                foreach ($calc["addons"] as $a) {
                    // The price is stored with the booking, so later admin price changes never alter it
                    pay_insert($conn, "reservation_addons", [
                        "reservation_id" => $reservation_id,
                        "addon_id" => (int)$a["id"],
                        "price" => $a["price"],
                        "quantity" => 1,
                        "line_total" => $a["line_total"],
                        "pricing_method" => $a["pricing_method"],
                    ]);
                }
            }

            $receipt_path = $up["path"];
            pay_insert($conn, "payments", [
                "reservation_id" => $reservation_id,
                "amount" => $down,
                "receipt_image" => $receipt_path,
                "total_amount" => $total,
                "down_payment" => $down,
                "remaining_balance" => $remaining,
            ], ["method" => "'gcash'", "status" => "'pending'", "payment_date" => "NOW()"]);

          
            $conn->commit();
        } catch (Throwable $ex) {
            $conn->rollback();
            @unlink($up["abs"]);
            if (!($ex instanceof RuntimeException)) error_log("book.php: " . $ex->getMessage());
            if ($ex instanceof RuntimeException) {
                $friendly = $ex->getMessage();
            } else {
                $friendly = $PAY_DEBUG ? "Database error: " . $ex->getMessage() : "We couldn't save your booking. Please try again.";
            }
            json_out(["ok" => false, "error" => $friendly]);
        }

        json_out(["ok" => true, "redirect" => "booking_success.php?id=" . $reservation_id]);

    } catch (Throwable $ex) {
        error_log("book.php: " . $ex->getMessage());
        json_out(["ok" => false, "error" => $PAY_DEBUG ? "Error: " . $ex->getMessage() : "Something went wrong. Please try again."]);
    }
}

// Main picture = first gallery image the admin uploaded (falls back to the legacy single image)
$acc_image = $accommodation["image"] ?? ($accommodation["image_url"] ?? "");
$imgs_json = json_decode((string)($accommodation["image_urls"] ?? "[]"), true);
if (is_array($imgs_json) && !empty($imgs_json) && is_string($imgs_json[0] ?? null)) { $acc_image = $imgs_json[0]; }
$tg = $conn->query("SHOW TABLES LIKE 'accommodation_images'");
if ($tg && $tg->num_rows > 0) {
    $gi = $conn->prepare("SELECT image_path FROM accommodation_images WHERE accommodation_id = ? ORDER BY sort_order ASC, id ASC LIMIT 1");
    $gi->bind_param("i", $accommodation_id);
    $gi->execute();
    $g1 = $gi->get_result()->fetch_assoc();
    $gi->close();
    if ($g1 && !empty($g1["image_path"])) $acc_image = $g1["image_path"];
}
$pct_text = pay_percent_display((float)$settings["down_payment_percent"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book <?= e($accommodation["name"]) ?> | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="css/customer.css">
    <link rel="stylesheet" href="css/ui.css">
    <style>
        /* HIDE NOTIFICATION BELLS & EXTRA ICONS */
        .nf-wrap, .nf-bell, .notification-bell, .nav-links i, .nav-links svg {
            display: none !important;
        }

        .steps { display:flex; gap:6px; margin-bottom:25px; flex-wrap:wrap; }
        .step { flex:1; min-width:110px; text-align:center; font-size:11px; padding:10px 6px; border-radius:20px; background:#eceae2; color:#777; font-weight:700; text-transform: uppercase; letter-spacing: 0.3px; transition: all 0.2s ease; }
        .step.active { background: #6f8761; color:#fff; }
        .step.done { background:#e5f4e7; color:#256030; }

        .label { display:block; font-weight:700; margin-bottom:8px; font-size:13.5px; color:#344a30; }
        .field { width:100%; padding:11px 14px; border:1px solid #ede8dc; border-radius:8px; font-size:14px; background:#f7f5ee; color:#344a30; box-sizing:border-box; outline: none; transition: border-color 0.2s ease; }
        .field:focus { border-color: #6f8761; background: #fff; }

        .panel { background:#f9f8f5; padding:20px; border-radius:12px; border:1px solid #ede8dc; margin-bottom:25px; box-shadow: 0 4px 20px rgba(52, 74, 48, 0.02); }
        .qline { display:flex; justify-content:space-between; font-size:14px; padding:6px 0; border-bottom:1px dashed #ede8dc; }
        .qtotal { display:flex; justify-content:space-between; font-size:17px; font-weight:700; padding:10px 0 4px; color: #344a30; }
        .qrow { display:flex; justify-content:space-between; font-size:14.5px; padding:4px 0; }
        .qdown { background:#fff8e1; border:1px solid #f0dc9a; border-radius:10px; padding:12px 14px; margin-top:10px; }
        .msg-error { background:#fbeae8; color:#9c2e25; padding:12px 16px; border-radius:8px; border:1px solid #f5c7c2; font-size:14px; }
        .msg-ok { background:#e5f4e7; color:#256030; padding:12px 16px; border-radius:8px; border:1px solid #e5f4e7; font-size:14px; }
        .progress-track { width:100%; height:12px; background:#e3dec9; border-radius:6px; overflow:hidden; margin-top:8px; }
        .progress-fill { height:100%; width:0; background:#6f8761; transition:width .2s ease; }
        #receiptPreview img { max-width:220px; max-height:220px; border-radius:8px; border:1px solid #ede8dc; margin-top:10px; }

        /* ----- availability calendar ----- */
        .cal { max-width:380px; margin:0 auto; }
        .cal-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; }
        .cal-nav { border:1px solid #ede8dc; background:#fff; border-radius:8px; width:36px; height:36px; font-size:18px; cursor:pointer; color:#344a30; transition: background 0.2s ease; }
        .cal-nav:hover { background: #f7f5ee; }
        .cal-nav:disabled { opacity:.35; cursor:default; }
        .cal-week, .cal-grid { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; }
        .cal-week span { text-align:center; font-size:11px; font-weight:700; color:#777; padding:4px 0; }
        .cal-day { border:1px solid transparent; border-radius:8px; padding:9px 0; font-size:13px; font-weight:600; cursor:pointer; background:#e5f4e7; color:#256030; transition: all 0.2s ease; }
        .cal-day:hover { filter: brightness(0.95); }
        .cal-day.booked { background:#fbeae8; color:#9c2e25; text-decoration:line-through; cursor: not-allowed; }
        .cal-day.past { background:#f0ede4; color:#aaa; cursor:default; }
        .cal-day.range { background:#cfe3c7; color:#1f4d1f; }
        .cal-day.sel { background:#6f8761; color:#fff; text-decoration:none; }
        .cal-empty { visibility:hidden; }
        .cal-legend { display:flex; flex-wrap:wrap; gap:14px; justify-content:center; font-size:12px; color:#555; margin-top:12px; }
        .cal-legend i { display:inline-block; width:12px; height:12px; border-radius:3px; margin-right:5px; vertical-align:-1px; }
        .cal-status { min-height:20px; text-align:center; font-size:14px; font-weight:600; margin-top:10px; }
        .cal-status.ok { color:#256030; } .cal-status.bad { color:#9c2e25; }

        /* Section Title & Card Polish */
        .section-title {
            border-bottom: 1px solid #ede8dc;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }
        .card {
            border: 1px solid #ede8dc !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04) !important;
        }
        .primary-btn {
            background: #6f8761 !important;
            border: 1px solid #526b49 !important;
            border-radius: 8px !important;
            font-weight: 700 !important;
            transition: all 0.2s ease !important;
        }
        .primary-btn:hover {
            background: #526b49 !important;
            transform: translateY(-1px);
        }
        .primary-btn:disabled {
            background: #ccc !important;
            border-color: #bbb !important;
            cursor: not-allowed !important;
            transform: none !important;
        }

        /* ----- add-ons ----- */
        .addon-panel { padding: 8px 20px 20px; }
        .addon-cat-title { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #6f8761; padding: 18px 0 6px; border-bottom: 1px solid #ede8dc; margin-bottom: 4px; }
        .addon-item { display: flex; align-items: center; justify-content: space-between; gap: 14px; font-size: 14px; cursor: pointer; padding: 10px 10px; margin: 0 -10px; border-radius: 8px; transition: background 0.15s ease; }
        .addon-item:hover { background: #f1efe6; }
        .addon-item.checked { background: #e9f1e4; }
        .addon-left { display: flex; align-items: flex-start; gap: 12px; min-width: 0; }
        .addon-left input[type=checkbox] { width: 18px; height: 18px; margin: 2px 0 0; accent-color: #6f8761; flex: 0 0 auto; cursor: pointer; }
        .addon-name { display: block; color: #344a30; font-weight: 600; }
        .addon-desc { display: block; font-size: 12.5px; color: #777; margin-top: 2px; }
        .addon-price { color: #344a30; white-space: nowrap; text-align: right; }
        .addon-price small { display: block; font-size: 11px; font-weight: 600; color: #777; }
        .addon-summary { background: #fff; border: 1px solid #ede8dc; border-radius: 10px; padding: 12px 16px; margin-top: 18px; }
        .addon-summary .qtotal { padding-bottom: 0; }
        .addon-note { font-size: 12px; color: #777; margin-top: 8px; }
    </style>
    <!-- PayPal JavaScript SDK (uses the public Client ID only) -->
    <?php if ($paypal_enabled): ?>
    <script src="https://www.paypal.com/sdk/js?client-id=<?= e(PAYPAL_CLIENT_ID) ?>&amp;currency=<?= e(PAYPAL_CURRENCY) ?>"></script>
    <?php endif; ?>
</head>
<body>

<header class="navbar">
    <div class="nav-container">
        <a href="customer/home.php" class="nav-brand">
            <h2><?= e($resort_name) ?></h2>
        </a>
        <nav class="nav-links">
            <a href="customer/home.php">Home</a>
            <a href="accommodations.php" class="active">Accommodations</a>
            <a href="customer/dashboard.php">Dashboard</a>
            <a href="customer/my_bookings.php">My Bookings</a>
            <a href="customer/profile.php">Profile</a>
            <a href="logout.php" class="nav-btn-outline">Sign Out</a>
        </nav>
    </div>
</header>

<main class="container" style="padding: 40px 20px; max-width: 800px;">
    <div class="section-title">
        <h1>Reserve &amp; Pay Down Payment</h1>
        <p>A <?= e($pct_text) ?>% down payment via GCash or PayPal is required to secure your reservation. <em>Note: PayPal may require a valid customer mobile number on your profile.</em></p>
    </div>

    <div class="steps" id="steps">
        <div class="step active" data-s="1">1. Booking Details</div>
        <div class="step" data-s="2">2. Payment Info</div>
        <div class="step" data-s="3">3. Upload Receipt</div>
        <div class="step" data-s="4">4. Admin Verify</div>
        <div class="step" data-s="5">5. Confirmed</div>
    </div>

    <div class="card" style="padding: 30px; margin-bottom: 30px;">
        <div style="display:flex; gap:20px; flex-wrap:wrap; align-items:center; border-bottom:1px solid #ede8dc; padding-bottom:20px; margin-bottom:20px;">
            <?php if (!empty($acc_image)): ?>
                <div style="width:150px; height:110px; background-image:url('<?= e($acc_image) ?>'); background-size:cover; background-position:center; border-radius:8px;"></div>
            <?php endif; ?>
            <div>
                <h2 style="color:#344a30; font-size:22px; margin-bottom:5px; font-weight:700;"><?= e($accommodation["name"]) ?></h2>
                <p style="color:#666; font-size:14px; margin-bottom:5px;">Max Capacity: <?= $max_capacity ?> Persons</p>
                <p style="font-size:16px; color:#344a30; font-weight: 700;"><strong><?= peso($accommodation["price"]) ?></strong> / night</p>
            </div>
        </div>

        <form id="bookForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <!-- ===== AVAILABILITY CALENDAR ===== -->
            <div class="panel" id="calPanel">
                <label class="label" style="text-align:center;">Check availability &amp; pick your dates</label>
                <div class="cal">
                    <div class="cal-head">
                        <button type="button" class="cal-nav" id="calPrev" aria-label="Previous month">&#8249;</button>
                        <strong id="calTitle" style="color: #344a30;"></strong>
                        <button type="button" class="cal-nav" id="calNext" aria-label="Next month">&#8250;</button>
                    </div>
                    <div class="cal-week"><span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span></div>
                    <div class="cal-grid" id="calGrid"></div>
                </div>
                <div class="cal-legend">
                    <span><i style="background:#e5f4e7;border:1px solid #cfe3c7"></i>Available</span>
                    <span><i style="background:#fbeae8"></i>Booked</span>
                    <span><i style="background:#6f8761"></i>Your dates</span>
                </div>
                <div id="calStatus" class="cal-status" role="status"></div>
                <p style="font-size:12px; color:#777; text-align:center; margin-top:6px;">Click a check-in date, then a check-out date. You can also type the dates below.</p>
            </div>

            <div style="display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:20px;">
                <div>
                    <label class="label" for="check_in">Check-In Date</label>
                    <input class="field" type="date" name="check_in" id="check_in" required min="<?= date('Y-m-d') ?>">
                </div>
                <div>
                    <label class="label" for="check_out">Check-Out Date</label>
                    <input class="field" type="date" name="check_out" id="check_out" required min="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                </div>
            </div>

            <!-- ===== NUMBER OF GUESTS ===== -->
            <div style="margin-bottom:20px;">
                <label class="label" for="guests">Number of Guests</label>
                <input class="field" type="number" name="guests" id="guests" min="1" max="<?= $max_capacity ?>" value="1" required>
            </div>

            <div class="panel" style="display:flex; gap:24px; flex-wrap:wrap; justify-content:space-between; font-size:14px;">
                <div><strong>Check-in time:</strong> <span id="schedIn">3:00 PM</span> <span style="color:#777;">(standard)</span></div>
                <div><strong>Check-out time:</strong> <span id="schedOut">12:00 PM (noon)</span> <span style="color:#777;">(standard)</span></div>
            </div>

            <?php if ($addons): ?>
            <div style="margin-bottom:25px;">
                <label class="label">Optional Add-ons</label>
                <div class="panel addon-panel">
                    <?php foreach ($addon_groups_sorted as $cat_name => $items): ?>
                        <?php if ($has_cat): ?><div class="addon-cat-title"><?= e($cat_name) ?></div><?php endif; ?>
                        <?php foreach ($items as $addon):
                            $is_pn = (($addon["pricing_method"] ?? "") === "per_night"); ?>
                            <label class="addon-item">
                                <span class="addon-left">
                                    <input type="checkbox" class="addon-cb" name="addons[]" value="<?= (int)$addon["id"] ?>"
                                           data-price="<?= e(number_format((float)$addon["price"], 2, ".", "")) ?>"
                                           data-method="<?= $is_pn ? "per_night" : "flat" ?>">
                                    <span>
                                        <span class="addon-name"><?= e($addon["name"]) ?></span>
                                        <?php if (!empty($addon["description"])): ?><span class="addon-desc"><?= e($addon["description"]) ?></span><?php endif; ?>
                                    </span>
                                </span>
                                <strong class="addon-price"><?= peso($addon["price"]) ?><?php if ($is_pn): ?><small>per night</small><?php endif; ?></strong>
                            </label>
                        <?php endforeach; ?>
                    <?php endforeach; ?>

                    <!-- Live total: updates as soon as an add-on is ticked or the dates change -->
                    <div class="addon-summary" id="addonSummary" aria-live="polite">
                        <div class="qline"><span>Add-ons selected</span><strong id="adCount">0</strong></div>
                        <div class="qline"><span>Add-ons subtotal</span><strong id="adSub">₱0.00</strong></div>
                        <div class="qline" id="adRoomRow" style="display:none;"><span id="adRoomLbl">Accommodation</span><strong id="adRoom">₱0.00</strong></div>
                        <div class="qtotal"><span id="adTotalLbl">Add-ons total</span><span id="adTotal">₱0.00</span></div>
                        <div class="addon-note" id="adNote">Pick your dates to include the accommodation in this total.</div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- QUOTE -->
            <div id="quoteBox" class="panel" style="display:none;"></div>

            <!-- PAYMENT INFO + RECEIPT (shown only after an available quote) -->
            <div id="paySection" style="display:none;">
                <div class="panel" style="text-align:center;">
                    <h3 style="color:#344a30; font-size:17px; margin-bottom:6px; font-weight:700;">PAYMENT REQUIRED</h3>
                    <p style="font-size:13px; color:#555; margin-bottom:14px;">
                        <?= nl2br(e($settings["instructions"] ?: "To secure your reservation, a down payment is required.")) ?>
                    </p>
                    <div style="font-size:14px; margin-bottom:6px;">Payment Method: <strong style="color: #0047ff;">GCASH</strong></div>
                    <?php if (!empty($settings["gcash_qr"])): ?>
                        <div style="margin:14px 0;">
                            <img src="<?= e($settings["gcash_qr"]) ?>" alt="GCash QR Code" style="width:200px; height:200px; object-fit:contain; border:1px solid #ede8dc; border-radius:8px; background:#fff;">
                        </div>
                    <?php endif; ?>
                    <p style="font-size:14px; margin:4px 0;">GCash Account Name: <strong><?= e($settings["gcash_name"]) ?></strong></p>
                    <p style="font-size:14px; margin:4px 0;">GCash Number: <strong><?= e($settings["gcash_number"]) ?></strong></p>
                    <p style="font-size:13px; margin-top:12px; color:#8a6d00; font-weight:600;" id="payAmountNote"></p>
                </div>

                <div style="margin-bottom:25px;">
                    <label class="label" for="receipt_file">Upload Payment Receipt</label>
                    <input class="field" type="file" name="receipt_file" id="receipt_file" accept="image/jpeg,image/png,image/webp,application/pdf" style="background:#fff; border: 1px dashed #c9b8b8;">
                    <span style="font-size:12px; color:#666; display:block; margin-top:5px;">Accepted: JPG, PNG, WEBP, PDF &nbsp;|&nbsp; Maximum size: 5MB</span>
                    <div id="receiptOk" style="display:none; color:#256030; font-weight:700; font-size:14px; margin-top:8px;">✓ Receipt selected</div>
                    <div id="receiptPreview"></div>
                </div>

                <div id="progressWrap" style="display:none; margin-bottom:15px;">
                    <div id="progressText" style="font-size:13px; font-weight:700; color:#344a30;">Uploading receipt...</div>
                    <div class="progress-track"><div class="progress-fill" id="progressFill"></div></div>
                </div>
                <div id="formMsg" style="margin-bottom:15px; display:none;"></div>

                <button type="submit" id="submitBtn" class="btn primary-btn full-width" style="padding:14px; font-size:16px;" disabled>Submit Booking &amp; Payment Receipt</button>
                <p id="submitHint" style="font-size:12.5px; color:#8a6d00; font-weight:600; text-align:center; margin:8px 0 0;"></p>
                <p style="font-size:12px; color:#777; text-align:center; margin-top:10px;">Your reservation will be confirmed only after our admin verifies your payment receipt.</p>

                <?php if ($paypal_enabled): ?>
                <!-- ===== PAYPAL OPTION ===== -->
                <div class="panel" style="text-align:center; margin-top:25px;">
                    <h3 style="color:#344a30; font-size:17px; margin-bottom:6px; font-weight:700;">OR PAY INSTANTLY WITH PAYPAL</h3>
                    <p style="font-size:13px; color:#555; margin-bottom:14px;">Pay the down payment now. Your reservation is confirmed automatically, with no receipt upload needed. Make sure your mobile number is updated in your <a href="customer/profile.php" style="color:#0047ff; font-weight:700;">Profile</a>.</p>
                    <div id="paypal-buttons"></div>
                </div>
                <?php endif; ?>
            </div>
        </form>
    </div>
</main>

<footer class="footer">
    <div class="container"><p>&copy; <?= date("Y") ?> <?= e($resort_name) ?>. All rights reserved.</p></div>
</footer>

<script>
(function () {
    const accId = <?= (int)$accommodation_id ?>;
    const form = document.getElementById('bookForm');
    const checkIn = document.getElementById('check_in');
    const checkOut = document.getElementById('check_out');
    const guestsInput = document.getElementById('guests');
    const quoteBox = document.getElementById('quoteBox');
    const paySection = document.getElementById('paySection');
    const payNote = document.getElementById('payAmountNote');
    const fileInput = document.getElementById('receipt_file');
    const receiptOk = document.getElementById('receiptOk');
    const receiptPreview = document.getElementById('receiptPreview');
    const submitBtn = document.getElementById('submitBtn');
    const formMsg = document.getElementById('formMsg');
    const progressWrap = document.getElementById('progressWrap');
    const progressFill = document.getElementById('progressFill');
    const progressText = document.getElementById('progressText');
    let quoteOk = false;
    let seq = 0;

    function setStep(n) {
        document.querySelectorAll('#steps .step').forEach(function (el) {
            const s = parseInt(el.dataset.s, 10);
            el.classList.toggle('done', s < n);
            el.classList.toggle('active', s === n);
        });
    }
    function el(tag, cls, text) {
        const x = document.createElement(tag);
        if (cls) x.className = cls;
        if (text !== undefined) x.textContent = text;
        return x;
    }
    function showMsg(text, ok) {
        formMsg.style.display = text ? 'block' : 'none';
        formMsg.className = ok ? 'msg-ok' : 'msg-error';
        formMsg.textContent = text || '';
    }
    function refreshSubmit() {
        submitBtn.disabled = !(quoteOk && fileInput.files.length === 1);
        const hint = document.getElementById('submitHint');
        if (hint) hint.textContent = (quoteOk && submitBtn.disabled) ? 'Upload your GCash receipt above to enable this button.' : '';
        if (quoteOk) setStep(fileInput.files.length ? 3 : 2); else setStep(1);
    }
    function buildData(withFile) {
        const fd = new FormData(form);
        if (!withFile) fd.delete('receipt_file');
        return fd;
    }

    function renderQuote(d) {
        quoteBox.innerHTML = '';
        quoteBox.style.display = 'block';
        const sch = el('div', 'qline');
        sch.appendChild(el('span', '', 'Check-in'));
        sch.appendChild(el('strong', '', d.checkin));
        quoteBox.appendChild(sch);
        const sch2 = el('div', 'qline');
        sch2.appendChild(el('span', '', 'Check-out'));
        sch2.appendChild(el('strong', '', d.checkout));
        quoteBox.appendChild(sch2);
        
        const gRow = el('div', 'qline');
        gRow.appendChild(el('span', '', 'Guests'));
        gRow.appendChild(el('strong', '', d.guests + ' guest' + (d.guests > 1 ? 's' : '')));
        quoteBox.appendChild(gRow);

        d.lines.forEach(function (l) {
            const row = el('div', 'qline');
            row.appendChild(el('span', '', l.label));
            row.appendChild(el('strong', '', l.amount));
            quoteBox.appendChild(row);
        });
        const tot = el('div', 'qtotal');
        tot.appendChild(el('span', '', 'TOTAL'));
        tot.appendChild(el('span', '', d.total));
        quoteBox.appendChild(tot);

        const dp = el('div', 'qdown');
        const r1 = el('div', 'qrow'); r1.appendChild(el('strong', '', d.percent + '% DOWN PAYMENT REQUIRED')); r1.appendChild(el('strong', '', d.down));
        const r2 = el('div', 'qrow'); r2.appendChild(el('span', '', 'REMAINING BALANCE')); r2.appendChild(el('span', '', d.remaining));
        dp.appendChild(r1); dp.appendChild(r2);
        quoteBox.appendChild(dp);

        payNote.textContent = 'Please send exactly ' + d.down + ' (' + d.percent + '% down payment). Total booking: ' + d.total + ' | Remaining balance: ' + d.remaining;
    }

    function renderError(text) {
        quoteBox.innerHTML = '';
        quoteBox.style.display = 'block';
        const m = el('div', 'msg-error', text);
        quoteBox.appendChild(m);
    }

    async function requestQuote() {
        showMsg('');
        if (!checkIn.value || !checkOut.value) {
            quoteBox.style.display = 'none';
            paySection.style.display = 'none';
            setCalStatus('', true);
            quoteOk = false; refreshSubmit();
            return;
        }
        const my = ++seq;
        quoteBox.style.display = 'block';
        quoteBox.textContent = 'Checking availability & calculating...';
        try {
            const r = await fetch('book.php?id=' + accId + '&action=quote', { method: 'POST', body: buildData(false), credentials: 'same-origin' });
            const txt = await r.text();
            let d = null;
            try { d = JSON.parse(txt); } catch (e) { d = null; }
            if (my !== seq) return;
            if (!d) {
                quoteOk = false;
                renderError('The server sent an unexpected reply (' + r.status + '). Please refresh the page and try again.');
                paySection.style.display = 'none';
                refreshSubmit();
                return;
            }
            if (d.ok) {
                quoteOk = true;
                renderQuote(d);
                setCalStatus('✓ Available for your selected dates.', true);
                paySection.style.display = 'block';
                try { ensurePayPal(); } catch (ppErr) { console.error(ppErr); }   // a PayPal problem must never block GCash booking
            } else {
                quoteOk = false;
                renderError(d.error || 'Unable to calculate your booking.');
                setCalStatus(d.error || 'These dates are not available.', false);
                loadAvail();   // refresh booked dates in case someone just booked
                paySection.style.display = 'none';
            }
        } catch (err) {
            if (my !== seq) return;
            quoteOk = false;
            renderError('Network error. Please try again.');
            paySection.style.display = 'none';
        }
        refreshSubmit();
    }

    checkIn.addEventListener('change', function () {
        if (checkIn.value) {
            const d = new Date(checkIn.value + 'T00:00:00');
            d.setDate(d.getDate() + 1);
            const iso = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
            checkOut.min = iso;
            if (checkOut.value && checkOut.value < iso) checkOut.value = '';
        }
        requestQuote();
    });
    checkOut.addEventListener('change', requestQuote);
    guestsInput.addEventListener('change', requestQuote);
    guestsInput.addEventListener('input', function() {
        const max = parseInt(guestsInput.max, 10) || 1;
        let val = parseInt(guestsInput.value, 10) || 1;
        if (val > max) guestsInput.value = max;
        if (val < 1) guestsInput.value = 1;
    });
    form.querySelectorAll('input[name="addons[]"]').forEach(function (cb) { cb.addEventListener('change', requestQuote); });

    // ---------- LIVE ADD-ON TOTAL (instant preview; the server quote below stays the final amount) ----------
    const roomPrice = <?= json_encode((float)$accommodation["price"]) ?>;
    const addonCbs = Array.prototype.slice.call(form.querySelectorAll('.addon-cb'));
    function money(n) { return '\u20B1' + n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function stayNights() {
        if (!checkIn.value || !checkOut.value) return 0;
        const n = Math.round((new Date(checkOut.value + 'T00:00:00Z') - new Date(checkIn.value + 'T00:00:00Z')) / 86400000);
        return n > 0 ? n : 0;
    }
    function updateAddonTotal() {
        const nights = stayNights();
        const mult = nights > 0 ? nights : 1;       // per-night add-ons count 1 night until dates are chosen
        let sub = 0, count = 0;
        addonCbs.forEach(function (cb) {
            cb.closest('.addon-item').classList.toggle('checked', cb.checked);
            if (!cb.checked) return;
            count++;
            const price = parseFloat(cb.dataset.price) || 0;
            sub += cb.dataset.method === 'per_night' ? price * mult : price;
        });
        const set = function (id, t) { const x = document.getElementById(id); if (x) x.textContent = t; };
        set('adCount', String(count));
        set('adSub', money(sub));
        const roomRow = document.getElementById('adRoomRow');
        if (!roomRow) return;
        if (nights > 0) {
            const room = roomPrice * nights;
            roomRow.style.display = 'flex';
            set('adRoomLbl', 'Accommodation (' + nights + ' night' + (nights > 1 ? 's' : '') + ' \u00D7 ' + money(roomPrice) + ')');
            set('adRoom', money(room));
            set('adTotalLbl', 'Estimated total');
            set('adTotal', money(room + sub));
            set('adNote', 'Estimated total before the down payment. The final amount is confirmed in your booking summary below.');
        } else {
            roomRow.style.display = 'none';
            set('adTotalLbl', 'Add-ons total');
            set('adTotal', money(sub));
            set('adNote', 'Pick your dates to include the accommodation' + (addonCbs.some(function (c) { return c.checked && c.dataset.method === 'per_night'; }) ? ' and the full per-night charges' : '') + ' in this total.');
        }
    }
    addonCbs.forEach(function (cb) { cb.addEventListener('change', updateAddonTotal); });
    checkIn.addEventListener('change', updateAddonTotal);
    checkOut.addEventListener('change', updateAddonTotal);
    updateAddonTotal();

    fileInput.addEventListener('change', function () {
        receiptPreview.innerHTML = '';
        receiptOk.style.display = 'none';
        showMsg('');
        const f = fileInput.files[0];
        if (f) {
            const okTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
            if (okTypes.indexOf(f.type) === -1) {
                showMsg('Invalid file type. Please choose a JPG, PNG, WEBP or PDF.', false);
                fileInput.value = '';
            } else if (f.size > 5 * 1024 * 1024) {
                showMsg('File is larger than 5MB. Please choose a smaller file.', false);
                fileInput.value = '';
            } else {
                receiptOk.style.display = 'block';
                if (f.type.indexOf('image/') === 0) {
                    const img = document.createElement('img');
                    img.src = URL.createObjectURL(f);
                    img.alt = 'Receipt preview';
                    receiptPreview.appendChild(img);
                } else {
                    receiptPreview.appendChild(el('div', '', 'PDF: ' + f.name));
                }
            }
        }
        refreshSubmit();
    });

    form.addEventListener('submit', function (ev) {
        ev.preventDefault();
        if (submitBtn.disabled) return;

        submitBtn.disabled = true;
        showMsg('');
        progressWrap.style.display = 'block';
        progressFill.style.background = '#6f8761';
        progressFill.style.width = '0%';
        progressText.textContent = 'Uploading receipt... 0%';

        const xhr = new XMLHttpRequest();
        xhr.open('POST', 'book.php?id=' + accId + '&action=submit', true);

        xhr.upload.onprogress = function (e) {
            if (e.lengthComputable) {
                const pct = Math.round((e.loaded / e.total) * 100);
                progressFill.style.width = pct + '%';
                progressText.textContent = 'Uploading receipt... ' + pct + '%';
            }
        };
        xhr.upload.onload = function () {
            progressFill.style.width = '100%';
            progressText.textContent = 'Receipt uploaded successfully. Submitting booking...';
        };
        function fail(msg) {
            submitBtn.disabled = false;
            progressFill.style.background = '#9c2e25';
            progressText.textContent = 'Submission failed.';
            showMsg(msg, false);
        }
        xhr.onload = function () {
            let d = null;
            try { d = JSON.parse(xhr.responseText); } catch (e) {}
            if (d && d.ok) {
                progressText.textContent = 'Booking submitted successfully.';
                window.location.href = d.redirect;
            } else {
                fail((d && d.error) ? d.error : 'Server error (' + xhr.status + '). Please try again.');
            }
        };
        xhr.onerror = function () { fail('Network error. Please try again.'); };
        xhr.send(buildData(true));
    });

    // ---------- AVAILABILITY CALENDAR ----------
    const calGrid = document.getElementById('calGrid');
    const calTitle = document.getElementById('calTitle');
    const calStatus = document.getElementById('calStatus');
    const calPrev = document.getElementById('calPrev');
    const calNext = document.getElementById('calNext');
    const todayIso = '<?= date("Y-m-d") ?>';
    const MONTHS = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    const booked = new Set();
    const startY = parseInt(todayIso.slice(0, 4), 10), startM = parseInt(todayIso.slice(5, 7), 10) - 1;
    let viewY = startY, viewM = startM;

    function pad(n) { return String(n).padStart(2, '0'); }
    function addDays(iso, n) {
        const d = new Date(iso + 'T00:00:00Z');
        d.setUTCDate(d.getUTCDate() + n);
        return d.toISOString().slice(0, 10);
    }
    function setCalStatus(text, ok) {
        calStatus.textContent = text || '';
        calStatus.className = 'cal-status' + (text ? (ok ? ' ok' : ' bad') : '');
    }
    function rangeFree(a, b) {            // nights a .. b-1 must be free (checkout day itself may be booked)
        for (let d = a, i = 0; d < b && i < 400; d = addDays(d, 1), i++) { if (booked.has(d)) return false; }
        return true;
    }
    function fire(input) { input.dispatchEvent(new Event('change')); }

    function renderCal() {
        calTitle.textContent = MONTHS[viewM] + ' ' + viewY;
        calGrid.innerHTML = '';
        const ci = checkIn.value, co = checkOut.value;
        const first = new Date(Date.UTC(viewY, viewM, 1)).getUTCDay();
        const days = new Date(Date.UTC(viewY, viewM + 1, 0)).getUTCDate();
        for (let i = 0; i < first; i++) calGrid.appendChild(el('span', 'cal-empty'));
        for (let d = 1; d <= days; d++) {
            const iso = viewY + '-' + pad(viewM + 1) + '-' + pad(d);
            const b = el('button', 'cal-day', String(d));
            b.type = 'button';
            if (iso < todayIso) { b.classList.add('past'); b.disabled = true; }
            else if (booked.has(iso)) { b.classList.add('booked'); b.title = 'Booked'; }
            if (ci && co && iso > ci && iso < co) b.classList.add('range');
            if (iso === ci || (co && iso === co)) b.classList.add('sel');
            b.addEventListener('click', function () { pickDate(iso); });
            calGrid.appendChild(b);
        }
        const idx = (viewY - startY) * 12 + (viewM - startM);
        calPrev.disabled = idx <= 0;
        calNext.disabled = idx >= 12;
    }

    function pickDate(iso) {
        setCalStatus('');
        const ci = checkIn.value, co = checkOut.value;
        if (!ci || co || iso <= ci) {                       // start a new selection
            if (booked.has(iso)) { setCalStatus('That night is already booked. Please pick another date.', false); return; }
            checkOut.value = '';
            checkIn.value = iso;
            fire(checkIn);
            return;
        }
        if (!rangeFree(ci, iso)) {                          // second click: validate the whole stay
            setCalStatus('Some nights in that range are already booked. Please choose different dates.', false);
            return;
        }
        checkOut.value = iso;
        fire(checkOut);
    }

    function loadAvail() {
        fetch('book.php?id=' + accId + '&action=availability', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) return;
                booked.clear();
                d.ranges.forEach(function (rg) {
                    for (let x = rg[0], i = 0; x < rg[1] && i < 400; x = addDays(x, 1), i++) booked.add(x);
                });
                renderCal();
            })
            .catch(function () { renderCal(); });
    }

    calPrev.addEventListener('click', function () { viewM--; if (viewM < 0) { viewM = 11; viewY--; } renderCal(); });
    calNext.addEventListener('click', function () { viewM++; if (viewM > 11) { viewM = 0; viewY++; } renderCal(); });
    checkIn.addEventListener('change', function () {
        if (checkIn.value) { viewY = parseInt(checkIn.value.slice(0, 4), 10); viewM = parseInt(checkIn.value.slice(5, 7), 10) - 1; }
        renderCal();
    });
    checkOut.addEventListener('change', renderCal);
    renderCal();
    loadAvail();

    // ---------- PAYPAL ----------
    let ppRendered = false;
    function ensurePayPal() {
        const ppBox = document.getElementById('paypal-buttons');
        if (!ppBox || ppRendered) return;
        if (typeof paypal === 'undefined') {
            ppBox.textContent =
                'PayPal could not load. Check the Client ID in paypal_config.php (run paypal_test.php).';
            return;
        }
        ppRendered = true;
        const csrfVal = form.querySelector('input[name="csrf"]').value;

        paypal.Buttons({
            onClick: function (data, actions) {
                if (!quoteOk) { showMsg('Please select valid dates first.', false); return actions.reject(); }
                showMsg('');
            },
            createOrder: function () {
                const fd = buildData(false);
                fd.append('accommodation_id', accId);
                return fetch('api/paypal_create_order.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.id) throw new Error(d.error || 'Could not start PayPal payment.');
                        return d.id;
                    });
            },
            onApprove: function (data) {
                const fd = new FormData();
                fd.append('csrf', csrfVal);
                fd.append('orderID', data.orderID);
                return fetch('api/paypal_capture_order.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (d.ok) { window.location.href = d.redirect; }
                        else { showMsg(d.error || 'Payment failed.', false); }
                    });
            },
            onCancel: function () { showMsg('You cancelled the PayPal payment. You were not charged.', false); },
            onError: function (err) {
                showMsg((err && err.message) ? err.message : 'PayPal error. Please try again.', false);
                console.error(err);
            }
        }).render('#paypal-buttons');
    }
})();
</script>
<script src="js/app.js" data-base="" data-role="customer"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll('.nf-wrap, .nf-bell, .notification-bell').forEach(function (el) { el.remove(); });
});
</script>
</body>
</html>