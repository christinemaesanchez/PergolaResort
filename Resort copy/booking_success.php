<?php
require_once "config.php";
require_once "payment_lib.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}
$user_id = (int)$_SESSION["user_id"];
$rid = (int)($_GET["id"] ?? 0);

$s = $conn->prepare("SELECT r.id, r.check_in, r.check_out, r.total_amount, r.status AS res_status,
        a.name AS acc_name, p.amount, p.status AS pay_status, p.down_payment, p.remaining_balance
    FROM reservations r
    JOIN accommodations a ON a.id = r.accommodation_id
    LEFT JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
    WHERE r.id = ? AND r.user_id = ? LIMIT 1");
$s->bind_param("ii", $rid, $user_id);
$s->execute();
$b = $s->get_result()->fetch_assoc();
$s->close();

if (!$b) {
    header("Location: customer/my_bookings.php");
    exit();
}

$resort_name = "Pergola Resort";
$ri = $conn->query("SELECT resort_name FROM resort_info LIMIT 1");
if ($ri && $ri->num_rows > 0) $resort_name = $ri->fetch_assoc()["resort_name"];

$total = (float)$b["total_amount"];
$down = (float)($b["down_payment"] ?? $b["amount"]);
$submitted = (float)$b["amount"];
$remaining = pay_remaining($total, $submitted, (string)$b["pay_status"]);
[$pay_text, $pay_cls] = pay_status_info((string)$b["pay_status"]);
[$res_text, $res_cls] = res_status_info((string)$b["res_status"]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Submitted | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="css/customer.css">
    <style><?= pay_badge_css() ?>
        .srow { display:flex; justify-content:space-between; padding:9px 0; border-bottom:1px solid #eee; font-size:15px; }
    </style>
</head>
<body>
<header class="navbar">
    <div class="nav-container">
        <a href="index.php" class="nav-brand"><h2><?= e($resort_name) ?></h2></a>
        <nav class="nav-links">
            <a href="index.php">Home</a>
            <a href="accommodations.php">Accommodations</a>
            <a href="customer/my_bookings.php">My Bookings</a>
            <a href="logout.php" class="nav-btn-outline">Sign Out</a>
        </nav>
    </div>
</header>
<main class="container" style="padding:40px 20px; max-width:640px;">
    <div class="card" style="padding:30px;">
        <div style="text-align:center; margin-bottom:20px;">
            <div style="font-size:44px; color:#4caf50;">✓</div>
            <h1 style="color:var(--primary-color); font-size:24px;">BOOKING SUBMITTED SUCCESSFULLY</h1>
        </div>

        <div class="srow"><span>Reservation #</span><strong><?= (int)$b["id"] ?></strong></div>
        <div class="srow"><span>Accommodation</span><strong><?= e($b["acc_name"]) ?></strong></div>
        <div class="srow"><span>Check-in / Check-out</span><strong><?= e(date("M d, Y", strtotime($b["check_in"]))) ?> → <?= e(date("M d, Y", strtotime($b["check_out"]))) ?></strong></div>
        <div class="srow"><span>Total Booking Amount</span><strong><?= peso($total) ?></strong></div>
        <div class="srow"><span>Required Downpayment</span><strong><?= peso($down) ?></strong></div>
        <div class="srow"><span>Amount Submitted</span><strong><?= peso($submitted) ?></strong></div>
        <div class="srow"><span>Remaining Balance</span><strong><?= peso($remaining) ?></strong></div>
        <div class="srow"><span>Payment Status</span><span class="pbadge <?= e($pay_cls) ?>"><?= e($pay_text) ?></span></div>
        <div class="srow"><span>Reservation Status</span><span class="pbadge <?= e($res_cls) ?>"><?= e($res_text) ?></span></div>

        <p style="margin:20px 0; font-size:14px; color:#555; text-align:center;">
            Your payment receipt has been submitted. Our admin will verify your payment before your reservation is confirmed.
        </p>
        <a href="customer/my_bookings.php" class="btn primary-btn full-width" style="text-align:center; text-decoration:none;">View My Booking</a>
    </div>
</main>
</body>
</html>