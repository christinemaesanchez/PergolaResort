<?php
// ---------- 1. CONNECTION + SESSION ----------
require_once "../config.php";
require_once "../payment_lib.php";   // auth_guard(), notifications, shared helpers[cite: 12]
$auth_user = auth_guard($conn, "customer", "../login.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- 2. AUTHENTICATION ----------
if (empty($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}
if (($_SESSION["role"] ?? "") !== "customer") {
    header("Location: ../index.php");
    exit();
}
$user_id = (int)$_SESSION["user_id"];

ensure_payment_schema($conn);

$message = $_SESSION["flash_msg"] ?? "";
$message_type = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_msg"], $_SESSION["flash_type"]);

function bk_flash(string $msg, string $type): void {
    $_SESSION["flash_msg"] = $msg;
    $_SESSION["flash_type"] = $type;
    header("Location: my_bookings.php");
    exit();
}

// ---------- 3. CANCEL A RESERVATION (only the customer's OWN reservation) ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "cancel_reservation") {
    if (!csrf_valid($_POST["csrf"] ?? null)) {
        bk_flash("Your session expired. Please try again.", "error");
    }
    $cancel_id = (int)($_POST["reservation_id"] ?? 0);

    try {
        $conn->begin_transaction();

        $q = $conn->prepare("SELECT id, status, check_in FROM reservations WHERE id = ? AND user_id = ? FOR UPDATE");
        $q->bind_param("ii", $cancel_id, $user_id);
        $q->execute();
        $res = $q->get_result()->fetch_assoc();
        $q->close();

        if (!$res) throw new RuntimeException("Reservation not found.");
        if (!res_can_cancel((string)$res["status"], (string)$res["check_in"])) {
            throw new RuntimeException("This reservation can no longer be cancelled.");
        }

        $c = $conn->prepare("UPDATE reservations SET status = 'cancelled', cancelled_at = NOW() WHERE id = ? AND user_id = ?");
        $c->bind_param("ii", $cancel_id, $user_id);
        $c->execute();
        $c->close();

        $pq = $conn->prepare("SELECT status FROM payments WHERE reservation_id = ? ORDER BY id DESC LIMIT 1");
        $pq->bind_param("i", $cancel_id);
        $pq->execute();
        $pp = $pq->get_result()->fetch_assoc();
        $pq->close();
        $has_pay = $pp && in_array($pp["status"], ["paid", "pending"], true);

        $info = notify_res_info($conn, $cancel_id);
        notify_admins($conn, "reservation_cancelled", "Reservation cancelled by customer",
            ($info["full_name"] ?? "A customer") . " cancelled reservation #" . $cancel_id . " (" . ($info["acc_name"] ?? "") . ", "
            . pay_fmt_dt($info["check_in"] ?? null, "in") . " to " . pay_fmt_dt($info["check_out"] ?? null, "out") . ")."
            . ($has_pay ? " A payment is on record - review it for refund handling." : ""),
            "admin/reservations.php?search=" . $cancel_id, $cancel_id, "res_cancelled:" . $cancel_id);
        notify_user($conn, $user_id, "reservation_cancelled", "Reservation cancelled",
            "Your reservation #" . $cancel_id . " was cancelled." . ($has_pay ? " Your payment is not refunded automatically; the resort will contact you." : ""),
            "customer/my_bookings.php?res=" . $cancel_id, $cancel_id, "res_cancelled_self:" . $cancel_id);

        $conn->commit();
        bk_flash("Reservation #" . $cancel_id . " has been cancelled.", "success");
    } catch (Throwable $ex) {
        $conn->rollback();
        if (!($ex instanceof RuntimeException)) error_log("my_bookings.php: " . $ex->getMessage());
        bk_flash(($ex instanceof RuntimeException) ? $ex->getMessage() : "Could not cancel your reservation. Please try again.", "error");
    }
}

// ---------- 4. UPLOAD A NEW RECEIPT ----------
if ($_SERVER["REQUEST_METHOD"] === "POST" && ($_POST["action"] ?? "") === "reupload_receipt") {
    if (empty($_POST) && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        bk_flash("The file is too large for the server (limit " . ini_get("post_max_size") . ").", "error");
    }
    if (!csrf_valid($_POST["csrf"] ?? null)) {
        bk_flash("Your session expired. Please try again.", "error");
    }
    $payment_id = (int)($_POST["payment_id"] ?? 0);

    $up = pay_handle_upload(
        $_FILES["receipt_file"] ?? [],
        PAY_RECEIPT_MIMES,
        PERGOLA_ROOT . "/uploads/receipts/",
        "uploads/receipts/",
        "receipt_" . $user_id,
        "private"
    );
    if (!$up["ok"]) bk_flash($up["error"], "error");

    try {
        $conn->begin_transaction();

        $q = $conn->prepare("SELECT p.id, p.reservation_id, p.receipt_image
                             FROM payments p JOIN reservations r ON r.id = p.reservation_id
                             WHERE p.id = ? AND r.user_id = ? AND p.status = 'rejected' AND r.status <> 'cancelled' FOR UPDATE");
        $q->bind_param("ii", $payment_id, $user_id);
        $q->execute();
        $pay = $q->get_result()->fetch_assoc();
        $q->close();
        if (!$pay) throw new RuntimeException("This payment cannot be updated.");

        $new = $up["path"];
        $u = $conn->prepare("UPDATE payments SET receipt_image = ?, status = 'pending', payment_date = NOW(),
                             verified_by = NULL, verified_date = NULL WHERE id = ?");
        $u->bind_param("si", $new, $payment_id);
        $u->execute();
        $u->close();

        $rid = (int)$pay["reservation_id"];
        $r = $conn->prepare("UPDATE reservations SET status = 'pending_payment_verification' WHERE id = ? AND user_id = ? AND status = 'payment_verification_failed'");
        $r->bind_param("ii", $rid, $user_id);
        $r->execute();
        $r->close();

        $info = notify_res_info($conn, $rid);
        notify_admins($conn, "payment_submitted", "New receipt to verify",
            ($info["full_name"] ?? "A customer") . " uploaded a new receipt for reservation #" . $rid . ".",
            "admin/reservations.php?tab=payments", $rid, "pay_reupload:" . $payment_id . ":" . md5($new));
        notify_user($conn, $user_id, "payment_submitted", "Receipt received",
            "We received your new receipt for reservation #" . $rid . ". Our admin will verify it shortly.",
            "customer/my_bookings.php?res=" . $rid, $rid, "pay_reupload_self:" . $payment_id . ":" . md5($new));

        $conn->commit();
        pay_delete_receipt($pay["receipt_image"]);
        bk_flash("New receipt uploaded. Our admin will verify your payment again.", "success");
    } catch (Throwable $ex) {
        $conn->rollback();
        @unlink($up["abs"]);
        if (!($ex instanceof RuntimeException)) error_log("my_bookings.php: " . $ex->getMessage());
        bk_flash(($ex instanceof RuntimeException) ? $ex->getMessage() : "Could not upload your receipt. Please try again.", "error");
    }
}

// ---------- 5. FETCH THIS CUSTOMER'S BOOKINGS ----------
$s = $conn->prepare("SELECT r.id, r.check_in, r.check_out, r.guests, r.total_amount, r.status AS res_status,
        a.name AS acc_name,
        (SELECT GROUP_CONCAT(ad.name ORDER BY ad.name SEPARATOR ', ')
            FROM reservation_addons ra JOIN addons ad ON ad.id = ra.addon_id
            WHERE ra.reservation_id = r.id) AS addon_names,
        p.id AS pay_id, p.status AS pay_status, p.amount AS pay_amount, p.down_payment, p.admin_remarks
    FROM reservations r
    JOIN accommodations a ON a.id = r.accommodation_id
    LEFT JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
    WHERE r.user_id = ?
    ORDER BY r.id DESC");
$s->bind_param("i", $user_id);
$s->execute();
$bookings = $s->get_result();

// Fetch resort info for contact & refund guidance
$resort_name = "Pergola Resort";
$resort_info = [
    "resort_name" => "Pergola Resort",
    "contact_number" => "",
    "email" => "",
    "location" => "",
    "facebook_page" => "",
    "social_links" => ""
];
$ri = $conn->query("SELECT resort_name, contact_number, email, location, facebook_page, social_links FROM resort_info LIMIT 1");
if ($ri && $ri->num_rows > 0) {
    $resort_info = $ri->fetch_assoc();
    $resort_name = $resort_info["resort_name"] ?? "Pergola Resort";
}
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="../css/customer.css">
    <link rel="stylesheet" href="../css/ui.css">
    <style>
        <?= pay_badge_css() ?>

        /* HIDE NOTIFICATION BELLS & EXTRA ICONS */
        .nf-wrap, .nf-bell, .notification-bell, .nav-links i, .nav-links svg {
            display: none !important;
        }

        /* HIDE ICONS OR CHECKMARKS INSIDE BADGES */
        .badge svg, .pbadge svg, .badge i, .pbadge i {
            display: none !important;
        }

        /* Sticky footer setup */
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
        }
        body > main { flex: 1 0 auto; width: 100%; box-sizing: border-box; }
        body > .footer { margin-top: auto; flex-shrink: 0; }

        /* Alerts */
        .alert {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 14px 18px;
            margin-bottom: 22px;
            border-radius: 10px;
            border: 1px solid transparent;
            border-left-width: 4px;
            font-size: 14px;
            line-height: 1.5;
        }
        .alert-success { background: #eef6ea; color: #2e6b2e; border-color: #cfe3c6; border-left-color: #3c8a3c; }
        .alert-error   { background: #fdf0f0; color: #a02a35; border-color: #f0c4c4; border-left-color: #c0392b; }

        /* Booking card */
        .bk {
            padding: 0;
            margin-bottom: 24px;
            overflow: hidden;
            scroll-margin-top: 90px;
            border: 1px solid #ede8dc;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .bk:hover {
            box-shadow: 0 6px 24px rgba(52, 74, 48, 0.08);
        }
        .bk-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 18px 26px;
            background: #faf9f6;
            border-bottom: 1px solid #ede8dc;
        }
        .bk-head h3 { margin: 0; font-size: 18px; color: #344a30; font-weight: 700; }
        .bk-body { padding: 24px 26px; }

        .bk-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
        }
        .bk-col h4 {
            margin: 0 0 12px;
            padding-bottom: 8px;
            font-size: 12.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #526b49;
            text-transform: uppercase;
            border-bottom: 1px solid #ede8dc;
        }
        .bk-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 16px;
            padding: 7px 0;
            font-size: 14px;
        }
        .bk-row span { color: #777; }
        .bk-row strong { text-align: right; color: #344a30; }
        .bk-row.total {
            margin-top: 6px;
            padding-top: 12px;
            border-top: 1px dashed #ede8dc;
        }
        .bk-row.total strong { font-size: 16px; color: #344a30; }

        /* UNIFORM PILL-SHAPED STATUS BADGES */
        .badge, .pbadge {
            border-radius: 20px !important;
            padding: 5px 12px !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            letter-spacing: 0.3px;
            display: inline-block;
            text-transform: uppercase;
        }

        /* Payment panel */
        .bk-pay {
            margin-top: 22px;
            padding: 16px 18px;
            border-radius: 10px;
            background: #f9f8f5;
            border: 1px solid #ede8dc;
            font-size: 14px;
            line-height: 1.6;
        }
        .bk-pay-title { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-weight: 600; }
        .bk-pay.is-paid   { background: #eef6ea; border-color: #cfe3c6; }
        .bk-pay.is-reject { background: #fdf0f0; border-color: #f0c4c4; }
        .bk-pay .verified { color: #2e6b2e; font-weight: 700; }
        .bk-pay .failed   { color: #a02a35; font-weight: 700; }

        .bk-upload { margin-top: 14px; padding-top: 14px; border-top: 1px solid #f0c4c4; }
        .bk-upload input[type=file] {
            width: 100%;
            box-sizing: border-box;
            padding: 10px;
            background: #fff;
            border: 1px dashed #c9b8b8;
            border-radius: 8px;
            font-size: 13px;
        }
        .bk-hint { margin: 8px 0 12px; font-size: 12px; color: #777; }

        /* Refund notice box for cancelled bookings */
        .refund-notice {
            margin-top: 22px;
            padding: 18px 20px;
            border-radius: 10px;
            background: #fdf8f0;
            border: 1px solid #ebd9c5;
            font-size: 14px;
            line-height: 1.6;
            color: #5c4a32;
        }
        .refund-notice h4 {
            margin: 0 0 8px 0;
            font-size: 15px;
            color: #7a5c36;
            font-weight: 700;
        }
        .refund-notice ul {
            margin: 8px 0 0 20px;
            padding: 0;
        }
        .refund-notice li {
            margin-bottom: 4px;
        }

        /* Footer actions */
        .bk-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #ede8dc;
        }
        .btn-cancel {
            padding: 10px 22px;
            background: #fff;
            color: #c0392b;
            border: 1.5px solid #c0392b;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-cancel:hover { background: #c0392b; color: #fff; transform: translateY(-1px); }
        .bk-note { font-size: 12px; color: #777; }

        /* Section Header */
        .section-title {
            border-bottom: 1px solid #ede8dc;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        /* Empty state */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 56px 20px 60px;
        }
        .empty-state .empty-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ea;
            color: #344a30;
            margin-bottom: 18px;
        }
        .empty-state h4 { margin: 0 0 6px; font-size: 20px; color: #344a30; font-weight: 700; }
        .empty-state p { margin: 0 0 24px; max-width: 380px; font-size: 14px; line-height: 1.6; color: #666; }
        .browse-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 13px 28px;
            background: #6f8761;
            border: 1px solid #526b49;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(52, 74, 48, 0.15);
            transition: all 0.2s ease;
        }
        .browse-btn:hover {
            background: #526b49;
            box-shadow: 0 6px 16px rgba(52, 74, 48, 0.25);
            transform: translateY(-1px);
        }

        @media (max-width: 640px) {
            .bk-cols { grid-template-columns: 1fr; gap: 20px; }
            .bk-head, .bk-body { padding-left: 18px; padding-right: 18px; }
            .browse-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body>

<header class="navbar">
    <div class="nav-container">
        <a href="home.php" class="nav-brand"><h2><?= e($resort_name) ?></h2></a>
        <nav class="nav-links">
            <a href="home.php">Home</a>
            <a href="../accommodations.php">Accommodations</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="my_bookings.php" class="active">My Bookings</a>
            <a href="profile.php">Profile</a>
            <a href="../logout.php" class="nav-btn-outline">Sign Out</a>
        </nav>
    </div>
</header>

<main class="container" style="padding:40px 20px; max-width:900px;">
    <div class="section-title">
        <h1>My Bookings</h1>
        <p>Track your reservations and payment verification status.</p>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert <?= $message_type === 'success' ? 'alert-success' : 'alert-error' ?>" role="alert">
            <?= e($message) ?>
        </div>
    <?php elseif (isset($_GET["success"])): ?>
        <div class="alert alert-success" role="alert">Booking submitted successfully.</div>
    <?php endif; ?>

    <?php if ($bookings->num_rows === 0): ?>
        <div class="card" style="border: 1px solid #ede8dc; border-radius: 12px;">
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">
                    <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="17" rx="2"></rect>
                        <path d="M16 2v4M8 2v4M3 10h18"></path>
                    </svg>
                </div>
                <h4>No bookings yet</h4>
                <p>You have not made any reservations. Reserve a stay and it will appear here.</p>
                <a href="../accommodations.php" class="browse-btn">
                    <span>Browse Accommodations Now</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
                </a>
            </div>
        </div>
    <?php endif; ?>

    <?php while ($b = $bookings->fetch_assoc()):
        $total = (float)$b["total_amount"];
        $pay_status = (string)($b["pay_status"] ?? "unpaid");
        $submitted = (float)($b["pay_amount"] ?? 0);
        $required = (float)($b["down_payment"] ?? $b["pay_amount"] ?? 0);
        $paid = $pay_status === "paid" ? $submitted : 0.0;
        $remaining = $b["pay_id"] ? pay_remaining($total, $submitted, $pay_status) : $total;
        $addon_names = trim((string)($b["addon_names"] ?? ""));
        
        // Map badges to uniform color classes
        $resBadgeClass = 'b-pending';
        if ($b["res_status"] === 'confirmed') $resBadgeClass = 'b-confirmed';
        elseif ($b["res_status"] === 'completed') $resBadgeClass = 'b-completed';
        elseif ($b["res_status"] === 'cancelled') $resBadgeClass = 'b-cancelled';

        $payBadgeClass = 'b-pending';
        if ($pay_status === 'paid' || $pay_status === 'verified') $payBadgeClass = 'b-confirmed';
        elseif ($pay_status === 'rejected' || $pay_status === 'failed') $payBadgeClass = 'b-cancelled';

        [$ptext, $pcls] = pay_status_info($pay_status);
        [$rtext, $rcls] = res_status_info((string)$b["res_status"]);

        // Simplify lengthy verification statuses to "Pending"
        if (stripos($rtext, 'verification') !== false || stripos($rtext, 'pending') !== false) {
            $rtext = 'Pending';
        }
        if (stripos($ptext, 'verification') !== false || stripos($ptext, 'pending') !== false) {
            $ptext = 'Pending';
        }

        $pay_class = $pay_status === "rejected" ? "is-reject" : ($pay_status === "paid" ? "is-paid" : "");
    ?>
    <div class="card bk" id="res-<?= (int)$b["id"] ?>">
        <div class="bk-head">
            <h3>Reservation #<?= (int)$b["id"] ?></h3>
            <span class="badge <?= $resBadgeClass ?>"><?= e($rtext) ?></span>
        </div>

        <div class="bk-body">
            <div class="bk-cols">
                <!-- Stay details -->
                <div class="bk-col">
                    <h4>Stay Details</h4>
                    <div class="bk-row"><span>Accommodation</span><strong><?= e($b["acc_name"]) ?></strong></div>
                    <div class="bk-row"><span>Check-in</span><strong><?= e(pay_fmt_dt($b["check_in"], "in", "F d, Y")) ?></strong></div>
                    <div class="bk-row"><span>Check-out</span><strong><?= e(pay_fmt_dt($b["check_out"], "out", "F d, Y")) ?></strong></div>
                    <div class="bk-row"><span>Guests</span><strong><?= (int)$b["guests"] ?></strong></div>
                    <div class="bk-row"><span>Add-ons</span><strong><?= $addon_names !== "" ? e($addon_names) : "None" ?></strong></div>
                </div>

                <!-- Payment summary -->
                <div class="bk-col">
                    <h4>Payment Summary</h4>
                    <div class="bk-row"><span>Total</span><strong><?= peso($total) ?></strong></div>
                    <div class="bk-row"><span>Downpayment (required)</span><strong><?= peso($required) ?></strong></div>
                    <div class="bk-row"><span>Amount paid (verified)</span><strong><?= peso($paid) ?></strong></div>
                    <div class="bk-row total"><span>Remaining balance</span><strong><?= peso($remaining) ?></strong></div>
                </div>
            </div>

            <?php if ($b["pay_id"]): ?>
            <div class="bk-pay <?= e($pay_class) ?>">
                <div class="bk-pay-title">Payment status <span class="badge <?= $payBadgeClass ?>"><?= e($ptext) ?></span></div>

                <?php if ($pay_status === "paid"): ?>
                    <span class="verified">Payment verified.</span> Your downpayment has been received.
                <?php elseif ($pay_status === "pending"): ?>
                    Your receipt was submitted. Our admin is verifying your payment.
                <?php elseif ($pay_status === "rejected"): ?>
                    <span class="failed">Payment verification failed.</span><br>
                    Reason: <?= e($b["admin_remarks"] ?: "No reason provided.") ?>
                    <?php if ($b["res_status"] !== "cancelled"): ?>
                    <form method="POST" action="my_bookings.php" enctype="multipart/form-data" class="bk-upload">
                        <div style="margin-bottom:8px;">Please upload a valid payment receipt.</div>
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="reupload_receipt">
                        <input type="hidden" name="payment_id" value="<?= (int)$b["pay_id"] ?>">
                        <input type="file" name="receipt_file" required accept="image/jpeg,image/png,image/webp,application/pdf">
                        <div class="bk-hint">Accepted: JPG, PNG, WEBP, PDF. Max 5MB.</div>
                        <button type="submit" class="btn primary-btn" style="background: #6f8761; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer;">Upload New Receipt</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Refund / Downpayment Settlement Info for Cancelled Bookings with Payments -->
            <?php if ($b["res_status"] === 'cancelled' && in_array($pay_status, ["paid", "pending"], true)): ?>
            <div class="refund-notice">
                <h4>Downpayment Refund & Settlement Info</h4>
                <p>Your reservation has been cancelled. If you have already sent a downpayment, please reach out directly to <strong><?= e($resort_info["resort_name"]) ?></strong> to process your refund or settlement:</p>
                <ul>
                    <?php if (!empty($resort_info["contact_number"])): ?>
                        <li><strong>Contact Number:</strong> <?= e($resort_info["contact_number"]) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($resort_info["email"])): ?>
                        <li><strong>Email Address:</strong> <?= e($resort_info["email"]) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($resort_info["facebook_page"])): ?>
                        <li><strong>Facebook Page:</strong> <a href="<?= e($resort_info["facebook_page"]) ?>" target="_blank" rel="noopener"><?= e($resort_info["facebook_page"]) ?></a></li>
                    <?php endif; ?>
                    <?php if (!empty($resort_info["social_links"])): ?>
                        <li><strong>Other Socials:</strong> <?= e($resort_info["social_links"]) ?></li>
                    <?php endif; ?>
                    <?php if (!empty($resort_info["location"])): ?>
                        <li><strong>Resort Location:</strong> <?= e($resort_info["location"]) ?></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php if (res_can_cancel((string)$b["res_status"], (string)$b["check_in"])): ?>
            <form method="POST" action="my_bookings.php" class="bk-actions" onsubmit="return confirm('Cancel reservation #<?= (int)$b["id"] ?>? This cannot be undone.');">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="cancel_reservation">
                <input type="hidden" name="reservation_id" value="<?= (int)$b["id"] ?>">
                <button type="submit" class="btn-cancel">Cancel Reservation</button>
                <?php if (in_array($pay_status, ["paid", "pending"], true)): ?>
                    <span class="bk-note">Already sent a downpayment? Please contact the resort about your payment.</span>
                <?php endif; ?>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endwhile; ?>
</main>

<footer class="footer">
    <div class="container"><p>&copy; <?= date("Y") ?> <?= e($resort_name) ?>. All rights reserved.</p></div>
</footer>
<script src="../js/app.js" data-base="../" data-role="customer"></script>
<script src="../js/responsive.js"></script>
</body>
</html>