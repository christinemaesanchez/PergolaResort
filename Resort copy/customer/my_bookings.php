<?php
// ---------- 1. CONNECTION + SESSION ----------
require_once "../config.php";
require_once "../payment_lib.php";

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

        // Ownership check: the reservation must belong to this user
        $q = $conn->prepare("SELECT id, status, check_in FROM reservations WHERE id = ? AND user_id = ? FOR UPDATE");
        $q->bind_param("ii", $cancel_id, $user_id);
        $q->execute();
        $res = $q->get_result()->fetch_assoc();
        $q->close();

        if (!$res) throw new RuntimeException("Reservation not found.");
        if (!res_can_cancel((string)$res["status"], (string)$res["check_in"])) {
            throw new RuntimeException("This reservation can no longer be cancelled.");
        }

        $c = $conn->prepare("UPDATE reservations SET status = 'cancelled' WHERE id = ? AND user_id = ?");
        $c->bind_param("ii", $cancel_id, $user_id);
        $c->execute();
        $c->close();

        $conn->commit();
        bk_flash("Reservation #" . $cancel_id . " has been cancelled.", "success");
    } catch (Throwable $ex) {
        $conn->rollback();
        if (!($ex instanceof RuntimeException)) error_log("my_bookings.php: " . $ex->getMessage());
        bk_flash(($ex instanceof RuntimeException) ? $ex->getMessage() : "Could not cancel your reservation. Please try again.", "error");
    }
}

// ---------- 4. UPLOAD A NEW RECEIPT (only for the customer's OWN rejected payment) ----------
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

        // Ownership check: payment -> reservation -> this user (and the reservation is not cancelled)
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

$resort_name = "Pergola Resort";
$ri = $conn->query("SELECT resort_name FROM resort_info LIMIT 1");
if ($ri && $ri->num_rows > 0) $resort_name = $ri->fetch_assoc()["resort_name"];
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Bookings | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="../css/customer.css">
    <style>
        <?= pay_badge_css() ?>

        /* ---------- Sticky footer: footer always sits at the bottom ---------- */
        body {
            margin: 0;
            min-height: 100vh;
            min-height: 100dvh;
            display: flex;
            flex-direction: column;
        }
        body > main { flex: 1 0 auto; width: 100%; box-sizing: border-box; }
        body > .footer { margin-top: auto; flex-shrink: 0; }

        /* ---------- Alerts ---------- */
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

        /* ---------- Booking card ---------- */
        .bk {
            padding: 0;
            margin-bottom: 24px;
            overflow: hidden;
            scroll-margin-top: 90px;
        }
        .bk-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            padding: 18px 26px;
            background: #faf9f6;
            border-bottom: 1px solid var(--border-color);
        }
        .bk-head h3 { margin: 0; font-size: 18px; color: var(--primary-color); }
        .bk-body { padding: 24px 26px; }

        .bk-cols {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 28px;
        }
        .bk-col h4 {
            margin: 0 0 12px;
            padding-bottom: 8px;
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.4px;
            color: var(--primary-color);
            border-bottom: 1px solid var(--border-color);
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
        .bk-row strong { text-align: right; color: #2b2b2b; }
        .bk-row.total {
            margin-top: 6px;
            padding-top: 12px;
            border-top: 1px dashed var(--border-color);
        }
        .bk-row.total strong { font-size: 16px; color: var(--primary-color); }

        /* ---------- Payment panel ---------- */
        .bk-pay {
            margin-top: 22px;
            padding: 16px 18px;
            border-radius: 10px;
            background: #f9f8f5;
            border: 1px solid var(--border-color);
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

        /* ---------- Footer actions ---------- */
        .bk-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 14px;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--border-color);
        }
        .btn-cancel {
            padding: 10px 22px;
            background: #fff;
            color: #c0392b;
            border: 1.5px solid #c0392b;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s ease, color 0.2s ease;
        }
        .btn-cancel:hover { background: #c0392b; color: #fff; }
        .btn-cancel:focus-visible { outline: 3px solid rgba(192, 57, 43, 0.3); outline-offset: 2px; }
        .bk-note { font-size: 12px; color: #777; }

        /* ---------- Empty state ---------- */
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
            color: var(--primary-color);
            margin-bottom: 18px;
        }
        .empty-state h4 { margin: 0 0 6px; font-size: 20px; color: var(--primary-color); }
        .empty-state p { margin: 0 0 24px; max-width: 380px; font-size: 14px; line-height: 1.6; color: #666; }
        .browse-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 13px 28px;
            background: var(--primary-color);
            border: 1px solid var(--primary-color);
            border-radius: 10px;
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            letter-spacing: 0.3px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(58, 74, 48, 0.25);
            transition: transform 0.2s ease, box-shadow 0.2s ease, filter 0.2s ease;
        }
        .browse-btn svg { transition: transform 0.2s ease; }
        .browse-btn:hover { filter: brightness(0.92); box-shadow: 0 6px 16px rgba(58, 74, 48, 0.3); }
        .browse-btn:hover svg { transform: translateX(4px); }
        .browse-btn:active { transform: translateY(1px); }
        .browse-btn:focus-visible { outline: 3px solid rgba(58, 74, 48, 0.35); outline-offset: 3px; }

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
        <a href="../index.php" class="nav-brand"><h2><?= e($resort_name) ?></h2></a>
        <nav class="nav-links">
            <a href="../index.php">Home</a>
            <a href="../accommodations.php">Accommodations</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="my_bookings.php" class="active">My Bookings</a>
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
        <div class="card">
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
        [$ptext, $pcls] = pay_status_info($pay_status);
        [$rtext, $rcls] = res_status_info((string)$b["res_status"]);
        $pay_class = $pay_status === "rejected" ? "is-reject" : ($pay_status === "paid" ? "is-paid" : "");
    ?>
    <div class="card bk" id="res-<?= (int)$b["id"] ?>">
        <div class="bk-head">
            <h3>Reservation #<?= (int)$b["id"] ?></h3>
            <span class="pbadge <?= e($rcls) ?>"><?= e($rtext) ?></span>
        </div>

        <div class="bk-body">
            <div class="bk-cols">
                <!-- Stay details -->
                <div class="bk-col">
                    <h4>Stay Details</h4>
                    <div class="bk-row"><span>Accommodation</span><strong><?= e($b["acc_name"]) ?></strong></div>
                    <div class="bk-row"><span>Check-in</span><strong><?= e(date("F d, Y", strtotime($b["check_in"]))) ?></strong></div>
                    <div class="bk-row"><span>Check-out</span><strong><?= e(date("F d, Y", strtotime($b["check_out"]))) ?></strong></div>
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
                <div class="bk-pay-title">Payment status <span class="pbadge <?= e($pcls) ?>"><?= e($ptext) ?></span></div>

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
                        <button type="submit" class="btn primary-btn">Upload New Receipt</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
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
</body>
</html>