<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";   // auth_guard(), notifications, shared helpers
$auth_user = auth_guard($conn, "admin", "../login.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =========================================================================
// 2. ADMIN AUTHENTICATION & SECURITY
// =========================================================================
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit();
}

$stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'admin' AND status = 'active'");
$stmt->bind_param("i", $_SESSION["user_id"]);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin) {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$admin_id = (int)$_SESSION["user_id"];

ensure_payment_schema($conn);

// Short, emoji-free status labels + badge colours used across the whole system
if (!function_exists('ui_status')) {
    function ui_status(string $s): array {   // returns [label, badge class]
        switch (strtolower(trim($s))) {
            case 'confirmed': case 'approved':          return ['Confirmed', 'b-confirmed'];
            case 'paid': case 'verified':               return ['Paid', 'b-confirmed'];
            case 'completed':                           return ['Completed', 'b-completed'];
            case 'cancelled': case 'canceled':          return ['Cancelled', 'b-cancelled'];
            case 'rejected': case 'failed':
            case 'payment_verification_failed':         return ['Rejected', 'b-cancelled'];
            case 'refunded':                            return ['Refunded', 'b-completed'];
            default:                                    return ['Pending', 'b-pending'];   // pending, pending_payment_verification, ...
        }
    }
}

$message = $_SESSION["flash_msg"] ?? "";
$message_type = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_msg"], $_SESSION["flash_type"]);

function page_flash(string $msg, string $type, string $qs = ""): void {
    $_SESSION["flash_msg"] = $msg;
    $_SESSION["flash_type"] = $type;
    header("Location: reservations.php" . $qs);
    exit();
}

// =========================================================================
// 3. HANDLE POST ACTIONS (UNIFIED BOOKING & PAYMENT ACTIONS)
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $back = "?" . http_build_query(array_filter([
        "tab" => $_POST["active_tab"] ?? "reservations",
        "status" => $_POST["f_status"] ?? "", 
        "search" => $_POST["f_search"] ?? "",
    ]));
    if ($back === "?") $back = "";

    if (!csrf_valid($_POST["csrf"] ?? null)) {
        page_flash("Your session expired. Please try again.", "error", $back);
    }

    /* ----- A. UNIFIED PAYMENT APPROVAL & AUTOMATIC BOOKING CONFIRMATION ----- */
    if ($action === "approve_payment") {
        $payment_id = (int)($_POST["payment_id"] ?? 0);

        try {
            $conn->begin_transaction();

            $q = $conn->prepare("SELECT p.id, p.reservation_id, p.status, r.status AS res_status, r.user_id
                                 FROM payments p JOIN reservations r ON r.id = p.reservation_id WHERE p.id = ? FOR UPDATE");
            $q->bind_param("i", $payment_id);
            $q->execute();
            $pay = $q->get_result()->fetch_assoc();
            $q->close();

            if (!$pay) throw new RuntimeException("Payment record not found.");
            $rid = (int)$pay["reservation_id"];

            if (in_array($pay["status"], ["paid", "refunded"], true)) {
                throw new RuntimeException("This payment is already marked as paid.");
            }

            $u = $conn->prepare("UPDATE payments SET status = 'paid', verified_by = ?, verified_date = NOW(), admin_remarks = NULL WHERE id = ?");
            $u->bind_param("ii", $admin_id, $payment_id);
            $u->execute();
            $u->close();

            $r = $conn->prepare("UPDATE reservations SET status = 'confirmed' WHERE id = ?");
            $r->bind_param("i", $rid);
            $r->execute();
            $r->close();

            notify_user($conn, (int)$pay["user_id"], "payment_approved", "Payment Verified & Confirmed",
                "Your payment for reservation #" . $rid . " was verified and your booking is now confirmed!",
                "customer/my_bookings.php?res=" . $rid, $rid, "pay_approved:" . $payment_id);

            $conn->commit();
            page_flash("Payment #" . $payment_id . " approved and Reservation #" . $rid . " successfully confirmed!", "success", $back);
        } catch (Throwable $ex) {
            $conn->rollback();
            page_flash($ex->getMessage(), "error", $back);
        }
    }

    /* ----- B. REJECT PAYMENT ----- */
    if ($action === "reject_payment") {
        $payment_id = (int)($_POST["payment_id"] ?? 0);
        $remarks = trim($_POST["admin_remarks"] ?? "");

        if (mb_strlen($remarks) < 3) {
            page_flash("Please enter a reason for rejection.", "error", $back);
        }

        try {
            $conn->begin_transaction();

            $q = $conn->prepare("SELECT p.id, p.reservation_id, p.status, r.user_id
                                 FROM payments p JOIN reservations r ON r.id = p.reservation_id WHERE p.id = ? FOR UPDATE");
            $q->bind_param("i", $payment_id);
            $q->execute();
            $pay = $q->get_result()->fetch_assoc();
            $q->close();

            if (!$pay) throw new RuntimeException("Payment record not found.");
            $rid = (int)$pay["reservation_id"];

            $u = $conn->prepare("UPDATE payments SET status = 'rejected', verified_by = ?, verified_date = NOW(), admin_remarks = ? WHERE id = ?");
            $u->bind_param("isi", $admin_id, $remarks, $payment_id);
            $u->execute();
            $u->close();

            $r = $conn->prepare("UPDATE reservations SET status = 'payment_verification_failed' WHERE id = ?");
            $r->bind_param("i", $rid);
            $r->execute();
            $r->close();

            notify_user($conn, (int)$pay["user_id"], "payment_rejected", "Payment Rejected",
                "Your payment for reservation #" . $rid . " was rejected. Reason: " . mb_substr($remarks, 0, 200) . " Please re-upload.",
                "customer/my_bookings.php?res=" . $rid, $rid, "pay_rejected:" . $payment_id);

            $conn->commit();
            page_flash("Payment #" . $payment_id . " rejected and customer notified.", "success", $back);
        } catch (Throwable $ex) {
            $conn->rollback();
            page_flash("Failed to reject payment.", "error", $back);
        }
    }

    /* ----- C. MANUAL RESERVATION STATUS UPDATE ----- */
    if ($action === "update_reservation_status") {
        $res_id = (int)($_POST["reservation_id"] ?? 0);
        $new_status = $_POST["status"] ?? "";
        $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'completed'];

        if (!in_array($new_status, $allowed_statuses, true)) {
            page_flash("Invalid reservation status.", "error", $back);
        }

        try {
            $cq = $conn->prepare("SELECT accommodation_id, check_in, check_out, status, user_id FROM reservations WHERE id = ? LIMIT 1");
            $cq->bind_param("i", $res_id);
            $cq->execute();
            $cur_res = $cq->get_result()->fetch_assoc();
            $cq->close();

            if (!$cur_res) page_flash("Reservation #" . $res_id . " not found.", "error", $back);

            // Nothing to do if the status is already the same
            if (ui_status((string)$cur_res["status"])[0] === ui_status($new_status)[0]
                && ($new_status !== 'pending' || in_array($cur_res["status"], ['pending', 'pending_payment_verification'], true))) {
                page_flash("Reservation #" . $res_id . " is already " . ui_status($new_status)[0] . ".", "error", $back);
            }

            $u = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            $u->bind_param("si", $new_status, $res_id);
            $u->execute();
            $u->close();

            // Let the customer know (a notification problem must never block the update)
            try {
                notify_user($conn, (int)$cur_res["user_id"], "reservation_status", "Booking " . ui_status($new_status)[0],
                    "Your reservation #" . $res_id . " is now " . strtolower(ui_status($new_status)[0]) . ".",
                    "customer/my_bookings.php?res=" . $res_id, $res_id, "res_status:" . $res_id . ":" . $new_status . ":" . time());
            } catch (Throwable $ex) { error_log("reservations.php notify: " . $ex->getMessage()); }

            page_flash("Reservation #" . $res_id . " status updated to " . ui_status($new_status)[0] . ".", "success", $back);
        } catch (Throwable $ex) {
            page_flash("Failed to update status.", "error", $back);
        }
    }

    /* ----- D. SAVE PAYMENT SETTINGS ----- */
    if ($action === "save_payment_settings") {
        $name = trim($_POST["gcash_name"] ?? "");
        $number = trim($_POST["gcash_number"] ?? "");
        $pct = filter_var($_POST["down_payment_percent"] ?? "", FILTER_VALIDATE_FLOAT);
        $instr = trim($_POST["instructions"] ?? "");

        if ($name === "" || $number === "" || $pct === false) {
            page_flash("Please fill in all required payment settings correctly.", "error", "?tab=settings");
        }

        $current_settings = pay_get_settings($conn);
        $qr_path = (string)($current_settings["gcash_qr"] ?? "");

        if (isset($_FILES["gcash_qr"]) && $_FILES["gcash_qr"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $up = pay_handle_upload($_FILES["gcash_qr"], PAY_IMAGE_MIMES, PERGOLA_ROOT . "/images/uploads/", "images/uploads/", "gcash_qr", "public");
            if ($up["ok"]) $qr_path = $up["path"];
        }

        $st = $conn->prepare("UPDATE payment_settings SET gcash_name = ?, gcash_number = ?, down_payment_percent = ?, instructions = ?, gcash_qr = ? WHERE id = 1");
        $st->bind_param("ssdss", $name, $number, $pct, $instr, $qr_path);
        $st->execute();
        $st->close();

        page_flash("Payment settings saved successfully.", "success", "?tab=settings");
    }
}

// =========================================================================
// 4. DATA QUERIES
// =========================================================================
$active_tab = $_GET["tab"] ?? "reservations";
$filter_options = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'completed' => 'Completed', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];
$raw_filter = (string)($_GET["status"] ?? "");
if ($raw_filter === 'pending_payment_verification') $raw_filter = 'pending';      // old links still work
if ($raw_filter === 'payment_verification_failed')  $raw_filter = 'rejected';
$res_status_filter = isset($filter_options[$raw_filter]) ? $raw_filter : "";
$search_query = trim($_GET["search"] ?? "");

$res_sql = "SELECT r.id, u.full_name, u.email, u.phone, a.name AS accommodation, r.check_in, r.check_out, r.guests,
                   r.total_amount, r.status, r.created_at,
                   p.id AS pay_id, p.status AS pay_status, p.amount AS pay_amount, p.down_payment,
                   p.receipt_image, p.admin_remarks, p.method AS pay_method
            FROM reservations r
            JOIN users u ON u.id = r.user_id
            JOIN accommodations a ON a.id = r.accommodation_id
            LEFT JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
            WHERE 1=1";

$res_params = [];
$res_types = "";
if ($res_status_filter === "pending") {
    $res_sql .= " AND r.status IN ('pending', 'pending_payment_verification')";
} elseif ($res_status_filter === "rejected") {
    $res_sql .= " AND r.status = 'payment_verification_failed'";
} elseif ($res_status_filter !== "") {
    $res_sql .= " AND r.status = ?";
    $res_params[] = $res_status_filter;
    $res_types .= "s";
}
if ($search_query !== "") {
    $res_sql .= " AND (u.full_name LIKE ? OR r.id = ? OR a.name LIKE ?)";
    $like = "%" . $search_query . "%";
    $sid = (int)$search_query;
    array_push($res_params, $like, $sid, $like);
    $res_types .= "sis";
}
$res_sql .= " ORDER BY r.check_in DESC";
$stmt = $conn->prepare($res_sql);
if ($res_params) $stmt->bind_param($res_types, ...$res_params);
$stmt->execute();
$reservations = $stmt->get_result();

$settings = pay_get_settings($conn);
$csrf = csrf_token();
$current = "reservations";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations & Payments | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/ui.css">
    <style>
        <?= pay_badge_css() ?>
        
        /* FORCE HIDE ALL ICONS, SB-TOGGLE, AND NOTIFICATION BELLS */
        .sidebar-nav a i, .sidebar-nav a svg, .sidebar-nav span i,
        .sb-toggle, .sidebar-collapse-btn, .ui-ico,
        .nf-wrap, .nf-admin, .topbar-bell, .notification-bell, .topbar-icons { 
            display: none !important; 
        }

        .pay-cell { font-size: 12.5px; line-height: 1.6; min-width: 170px; }
        .pay-cell .badge { margin-bottom: 4px; display: inline-block; }
        
        .page-tabs { display: flex; gap: 10px; margin-bottom: 25px; border-bottom: 1px solid #e3dec9; padding-bottom: 15px; }
        .page-tab-btn { padding: 10px 20px; font-weight: 600; text-decoration: none; border-radius: 8px; color: #555; background: #f0ede4; transition: all 0.2s; font-size: 13.5px; }
        .page-tab-btn.active { background: #6f8761; color: #fff; }
        
        /* Neat action column */
        .action-stack { display: flex; flex-direction: column; gap: 8px; width: 210px; }
        .action-row { display: flex; gap: 6px; }
        .action-row .action-btn { flex: 1; }
        .status-form { display: flex; gap: 6px; margin: 0; }
        .status-form .table-action-select { flex: 1; min-width: 0; }

        .action-btn {
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.2;
            border-radius: 6px;
            text-align: center;
            border: 1px solid transparent;
            cursor: pointer;
            white-space: nowrap;
            transition: background-color 0.2s, opacity 0.2s;
        }
        .action-btn.approve { background: #6f8761; border-color: #526b49; color: #fff; width: 100%; }
        .action-btn.approve:hover { background: #526b49; }
        .action-btn.reject { background: #fff; color: #9c2e25; border-color: #e3b3ad; }
        .action-btn.reject:hover { background: #fbeae8; }
        .action-btn.update { background: #344a30; color: #fff; }
        .action-btn.update:hover:not(:disabled) { background: #283a25; }
        .action-btn.update:disabled { opacity: 0.4; cursor: not-allowed; }

        .table-action-select {
            padding: 8px 10px;
            font-size: 12px;
            border-radius: 6px;
            border: 1px solid #d6cfba;
            background: #fff;
            color: #344a30;
            cursor: pointer;
            outline: none;
            font-weight: 600;
        }
        .table-action-select:hover, .table-action-select:focus { border-color: #6f8761; }

        .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal-bg.open { display: flex; }
        .modal { background: #fff; border-radius: 12px; padding: 25px; max-width: 440px; width: 100%; box-shadow: 0 4px 20px rgba(52, 74, 48, 0.1); }
        .modal textarea { width: 100%; padding: 11px 14px; border: 1px solid #e3dec9; border-radius: 8px; margin-top: 8px; background: #f7f5ee; color: #344a30; outline: none; }
        .modal textarea:focus { border-color: #6f8761; background: white; }
    </style>
    <script>
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
            <a href="reservations.php" class="active">Reservations</a>
            <a href="calendar.php">Calendar</a>
            <a href="accommodations.php">Accommodations</a>
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
                <span class="section-label">OPERATIONS & FINANCE</span>
                <h1>Reservations & Payments Management</h1>
                <p>Manage guest bookings and verify payment receipts seamlessly in one place.</p>
            </div>
            <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
        </header>

        <!-- Flash Messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <!-- Page Tabs -->
        <div class="page-tabs">
            <a href="reservations.php?tab=reservations" class="page-tab-btn <?= $active_tab !== 'settings' ? 'active' : '' ?>">Bookings & Payments</a>
            <a href="reservations.php?tab=settings" class="page-tab-btn <?= $active_tab === 'settings' ? 'active' : '' ?>">Payment Settings</a>
        </div>

        <!-- ================= TAB 1: BOOKINGS & PAYMENTS ================= -->
        <?php if ($active_tab !== 'settings'): ?>
            <section class="card filter-card">
                <form method="GET" action="reservations.php" class="filter-form">
                    <input type="hidden" name="tab" value="reservations">
                    <div class="form-group-inline">
                        <label for="status">Filter Status</label>
                        <select name="status" id="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($filter_options as $val => $lbl): ?>
                                <option value="<?= e($val) ?>" <?= $res_status_filter === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group-inline search-group">
                        <label for="search">Search Guest or ID</label>
                        <input type="text" name="search" id="search" placeholder="Enter guest name or ID..." value="<?= e($search_query) ?>">
                    </div>
                    <div class="form-group-inline btn-group">
                        <button type="submit" class="admin-btn primary">Apply Filters</button>
                        <a href="reservations.php?tab=reservations" class="admin-btn secondary">Reset</a>
                    </div>
                </form>
            </section>

            <section class="card content-card full-width-card">
                <div class="card-header-flex">
                    <h3>Bookings & Payment Verification</h3>
                    <span class="record-count"><?= $reservations->num_rows ?> records found</span>
                </div>
                <div class="table-wrap">
                <?php if ($reservations->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Guest Details</th>
                                <th>Accommodation</th>
                                <th>Check-in / Out</th>
                                <th>Total & Payment</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($r = $reservations->fetch_assoc()):
                                $total = (float)$r["total_amount"];
                                [$rtext, $resBadgeClass] = ui_status((string)$r["status"]);
                                $ptext = ""; $payBadgeClass = "b-pending";
                                if ($r["pay_id"]) {
                                    [$ptext, $payBadgeClass] = ui_status((string)$r["pay_status"]);
                                }
                                $can_review = ($r["pay_id"] && $r["pay_status"] === 'pending');
                                $cur_sel = in_array($r["status"], ['confirmed', 'completed', 'cancelled'], true) ? $r["status"] : 'pending';
                            ?>
                            <tr>
                                <td>#<?= (int)$r["id"] ?></td>
                                <td>
                                    <strong><?= e($r["full_name"]) ?></strong><br>
                                    <span class="sub-text"><?= e($r["email"]) ?></span>
                                    <?php if (trim((string)$r["phone"]) !== ""): ?><br><span class="sub-text"><?= e($r["phone"]) ?></span><?php endif; ?>
                                </td>
                                <td><?= e($r["accommodation"]) ?></td>
                                <td>
                                    <span class="date-block">In: <?= e(date("M d, Y", strtotime($r["check_in"]))) ?></span>
                                    <span class="date-block">Out: <?= e(date("M d, Y", strtotime($r["check_out"]))) ?></span>
                                </td>
                                <td>
                                    <?php if ($r["pay_id"]): ?>
                                        <div class="pay-cell">
                                            <strong><?= peso($total) ?></strong><br>
                                            <div style="display: flex; align-items: center; gap: 8px; margin: 4px 0; white-space: nowrap;">
                                                <strong style="color: #344a30; font-weight: 700;"><?= e(strtoupper((string)($r["pay_method"] ?? 'GCASH'))) ?></strong>
                                                <span class="badge <?= $payBadgeClass ?>"><?= e($ptext) ?></span>
                                            </div>
                                            <?php if (!empty($r["receipt_image"])): ?>
                                                <a href="receipt.php?id=<?= (int)$r["pay_id"] ?>" target="_blank" rel="noopener">View Receipt</a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <strong><?= peso($total) ?></strong><br>
                                        <span class="sub-text">No payment submitted</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="badge <?= $resBadgeClass ?>"><?= e($rtext) ?></span></td>
                                <td>
                                    <div class="action-stack">
                                        <?php if ($can_review): ?>
                                            <div class="action-row">
                                                <form method="POST" action="reservations.php" style="margin: 0; flex: 1;">
                                                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                    <input type="hidden" name="action" value="approve_payment">
                                                    <input type="hidden" name="payment_id" value="<?= (int)$r["pay_id"] ?>">
                                                    <input type="hidden" name="active_tab" value="reservations">
                                                    <input type="hidden" name="f_status" value="<?= e($res_status_filter) ?>">
                                                    <input type="hidden" name="f_search" value="<?= e($search_query) ?>">
                                                    <button type="submit" class="action-btn approve" onclick="return confirm('Approve payment and confirm booking #<?= (int)$r["id"] ?>?');">Approve</button>
                                                </form>
                                                <button type="button" class="action-btn reject" onclick="openRejectModal(<?= (int)$r["pay_id"] ?>)">Reject</button>
                                            </div>
                                        <?php endif; ?>

                                        <form method="POST" action="reservations.php" class="status-form" data-id="<?= (int)$r["id"] ?>" data-current="<?= e($cur_sel) ?>">
                                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="update_reservation_status">
                                            <input type="hidden" name="reservation_id" value="<?= (int)$r["id"] ?>">
                                            <input type="hidden" name="active_tab" value="reservations">
                                            <input type="hidden" name="f_status" value="<?= e($res_status_filter) ?>">
                                            <input type="hidden" name="f_search" value="<?= e($search_query) ?>">
                                            <select name="status" class="table-action-select" aria-label="Booking status">
                                                <option value="pending"   <?= $cur_sel === 'pending'   ? 'selected' : '' ?>>Pending</option>
                                                <option value="confirmed" <?= $cur_sel === 'confirmed' ? 'selected' : '' ?>>Confirmed</option>
                                                <option value="completed" <?= $cur_sel === 'completed' ? 'selected' : '' ?>>Completed</option>
                                                <option value="cancelled" <?= $cur_sel === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                                            </select>
                                            <button type="submit" class="action-btn update" disabled>Update</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="empty">No reservations match your criteria.</p>
                <?php endif; ?>
                </div>
            </section>

        <!-- ================= TAB 2: PAYMENT SETTINGS ================= -->
        <?php else: ?>
            <section class="card content-card pay-settings">
                <h3 style="margin-bottom: 20px;">Payment & GCash Configuration</h3>
                <form method="POST" action="reservations.php" enctype="multipart/form-data">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="action" value="save_payment_settings">
                    <input type="hidden" name="active_tab" value="settings">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label for="gcash_name">GCash Account Name</label>
                            <input type="text" id="gcash_name" name="gcash_name" required maxlength="100" value="<?= e($settings["gcash_name"]) ?>">
                        </div>
                        <div class="form-group">
                            <label for="gcash_number">GCash Number</label>
                            <input type="text" id="gcash_number" name="gcash_number" required maxlength="20" value="<?= e($settings["gcash_number"]) ?>">
                        </div>
                        <div class="form-group">
                            <label for="down_payment_percent">Down Payment Percent (%)</label>
                            <input type="number" id="down_payment_percent" name="down_payment_percent" min="1" max="100" step="0.5" required value="<?= e($settings["down_payment_percent"]) ?>">
                        </div>
                        <div class="form-group">
                            <label for="gcash_qr">GCash QR Code Image</label>
                            <?php if (!empty($settings["gcash_qr"])): ?>
                                <div style="margin-bottom: 8px;"><img src="../<?= e($settings["gcash_qr"]) ?>" alt="QR" class="table-thumb" style="width: 80px; height: 80px;"></div>
                            <?php endif; ?>
                            <input type="file" id="gcash_qr" name="gcash_qr" accept="image/*" class="file-input">
                        </div>
                    </div>
                    <div class="form-group" style="margin-top: 20px;">
                        <label for="instructions">Payment Instructions</label>
                        <textarea id="instructions" name="instructions" rows="4"><?= e($settings["instructions"]) ?></textarea>
                    </div>
                    <div style="margin-top: 20px;">
                        <button type="submit" class="admin-btn primary">Save Payment Settings</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

    </main>

    <!-- Reject Payment Modal -->
    <div id="rejectModal" class="modal-bg">
        <div class="modal">
            <h3 style="margin-bottom: 15px; color: #344a30;">Reject Payment Receipt</h3>
            <form method="POST" action="reservations.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="payment_id" id="reject_payment_id">
                <input type="hidden" name="active_tab" value="reservations">
                <input type="hidden" name="f_status" value="<?= e($res_status_filter) ?>">
                <input type="hidden" name="f_search" value="<?= e($search_query) ?>">
                <div class="form-group">
                    <label for="admin_remarks">Reason for Rejection (Required)</label>
                    <textarea name="admin_remarks" id="admin_remarks" rows="3" required placeholder="E.g., Receipt is unclear or incorrect amount..."></textarea>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="submit" class="admin-btn primary" style="background: #9c2e25;">Confirm Rejection</button>
                    <button type="button" class="admin-btn secondary" onclick="closeRejectModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRejectModal(paymentId) {
            document.getElementById('reject_payment_id').value = paymentId;
            document.getElementById('rejectModal').classList.add('open');
        }
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('open');
        }

        // Status "Update" button: enabled only when the choice changed; asks before saving
        document.querySelectorAll('.status-form').forEach(function (f) {
            var sel = f.querySelector('select'), btn = f.querySelector('.update');
            sel.addEventListener('change', function () { btn.disabled = (sel.value === f.dataset.current); });
            f.addEventListener('submit', function (ev) {
                var from = sel.querySelector('option[value="' + f.dataset.current + '"]').text;
                var to = sel.options[sel.selectedIndex].text;
                if (!confirm('Change booking #' + f.dataset.id + ' from ' + from + ' to ' + to + '?')) {
                    ev.preventDefault();
                    sel.value = f.dataset.current;
                    btn.disabled = true;
                }
            });
        });
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') closeRejectModal(); });
    </script>
<script src="../js/app.js" data-base="../" data-role="admin"></script>
</body>
</html>