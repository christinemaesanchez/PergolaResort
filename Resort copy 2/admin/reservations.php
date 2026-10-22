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

            $u = $conn->prepare("UPDATE reservations SET status = ? WHERE id = ?");
            $u->bind_param("si", $new_status, $res_id);
            $u->execute();
            $u->close();

            page_flash("Reservation #" . $res_id . " status updated to " . ucfirst($new_status) . ".", "success", $back);
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
$valid_res_statuses = ['pending_payment_verification', 'payment_verification_failed', 'pending', 'confirmed', 'cancelled', 'completed'];
$res_status_filter = in_array($_GET["status"] ?? "", $valid_res_statuses, true) ? $_GET["status"] : "";
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
if ($res_status_filter !== "") {
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
        
        /* Clean Uniform Action Stack */
        .action-stack {
            display: flex;
            flex-direction: column;
            gap: 6px;
            width: 145px;
        }

        .action-btn {
            width: 100%;
            padding: 7px 10px;
            font-size: 11.5px;
            font-weight: 600;
            border-radius: 6px;
            text-align: center;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
        }

        .action-btn.approve {
            background: #6f8761;
            color: white;
        }
        .action-btn.approve:hover {
            background: #526b49;
        }

        .action-btn.reject {
            background: #fbeae8;
            color: #9c2e25;
            border: 1px solid #f5c7c2;
        }
        .action-btn.reject:hover {
            background: #f7dcd7;
        }

        .table-action-select {
            width: 100%;
            padding: 7px 10px;
            font-size: 11.5px;
            border-radius: 6px;
            border: 1px solid #e3dec9;
            background: #f7f5ee;
            color: #344a30;
            cursor: pointer;
            outline: none;
            font-weight: 600;
            transition: border-color 0.2s;
        }
        .table-action-select:hover, .table-action-select:focus {
            border-color: #6f8761;
            background: white;
        }

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
                                [$rtext, $rcls] = res_status_info((string)$r["status"]);
                                
                                // Proper color-coded badge mapping for reservation status
                                $resBadgeClass = 'b-pending';
                                if ($r["status"] === 'confirmed') $resBadgeClass = 'b-confirmed';
                                elseif ($r["status"] === 'completed') $resBadgeClass = 'b-completed';
                                elseif ($r["status"] === 'cancelled') $resBadgeClass = 'b-cancelled';

                                $payBadgeClass = 'b-pending';
                                if ($r["pay_id"]) {
                                    $ps = (string)$r["pay_status"];
                                    [$ptext, $pcls] = pay_status_info($ps);
                                    if ($ps === 'paid' || $ps === 'verified') $payBadgeClass = 'b-confirmed';
                                    elseif ($ps === 'rejected' || $ps === 'failed') $payBadgeClass = 'b-cancelled';
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
                                <td>
                                    <?php if ($r["pay_id"]): ?>
                                        <div class="pay-cell">
                                            <strong><?= peso($total) ?></strong><br>
                                            <div style="display: flex; align-items: center; gap: 8px; margin: 4px 0; white-space: nowrap;">
                                                <strong style="color: #0047ff; font-weight: 700;"><?= e(strtoupper((string)($r["pay_method"] ?? 'GCASH'))) ?></strong>
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
                                        <!-- Unified One-Click Payment Approval & Confirmation -->
                                        <?php if ($r["pay_id"] && $r["pay_status"] === 'pending'): ?>
                                            <form method="POST" action="reservations.php" style="margin: 0;">
                                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="action" value="approve_payment">
                                                <input type="hidden" name="payment_id" value="<?= (int)$r["pay_id"] ?>">
                                                <input type="hidden" name="active_tab" value="reservations">
                                                <button type="submit" class="action-btn approve" onclick="return confirm('Approve payment & confirm booking #<?= (int)$r["id"] ?>?');">Approve & Confirm</button>
                                            </form>
                                            <button type="button" class="action-btn reject" onclick="openRejectModal(<?= (int)$r["pay_id"] ?>)">Reject Payment</button>
                                        <?php endif; ?>

                                        <!-- Uniform Status Dropdown -->
                                        <form method="POST" action="reservations.php" style="margin: 0;">
                                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="action" value="update_reservation_status">
                                            <input type="hidden" name="reservation_id" value="<?= (int)$r["id"] ?>">
                                            <input type="hidden" name="active_tab" value="reservations">
                                            <select name="status" onchange="if(confirm('Change booking status?')){this.form.submit();}else{this.selectedIndex=0;}" class="table-action-select">
                                                <option value="" disabled selected>Update status...</option>
                                                <option value="pending">Pending</option>
                                                <option value="confirmed">Confirm</option>
                                                <option value="completed">Complete</option>
                                                <option value="cancelled">Cancel</option>
                                            </select>
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
    </script>
<script src="../js/app.js" data-base="../" data-role="admin"></script>
</body>
</html>