<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =========================================================================
// 2. ADMIN AUTHENTICATION & SECURITY
// =========================================================================
if (empty($_SESSION["user_id"])) {
    header("Location: ../login.php");
    exit();
}

if (($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit();
}

$stmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'admin' AND status = 'active'");
$stmt->bind_param("i", $_SESSION["user_id"]);
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();

if (!$admin) {
    session_unset();
    session_destroy();
    header("Location: ../login.php");
    exit();
}
$admin_id = (int)$_SESSION["user_id"];

ensure_payment_schema($conn);

// Retrieve session flash messages for success/error notifications
$message = $_SESSION["flash_msg"] ?? "";
$message_type = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_msg"], $_SESSION["flash_type"]);

// Helper function to handle redirects with flash messages
function page_flash(string $msg, string $type, string $qs = ""): void {
    $_SESSION["flash_msg"] = $msg;
    $_SESSION["flash_type"] = $type;
    header("Location: reservations.php" . $qs);
    exit();
}

// =========================================================================
// 3. HANDLE POST ACTIONS (RESERVATIONS & PAYMENTS)
// =========================================================================
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $back = "?" . http_build_query(array_filter([
        "tab" => $_POST["active_tab"] ?? "reservations",
        "status" => $_POST["f_status"] ?? "", 
        "search" => $_POST["f_search"] ?? "",
    ]));
    if ($back === "?") $back = "";

    // Validate CSRF token for security
    if (!csrf_valid($_POST["csrf"] ?? null)) {
        page_flash("Your session expired. Please try again.", "error", $back);
    }

    /* ----- A. UPDATE RESERVATION STATUS ----- */
    if ($action === "update_reservation_status") {
        $res_id = (int)($_POST["reservation_id"] ?? 0);
        $new_status = $_POST["status"] ?? "";
        $allowed_statuses = ['pending', 'confirmed', 'cancelled', 'completed'];

        if (!in_array($new_status, $allowed_statuses, true)) {
            page_flash("Invalid reservation status.", "error", $back);
        }

        try {
            // The reservation must exist
            $cq = $conn->prepare("SELECT accommodation_id, check_in, check_out, status FROM reservations WHERE id = ? LIMIT 1");
            $cq->bind_param("i", $res_id);
            $cq->execute();
            $cur_res = $cq->get_result()->fetch_assoc();
            $cq->close();
            if (!$cur_res) {
                page_flash("Reservation #" . $res_id . " was not found.", "error", $back);
            }

            // Re-activating a cancelled/completed reservation must not double-book the dates
            if (in_array($new_status, PAY_BLOCKING_STATUSES, true) && !in_array($cur_res["status"], PAY_BLOCKING_STATUSES, true)) {
                if (!pay_is_available($conn, (int)$cur_res["accommodation_id"], substr((string)$cur_res["check_in"], 0, 10), substr((string)$cur_res["check_out"], 0, 10), $res_id)) {
                    page_flash("Reservation #" . $res_id . " cannot be set to " . $new_status . ": another active reservation already uses those dates.", "error", $back);
                }
            }

            $q = $conn->prepare("SELECT id, status FROM payments WHERE reservation_id = ? ORDER BY id DESC LIMIT 1");
            $q->bind_param("i", $res_id);
            $q->execute();
            $pay = $q->get_result()->fetch_assoc();
            $q->close();

            if ($pay && in_array($new_status, ['confirmed', 'completed'], true) && $pay["status"] !== "paid") {
                page_flash("Reservation #" . $res_id . " cannot be " . $new_status . " yet: its payment status is " . $pay["status"] . ". Verify the receipt first.", "error", $back);
            }

            $u = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            $u->bind_param("si", $new_status, $res_id);
            $u->execute();
            $u->close();

            page_flash("Reservation #" . $res_id . " status successfully updated to " . ucfirst($new_status) . ".", "success", $back);
        } catch (Throwable $ex) {
            error_log("reservations.php status update: " . $ex->getMessage());
            page_flash("Failed to update reservation status.", "error", $back);
        }
    }

    /* ----- B. SAVE PAYMENT SETTINGS ----- */
    if ($action === "save_payment_settings") {
        $name = trim($_POST["gcash_name"] ?? "");
        $number = trim($_POST["gcash_number"] ?? "");
        $pct = filter_var($_POST["down_payment_percent"] ?? "", FILTER_VALIDATE_FLOAT);
        $instr = trim($_POST["instructions"] ?? "");

        if ($name === "" || mb_strlen($name) > 100) {
            page_flash("GCash account name is required (max 100 characters).", "error", "?tab=payments");
        }
        if (!preg_match('/^[0-9+\-\s]{7,20}$/', $number)) {
            page_flash("Please enter a valid GCash number.", "error", "?tab=payments");
        }
        if ($pct === false || $pct < 1 || $pct > 100) {
            page_flash("Down payment percentage must be between 1 and 100.", "error", "?tab=payments");
        }

        $current_settings = pay_get_settings($conn);
        $qr_path = (string)($current_settings["gcash_qr"] ?? "");
        $new_qr_abs = null;

        if (isset($_FILES["gcash_qr"]) && $_FILES["gcash_qr"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $up = pay_handle_upload($_FILES["gcash_qr"], PAY_IMAGE_MIMES, PERGOLA_ROOT . "/images/uploads/", "images/uploads/", "gcash_qr", "public");
            if (!$up["ok"]) page_flash("QR code upload failed: " . $up["error"], "error", "?tab=payments");
            $old_qr = $qr_path;
            $qr_path = $up["path"];
            $new_qr_abs = $up["abs"];
        }

        try {
            $st = $conn->prepare("UPDATE payment_settings SET gcash_name = ?, gcash_number = ?, down_payment_percent = ?, instructions = ?, gcash_qr = ? WHERE id = 1");
            $st->bind_param("ssdss", $name, $number, $pct, $instr, $qr_path);
            $st->execute();
            $st->close();

            if ($new_qr_abs && !empty($old_qr) && strpos($old_qr, "images/uploads/gcash_qr_") === 0) {
                $old_abs = PERGOLA_ROOT . "/images/uploads/" . basename($old_qr);
                if (is_file($old_abs)) @unlink($old_abs);
            }
            page_flash("Payment settings saved successfully.", "success", "?tab=payments");
        } catch (Throwable $ex) {
            if ($new_qr_abs) @unlink($new_qr_abs);
            page_flash("Could not save payment settings.", "error", "?tab=payments");
        }
    }

    /* ----- C. APPROVE / REJECT PAYMENTS ----- */
    if (in_array($action, ["approve_payment", "reject_payment"], true)) {
        $payment_id = (int)($_POST["payment_id"] ?? 0);
        $remarks = trim($_POST["admin_remarks"] ?? "");

        try {
            $conn->begin_transaction();

            $q = $conn->prepare("SELECT id, reservation_id, status FROM payments WHERE id = ? FOR UPDATE");
            $q->bind_param("i", $payment_id);
            $q->execute();
            $pay = $q->get_result()->fetch_assoc();
            $q->close();

            if (!$pay) throw new RuntimeException("Payment record not found.");
            $rid = (int)$pay["reservation_id"];

            if ($action === "approve_payment") {
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

                $msg = "Payment #" . $payment_id . " approved. Reservation #" . $rid . " is now confirmed.";
            } else {
                if (in_array($pay["status"], ["paid", "refunded"], true)) {
                    throw new RuntimeException("A paid payment cannot be rejected.");
                }
                if (mb_strlen($remarks) < 3) throw new RuntimeException("Please enter a reason for rejection.");

                $u = $conn->prepare("UPDATE payments SET status = 'rejected', verified_by = ?, verified_date = NOW(), admin_remarks = ? WHERE id = ?");
                $u->bind_param("isi", $admin_id, $remarks, $payment_id);
                $u->execute();
                $u->close();

                $r = $conn->prepare("UPDATE reservations SET status = 'payment_verification_failed' WHERE id = ?");
                $r->bind_param("i", $rid);
                $r->execute();
                $r->close();

                $msg = "Payment #" . $payment_id . " rejected. Customer notified to re-upload receipt.";
            }

            $conn->commit();
            page_flash($msg, "success", "?tab=payments");
        } catch (RuntimeException $ex) {
            $conn->rollback();
            page_flash($ex->getMessage(), "error", "?tab=payments");
        } catch (Throwable $ex) {
            $conn->rollback();
            page_flash("Something went wrong processing the payment.", "error", "?tab=payments");
        }
    }
}

// =========================================================================
// 4. DATA QUERIES & TAB CONFIGURATION
// =========================================================================
$active_tab = $_GET["tab"] ?? "reservations";

// Filters for Reservations
$valid_res_statuses = ['pending_payment_verification', 'payment_verification_failed', 'pending', 'confirmed', 'cancelled', 'completed'];
$res_status_filter = in_array($_GET["status"] ?? "", $valid_res_statuses, true) ? $_GET["status"] : "";
$search_query = trim($_GET["search"] ?? "");
$user_filter = (int)($_GET["user"] ?? 0);   // set when opened from the Customers page (one customer's history)

// Fetch Reservations
$res_sql = "SELECT r.id, u.full_name, u.email, u.phone, a.name AS accommodation, r.check_in, r.check_out, r.guests,
                   r.total_amount, r.status, r.created_at,
                   p.id AS pay_id, p.status AS pay_status, p.amount AS pay_amount, p.down_payment,
                   p.receipt_image, p.admin_remarks
            FROM reservations r
            JOIN users u ON u.id = r.user_id
            JOIN accommodations a ON a.id = r.accommodation_id
            LEFT JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
            WHERE 1=1";

$res_params = [];
$res_types = "";
if ($res_status_filter !== "") {
    $res_sql .= " AND r.status = ?";
    $res_params[] = $res_status_filter;
    $res_types .= "s";
}
if ($user_filter > 0) {
    $res_sql .= " AND r.user_id = ?";
    $res_params[] = $user_filter;
    $res_types .= "i";
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

// Fetch Payments
$pay_sql = "SELECT p.id, p.reservation_id, p.amount, p.method, p.status, p.payment_date, p.receipt_image,
                   p.total_amount, p.down_payment, p.remaining_balance, p.verified_date, p.admin_remarks,
                   r.total_amount AS res_total, r.status AS res_status,
                   u.full_name, u.email, v.full_name AS verifier_name
            FROM payments p
            JOIN reservations r ON r.id = p.reservation_id
            JOIN users u ON u.id = r.user_id
            LEFT JOIN users v ON v.id = p.verified_by
            ORDER BY p.payment_date DESC, p.id DESC";
$payments = $conn->query($pay_sql);

$awaiting_res = (int)$conn->query("SELECT COUNT(*) c FROM reservations WHERE status = 'pending_payment_verification'")->fetch_assoc()["c"];
$awaiting_pay = (int)$conn->query("SELECT COUNT(*) c FROM payments WHERE status = 'pending'")->fetch_assoc()["c"];
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
    <style>
        <?= pay_badge_css() ?>
        .pay-cell { font-size: 12px; line-height: 1.6; min-width: 170px; }
        .pay-cell .pbadge { margin-bottom: 4px; }
        .pay-cell a { color: var(--primary-color, #2f6b3a); font-weight: 600; text-decoration: none; }
        
        .alert-bar { padding: 12px 16px; margin-bottom: 20px; border-radius: 8px; background: #fff3cd; color: #8a6d00; border: 1px solid #ffeeba; }
        .alert-bar a { color: #8a6d00; font-weight: 700; text-decoration: underline; }
        
        /* Navigation Tabs Header matching dashboard style */
        .page-tabs { display: flex; gap: 10px; margin-bottom: 24px; border-bottom: 2px solid var(--border-color); padding-bottom: 12px; }
        .page-tab-btn { padding: 10px 20px; font-weight: 600; text-decoration: none; border-radius: 8px; color: #555; background: #f1f1f1; transition: all 0.2s; font-size: 14px; }
        .page-tab-btn.active { background: var(--primary-color, #2f6b3a); color: #fff; }
        
        /* Payment Settings & Forms */
        .pay-settings summary { cursor: pointer; font-weight: 700; font-size: 15px; padding: 4px 0; color: var(--primary-color); }
        .pay-settings .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 16px; }
        .pay-settings label { display: block; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #333; }
        .pay-settings input, .pay-settings textarea { width: 100%; padding: 10px; border: 1px solid var(--border-color, #ccc); border-radius: 8px; box-sizing: border-box; font-size: 14px; }
        .qr-current { max-width: 120px; max-height: 120px; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 8px; display: block; object-fit: cover; }
        
        /* Modals */
        .modal-bg { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 1000; align-items: center; justify-content: center; padding: 20px; }
        .modal-bg.open { display: flex; }
        .modal { background: #fff; border-radius: 10px; padding: 24px; max-width: 440px; width: 100%; box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .modal textarea { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 8px; margin-top: 6px; box-sizing: border-box; }
        
        /* Uniform Action Buttons */
        .action-btn-group { display: flex; flex-direction: column; gap: 6px; width: 100%; }
        .admin-btn.approve-btn { background: #2e6b3a; color: #fff; width: 100%; padding: 8px 12px; font-size: 12px; text-align: center; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .admin-btn.approve-btn:hover { background: #24552e; }
        .admin-btn.reject-btn { background: #a02a35; color: #fff; width: 100%; padding: 8px 12px; font-size: 12px; text-align: center; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .admin-btn.reject-btn:hover { background: #82212b; }
        
        @media (max-width: 700px){ .pay-settings .grid { grid-template-columns: 1fr; } }
    </style>
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

    <!-- =================================================================== -->
    <!-- MAIN CONTENT AREA                                                   -->
    <!-- =================================================================== -->
    <main class="main-content">

        <header class="topbar">
            <div class="topbar-title">
                <span class="section-label">OPERATIONS & FINANCE</span>
                <h1>Reservations & Payments Management</h1>
                <p>Manage bookings, verify payment receipts, and configure payment parameters.</p>
            </div>
            <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
        </header>

        <!-- Flash Messages -->
        <?php if (!empty($message)): ?>
            <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>" style="padding: 12px; margin-bottom: 20px; border-radius: 8px; background: <?= $message_type === 'success' ? '#dff0d8' : '#f2dede' ?>; color: <?= $message_type === 'success' ? '#3c763d' : '#a94442' ?>;">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <!-- Alert Banners -->
        <?php if ($awaiting_res > 0 || $awaiting_pay > 0): ?>
            <div class="alert-bar">
                Notice: You have <strong><?= max($awaiting_res, $awaiting_pay) ?></strong> item(s) pending payment verification. 
                <a href="reservations.php?tab=payments">Switch to Payments tab</a>
            </div>
        <?php endif; ?>

        <!-- Page Section Tabs -->
        <div class="page-tabs">
            <a href="reservations.php?tab=reservations" class="page-tab-btn <?= $active_tab !== 'payments' ? 'active' : '' ?>">Bookings & Reservations</a>
            <a href="reservations.php?tab=payments" class="page-tab-btn <?= $active_tab === 'payments' ? 'active' : '' ?>">Payment Receipts & Settings</a>
        </div>

        <!-- =============================================================== -->
        <!-- TAB 1: RESERVATIONS VIEW                                        -->
        <!-- =============================================================== -->
        <?php if ($active_tab !== 'payments'): ?>
            <!-- Filter & Search Bar -->
            <section class="card filter-card">
                <form method="GET" action="reservations.php" class="filter-form">
                    <input type="hidden" name="tab" value="reservations">
                    <?php if ($user_filter > 0): ?><input type="hidden" name="user" value="<?= $user_filter ?>"><?php endif; ?>
                    <div class="form-group-inline">
                        <label for="status">Filter Status</label>
                        <select name="status" id="status">
                            <option value="">All Statuses</option>
                            <?php foreach ($valid_res_statuses as $st): ?>
                                <option value="<?= e($st) ?>" <?= $res_status_filter === $st ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $st))) ?></option>
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

            <!-- Reservations Table -->
            <section class="card content-card full-width-card">
                <div class="card-header-flex">
                    <h3>Booking Records</h3>
                    <span class="record-count"><?= $reservations->num_rows ?> records found</span>
                </div>
                <div class="table-wrap">
                <?php if ($reservations->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Guest Name</th>
                                <th>Accommodation</th>
                                <th>Check-in / Out</th>
                                <th>Guests</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($r = $reservations->fetch_assoc()):
                                $total = (float)$r["total_amount"];
                                [$rtext, $rcls] = res_status_info((string)$r["status"]);
                                if ($r["pay_id"]) {
                                    $ps = (string)$r["pay_status"];
                                    [$ptext, $pcls] = pay_status_info($ps);
                                    $submitted = (float)$r["pay_amount"];
                                    $required = (float)($r["down_payment"] ?? $r["pay_amount"]);
                                    $remaining = pay_remaining($total, $submitted, $ps);
                                }
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
                                <td><?= (int)$r["guests"] ?> Pax</td>
                                <td><strong><?= peso($total) ?></strong></td>
                                <td>
                                    <?php if ($r["pay_id"]): ?>
                                        <div class="pay-cell">
                                            <span class="pbadge <?= e($pcls) ?>"><?= e($ptext) ?></span><br>
                                            Downpayment: <?= peso($required) ?><br>
                                            Remaining: <?= peso($remaining) ?><br>
                                            <?php if (!empty($r["receipt_image"])): ?>
                                                <a href="receipt.php?id=<?= (int)$r["pay_id"] ?>" target="_blank" rel="noopener">View Receipt</a> |
                                            <?php endif; ?>
                                            <a href="reservations.php?tab=payments&search=<?= (int)$r["id"] ?>">Review Payment</a>
                                        </div>
                                    <?php else: ?>
                                        <span class="sub-text">No payment record</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="pbadge <?= e($rcls) ?>"><?= e($rtext) ?></span></td>
                                <td>
                                    <form method="POST" action="reservations.php" class="inline-status-form">
                                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                        <input type="hidden" name="action" value="update_reservation_status">
                                        <input type="hidden" name="reservation_id" value="<?= (int)$r["id"] ?>">
                                        <input type="hidden" name="active_tab" value="reservations">
                                        <select name="status" onchange="if(confirm('Change this reservation status?')){this.form.submit();}else{this.selectedIndex=0;}" class="status-select">
                                            <option value="" disabled selected>Update...</option>
                                            <option value="pending">Pending</option>
                                            <option value="confirmed">Confirm</option>
                                            <option value="completed">Complete</option>
                                            <option value="cancelled">Cancel</option>
                                        </select>
                                    </form>
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

        <!-- =============================================================== -->
        <!-- TAB 2: PAYMENTS VIEW                                            -->
        <!-- =============================================================== -->
        <?php else: ?>
            <!-- Payment Settings Section -->
            <section class="card content-card pay-settings" style="margin-bottom: 24px;">
                <details>
                    <summary>Payment Settings (GCash &amp; Down Payment Configuration)</summary>
                    <form method="POST" action="reservations.php" enctype="multipart/form-data">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="save_payment_settings">
                        <input type="hidden" name="active_tab" value="payments">
                        <div class="grid">
                            <div>
                                <label for="gcash_name">GCash Account Name</label>
                                <input type="text" id="gcash_name" name="gcash_name" required maxlength="100" value="<?= e($settings["gcash_name"]) ?>">
                            </div>
                            <div>
                                <label for="gcash_number">GCash Number</label>
                                <input type="text" id="gcash_number" name="gcash_number" required maxlength="20" value="<?= e($settings["gcash_number"]) ?>">
                            </div>
                            <div>
                                <label for="down_payment_percent">Required Down Payment (%)</label>
                                <input type="number" id="down_payment_percent" name="down_payment_percent" required min="1" max="100" step="0.01" value="<?= e(pay_percent_display((float)$settings["down_payment_percent"])) ?>">
                            </div>
                            <div>
                                <label>Current QR Code</label>
                                <?php if (!empty($settings["gcash_qr"])): ?>
                                    <img class="qr-current" src="../<?= e($settings["gcash_qr"]) ?>" alt="GCash QR">
                                <?php endif; ?>
                                <input type="file" name="gcash_qr" accept="image/png,image/jpeg,image/webp">
                            </div>
                        </div>
                        <div style="margin-top: 14px;">
                            <label for="instructions">Payment Instructions</label>
                            <textarea id="instructions" name="instructions" rows="2"><?= e($settings["instructions"]) ?></textarea>
                        </div>
                        <div style="margin-top: 14px;">
                            <button type="submit" class="admin-btn primary">Save Payment Settings</button>
                        </div>
                    </form>
                </details>
            </section>

            <!-- Payments Table Section -->
            <section class="card content-card full-width-card">
                <div class="card-header-flex">
                    <h3>Payment Transactions & Receipts</h3>
                    <span class="record-count"><?= $payments->num_rows ?> records found</span>
                </div>
                <div class="table-wrap">
                <?php if ($payments->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Res #</th>
                                <th>Guest</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Receipt</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($p = $payments->fetch_assoc()):
                                [$ptext, $pcls] = pay_status_info((string)$p["status"]);
                            ?>
                            <tr>
                                <td>#<?= (int)$p["id"] ?></td>
                                <td><a href="reservations.php?tab=reservations&search=<?= (int)$p["reservation_id"] ?>">#<?= (int)$p["reservation_id"] ?></a></td>
                                <td><strong><?= e($p["full_name"]) ?></strong></td>
                                <td><?= peso($p["amount"]) ?></td>
                                <td><?= e(strtoupper($p["method"])) ?></td>
                                <td>
                                    <?php if (!empty($p["receipt_image"])): ?>
                                        <a href="receipt.php?id=<?= (int)$p["id"] ?>" target="_blank">View Receipt</a>
                                    <?php else: ?>
                                        <span class="sub-text">None</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(date("M d, Y", strtotime($p["payment_date"]))) ?></td>
                                <td><span class="pbadge <?= e($pcls) ?>"><?= e($ptext) ?></span></td>
                                <td>
                                    <?php if ($p["status"] === "pending"): ?>
                                        <div class="action-btn-group">
                                            <form method="POST" action="reservations.php" style="margin: 0;">
                                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="approve_payment">
                                                <input type="hidden" name="payment_id" value="<?= (int)$p["id"] ?>">
                                                <input type="hidden" name="active_tab" value="payments">
                                                <button type="submit" class="admin-btn approve-btn" onclick="return confirm('Approve this payment?');">Approve</button>
                                            </form>
                                            <button type="button" class="admin-btn reject-btn" onclick="openRejectModal(<?= (int)$p["id"] ?>)">Reject</button>
                                        </div>
                                    <?php else: ?>
                                        <span class="sub-text">Processed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="empty">No payment records found.</p>
                <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>

    </main>

    <!-- Rejection Modal -->
    <div id="rejectModal" class="modal-bg">
        <div class="modal">
            <h3>Reject Payment</h3>
            <form method="POST" action="reservations.php">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="reject_payment">
                <input type="hidden" name="payment_id" id="rejectPaymentId">
                <input type="hidden" name="active_tab" value="payments">
                <label for="admin_remarks" style="font-weight: 600; font-size: 13px; margin-bottom: 6px; display: block;">Reason for Rejection:</label>
                <textarea name="admin_remarks" id="admin_remarks" rows="3" required placeholder="Enter the reason for rejection..."></textarea>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 15px;">
                    <button type="button" class="admin-btn secondary" onclick="closeRejectModal()">Cancel</button>
                    <button type="submit" class="admin-btn primary" style="background: #a02a35;">Confirm Reject</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openRejectModal(id) {
            document.getElementById('rejectPaymentId').value = id;
            document.getElementById('rejectModal').classList.add('open');
        }
        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('open');
        }
    </script>
</body>
</html>