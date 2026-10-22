<?php
// ---------- 1. CONNECTION + SESSION ----------
require_once "../config.php";
require_once "../payment_lib.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- 2. ADMIN AUTHENTICATION ----------
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit();
}
$admin_id = (int)$_SESSION["user_id"];

ensure_payment_schema($conn);

function flash_redirect(string $msg, string $type, string $qs = ""): void {
    $_SESSION["flash_msg"] = $msg;
    $_SESSION["flash_type"] = $type;
    header("Location: payments.php" . $qs);
    exit();
}

$message = $_SESSION["flash_msg"] ?? "";
$message_type = $_SESSION["flash_type"] ?? "";
unset($_SESSION["flash_msg"], $_SESSION["flash_type"]);

// ---------- 3. HANDLE POST ACTIONS ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = $_POST["action"] ?? "";
    $back = "?" . http_build_query(array_filter([
        "status" => $_POST["f_status"] ?? "", "search" => $_POST["f_search"] ?? "",
        "date_from" => $_POST["f_from"] ?? "", "date_to" => $_POST["f_to"] ?? "",
    ]));
    if ($back === "?") $back = "";

    if (empty($_POST) && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        flash_redirect("Upload too large for the server (post_max_size = " . ini_get("post_max_size") . ").", "error");
    }
    if (!csrf_valid($_POST["csrf"] ?? null)) {
        flash_redirect("Your session expired. Please try again.", "error", $back);
    }

    /* ----- Save payment settings ----- */
    if ($action === "save_payment_settings") {
        $name = trim($_POST["gcash_name"] ?? "");
        $number = trim($_POST["gcash_number"] ?? "");
        $pct = filter_var($_POST["down_payment_percent"] ?? "", FILTER_VALIDATE_FLOAT);
        $instr = trim($_POST["instructions"] ?? "");

        if ($name === "" || mb_strlen($name) > 100) {
            flash_redirect("GCash account name is required (max 100 characters).", "error");
        }
        if (!preg_match('/^[0-9+\-\s]{7,20}$/', $number)) {
            flash_redirect("Please enter a valid GCash number.", "error");
        }
        if ($pct === false || $pct < 1 || $pct > 100) {
            flash_redirect("Down payment percentage must be between 1 and 100.", "error");
        }

        $current = pay_get_settings($conn);
        $qr_path = (string)($current["gcash_qr"] ?? "");
        $new_qr_abs = null;

        if (isset($_FILES["gcash_qr"]) && $_FILES["gcash_qr"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $up = pay_handle_upload($_FILES["gcash_qr"], PAY_IMAGE_MIMES, PERGOLA_ROOT . "/images/uploads/", "images/uploads/", "gcash_qr", "public");
            if (!$up["ok"]) flash_redirect("QR code upload failed: " . $up["error"], "error");
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
            flash_redirect("Payment settings saved.", "success");
        } catch (Throwable $ex) {
            if ($new_qr_abs) @unlink($new_qr_abs);
            error_log("payments.php settings: " . $ex->getMessage());
            flash_redirect("Could not save payment settings.", "error");
        }
    }

    /* ----- Approve / Reject / Reset status ----- */
    if (in_array($action, ["approve_payment", "reject_payment", "update_payment_status"], true)) {
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
                    throw new RuntimeException("This payment is already " . $pay["status"] . ".");
                }
                $u = $conn->prepare("UPDATE payments SET status = 'paid', verified_by = ?, verified_date = NOW(), admin_remarks = NULL WHERE id = ?");
                $u->bind_param("ii", $admin_id, $payment_id);
                $u->execute();
                $u->close();

                $r = $conn->prepare("UPDATE reservations SET status = 'confirmed' WHERE id = ? AND status IN ('pending','pending_payment_verification','payment_verification_failed','confirmed')");
                $r->bind_param("i", $rid);
                $r->execute();
                $r->close();
                $msg = "Payment #{$payment_id} approved. Reservation #{$rid} is now confirmed.";

            } elseif ($action === "reject_payment") {
                if (in_array($pay["status"], ["paid", "refunded"], true)) {
                    throw new RuntimeException("A " . $pay["status"] . " payment cannot be rejected.");
                }
                if (mb_strlen($remarks) < 3) throw new RuntimeException("Please enter a reason for rejection.");
                $remarks = mb_substr($remarks, 0, 1000);

                $u = $conn->prepare("UPDATE payments SET status = 'rejected', verified_by = ?, verified_date = NOW(), admin_remarks = ? WHERE id = ?");
                $u->bind_param("isi", $admin_id, $remarks, $payment_id);
                $u->execute();
                $u->close();

                $r = $conn->prepare("UPDATE reservations SET status = 'payment_verification_failed' WHERE id = ? AND status IN ('pending','pending_payment_verification','payment_verification_failed')");
                $r->bind_param("i", $rid);
                $r->execute();
                $r->close();
                $msg = "Payment #{$payment_id} rejected. The customer can now upload a new receipt.";

            } else {
                $new_status = $_POST["status"] ?? "";
                if (!in_array($new_status, ["pending", "unpaid", "refunded"], true)) {
                    throw new RuntimeException("Invalid status.");
                }
                $u = $conn->prepare("UPDATE payments SET status = ? WHERE id = ?");
                $u->bind_param("si", $new_status, $payment_id);
                $u->execute();
                $u->close();

                if ($new_status === "pending") {
                    $r = $conn->prepare("UPDATE reservations SET status = 'pending_payment_verification' WHERE id = ? AND status IN ('pending','confirmed','payment_verification_failed')");
                    $r->bind_param("i", $rid);
                    $r->execute();
                    $r->close();
                }
                $msg = "Payment #{$payment_id} status updated to " . ucfirst($new_status) . ".";
            }

            $conn->commit();
            flash_redirect($msg, "success", $back);
        } catch (RuntimeException $ex) {
            $conn->rollback();
            flash_redirect($ex->getMessage(), "error", $back);
        } catch (Throwable $ex) {
            $conn->rollback();
            error_log("payments.php: " . $ex->getMessage());
            flash_redirect("Something went wrong. No changes were made.", "error", $back);
        }
    }
}

// ---------- 4. FILTERS & SEARCH ----------
$valid_statuses = ["pending", "paid", "rejected", "refunded", "unpaid"];
$status_filter = in_array($_GET["status"] ?? "", $valid_statuses, true) ? $_GET["status"] : "";
$search_query = trim($_GET["search"] ?? "");
$date_from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["date_from"] ?? "") ? $_GET["date_from"] : "";
$date_to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET["date_to"] ?? "") ? $_GET["date_to"] : "";

$sql = "SELECT p.id, p.reservation_id, p.amount, p.method, p.status, p.payment_date, p.receipt_image,
               p.total_amount, p.down_payment, p.remaining_balance, p.verified_date, p.admin_remarks,
               r.total_amount AS res_total, r.status AS res_status,
               u.full_name, u.email, v.full_name AS verifier_name
        FROM payments p
        JOIN reservations r ON r.id = p.reservation_id
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users v ON v.id = p.verified_by
        WHERE 1=1";
$params = [];
$types = "";

if ($status_filter !== "") {
    $sql .= " AND p.status = ?";
    $params[] = $status_filter; $types .= "s";
}
if ($search_query !== "") {
    $sql .= " AND (u.full_name LIKE ? OR p.id = ? OR p.reservation_id = ?)";
    $like = "%" . $search_query . "%";
    $sid = (int)$search_query;
    array_push($params, $like, $sid, $sid);
    $types .= "sii";
}
if ($date_from !== "") {
    $sql .= " AND DATE(p.payment_date) >= ?";
    $params[] = $date_from; $types .= "s";
}
if ($date_to !== "") {
    $sql .= " AND DATE(p.payment_date) <= ?";
    $params[] = $date_to; $types .= "s";
}
$sql .= " ORDER BY p.payment_date DESC, p.id DESC";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$payments = $stmt->get_result();

$pending_count = (int)$conn->query("SELECT COUNT(*) c FROM payments WHERE status = 'pending'")->fetch_assoc()["c"];
$settings = pay_get_settings($conn);
$csrf = csrf_token();
$current = "payments";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Payments | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        <?= pay_badge_css() ?>
        .pay-settings summary { cursor:pointer; font-weight:700; font-size:16px; padding:4px 0; }
        .pay-settings .grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:16px; }
        .pay-settings label { display:block; font-weight:600; font-size:13px; margin-bottom:6px; }
        .pay-settings input[type=text], .pay-settings input[type=number], .pay-settings textarea, .pay-settings input[type=file] { width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; }
        .qr-current { max-width:140px; max-height:140px; border:1px solid #ddd; border-radius:6px; background:#fff; margin-bottom:8px; display:block; }
        .money small { display:block; color:#777; font-weight:400; font-size:11px; }
        .act-btn { display:block; width:100%; margin-bottom:6px; padding:7px 10px; border:none; border-radius:6px; cursor:pointer; font-weight:600; font-size:12px; color:#fff; }
        .act-approve { background:#3c8d40; } .act-reject { background:#c0392b; }
        .modal-bg { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; padding:20px; }
        .modal-bg.open { display:flex; }
        .modal { background:#fff; border-radius:10px; padding:24px; max-width:440px; width:100%; }
        .modal h3 { margin-bottom:14px; }
        .modal .mrow { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #eee; font-size:14px; }
        .modal textarea { width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box; margin-top:6px; }
        .modal .mbtns { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; }
        .remark { font-size:12px; color:#a02a35; max-width:180px; }
        @media (max-width:700px){ .pay-settings .grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <h2>Pergola</h2>
        <p>Resort Management</p>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php">Dashboard</a>
        <a href="reservations.php">Reservations</a>
        <a href="calendar.php">Calendar</a>
        <a href="accommodations.php">Accommodations</a>
        <a href="customers.php">Customers</a>
        <a href="payments.php" class="active">Payments</a>
        <a href="resort.php">Resort Info</a>
        <a href="reports.php">Reports</a>
        <a href="settings.php">Settings</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="logout-link">Sign Out</a>
    </div>
</aside>

<main class="main-content">

    <header class="topbar">
        <div class="topbar-title">
            <span class="section-label">FINANCE</span>
            <h1>Payments Management</h1>
            <p>Verify receipts, approve or reject down payments, and manage GCash details.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <?php if (!empty($message)): ?>
        <div class="message <?= $message_type === 'success' ? 'success-message' : 'error-message' ?>" style="padding:12px; margin-bottom:20px; border-radius:6px; background:<?= $message_type === 'success' ? '#dff0d8' : '#f2dede' ?>; color:<?= $message_type === 'success' ? '#3c763d' : '#a94442' ?>;">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <!-- ===== PAYMENT SETTINGS ===== -->
    <section class="card content-card pay-settings" style="margin-bottom:24px;">
        <details <?= isset($_GET["settings"]) ? "open" : "" ?>>
            <summary>⚙ PAYMENT SETTINGS (GCash &amp; Down Payment)</summary>
            <form method="POST" action="payments.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="save_payment_settings">
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
                            <img class="qr-current" src="../<?= e($settings["gcash_qr"]) ?>" alt="Current GCash QR">
                        <?php else: ?>
                            <div class="sub-text" style="margin-bottom:8px;">No QR code uploaded yet.</div>
                        <?php endif; ?>
                        <label for="gcash_qr">New QR Code (JPG, PNG, WEBP, max 5MB)</label>
                        <input type="file" id="gcash_qr" name="gcash_qr" accept="image/jpeg,image/png,image/webp">
                    </div>
                </div>
                <div style="margin-top:16px;">
                    <label for="instructions">Payment Instructions</label>
                    <textarea id="instructions" name="instructions" rows="3"><?= e($settings["instructions"]) ?></textarea>
                </div>
                <div style="margin-top:16px;">
                    <button type="submit" class="admin-btn primary">Save Payment Settings</button>
                </div>
            </form>
        </details>
    </section>

    <!-- ===== FILTERS ===== -->
    <section class="card filter-card">
        <form method="GET" action="payments.php" class="filter-form">
            <div class="form-group-inline">
                <label for="status">Payment Status</label>
                <select name="status" id="status">
                    <option value="">All</option>
                    <?php foreach ($valid_statuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= $status_filter === $st ? "selected" : "" ?>><?= e(ucfirst($st)) ?><?= $st === "pending" ? " (" . $pending_count . ")" : "" ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group-inline search-group">
                <label for="search">Search Guest, Reservation ID or Payment ID</label>
                <input type="text" name="search" id="search" placeholder="Enter guest name or ID..." value="<?= e($search_query) ?>">
            </div>
            <div class="form-group-inline">
                <label for="date_from">From</label>
                <input type="date" name="date_from" id="date_from" value="<?= e($date_from) ?>">
            </div>
            <div class="form-group-inline">
                <label for="date_to">To</label>
                <input type="date" name="date_to" id="date_to" value="<?= e($date_to) ?>">
            </div>
            <div class="form-group-inline btn-group">
                <button type="submit" class="admin-btn primary">Apply Filters</button>
                <a href="payments.php" class="admin-btn secondary">Reset</a>
            </div>
        </form>
    </section>

    <!-- ===== PAYMENTS TABLE ===== -->
    <section class="card content-card full-width-card">
        <div class="card-header-flex">
            <h3>Transaction Logs</h3>
            <span class="record-count"><?= $payments->num_rows ?> records found</span>
        </div>
        <div class="table-wrap">
        <?php if ($payments->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Payment</th>
                        <th>Reservation</th>
                        <th>Guest</th>
                        <th>Total Booking</th>
                        <th>Required Downpayment</th>
                        <th>Amount Submitted</th>
                        <th>Remaining Balance</th>
                        <th>Method</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Receipt</th>
                        <th>Verified</th>
                        <th>Admin Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $payments->fetch_assoc()):
                    $total = (float)($row["total_amount"] ?? $row["res_total"]);
                    $submitted = (float)$row["amount"];
                    $required = (float)($row["down_payment"] ?? $row["amount"]);
                    $remaining = pay_remaining($total, $submitted, (string)$row["status"]);
                    [$ptext, $pcls] = pay_status_info((string)$row["status"]);
                    [$rtext, $rcls] = res_status_info((string)$row["res_status"]);
                    $can_decide = in_array($row["status"], ["pending", "rejected", "unpaid"], true);
                    $can_reject = in_array($row["status"], ["pending", "unpaid"], true);
                ?>
                    <tr>
                        <td>#<?= (int)$row["id"] ?></td>
                        <td>#<?= (int)$row["reservation_id"] ?><br><span class="pbadge <?= e($rcls) ?>" style="font-size:10px;"><?= e($rtext) ?></span></td>
                        <td>
                            <strong><?= e($row["full_name"]) ?></strong><br>
                            <span class="sub-text"><?= e($row["email"]) ?></span>
                        </td>
                        <td><strong><?= peso($total) ?></strong></td>
                        <td><?= peso($required) ?></td>
                        <td><strong><?= peso($submitted) ?></strong></td>
                        <td><?= peso($remaining) ?></td>
                        <td><?= e(strtoupper((string)$row["method"])) ?></td>
                        <td><?= $row["payment_date"] ? e(date("M d, Y g:i A", strtotime($row["payment_date"]))) : "—" ?></td>
                        <td><span class="pbadge <?= e($pcls) ?>"><?= e($ptext) ?></span></td>
                        <td>
                            <?php if (!empty($row["receipt_image"])): ?>
                                <a href="receipt.php?id=<?= (int)$row["id"] ?>" target="_blank" rel="noopener" class="action-link edit">View Receipt</a>
                            <?php else: ?>
                                <span class="sub-text">No receipt</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($row["verified_date"])): ?>
                                <?= e($row["verifier_name"] ?? "Admin") ?><br>
                                <span class="sub-text"><?= e(date("M d, Y g:i A", strtotime($row["verified_date"]))) ?></span>
                            <?php else: ?>
                                <span class="sub-text">—</span>
                            <?php endif; ?>
                        </td>
                        <td><div class="remark"><?= e($row["admin_remarks"] ?? "") ?></div></td>
                        <td style="min-width:150px;">
                            <?php if ($can_decide): ?>
                                <button type="button" class="act-btn act-approve js-approve"
                                    data-id="<?= (int)$row["id"] ?>" data-res="<?= (int)$row["reservation_id"] ?>"
                                    data-amount="<?= e(peso($submitted)) ?>" data-required="<?= e(peso($required)) ?>"
                                    data-remaining="<?= e(peso(max($total - $submitted, 0))) ?>">APPROVE PAYMENT</button>
                            <?php endif; ?>
                            <?php if ($can_reject): ?>
                                <button type="button" class="act-btn act-reject js-reject"
                                    data-id="<?= (int)$row["id"] ?>" data-res="<?= (int)$row["reservation_id"] ?>">REJECT PAYMENT</button>
                            <?php endif; ?>
                            <form method="POST" action="payments.php" class="inline-status-form">
                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                <input type="hidden" name="action" value="update_payment_status">
                                <input type="hidden" name="payment_id" value="<?= (int)$row["id"] ?>">
                                <input type="hidden" name="f_status" value="<?= e($status_filter) ?>">
                                <input type="hidden" name="f_search" value="<?= e($search_query) ?>">
                                <input type="hidden" name="f_from" value="<?= e($date_from) ?>">
                                <input type="hidden" name="f_to" value="<?= e($date_to) ?>">
                                <select name="status" onchange="if(confirm('Change this payment status?')){this.form.submit();}else{this.selectedIndex=0;}" class="status-select">
                                    <option value="" disabled selected>Other...</option>
                                    <option value="pending">Reset to Pending</option>
                                    <option value="unpaid">Mark Unpaid</option>
                                    <option value="refunded">Mark Refunded</option>
                                </select>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="empty">No payment records found matching your filters.</p>
        <?php endif; ?>
        </div>
    </section>

</main>

<!-- ===== APPROVE MODAL ===== -->
<div class="modal-bg" id="approveModal">
    <div class="modal">
        <h3>Approve this payment?</h3>
        <div class="mrow"><span>Reservation</span><strong id="aRes"></strong></div>
        <div class="mrow"><span>Amount</span><strong id="aAmount"></strong></div>
        <div class="mrow"><span>Required Downpayment</span><strong id="aReq"></strong></div>
        <div class="mrow"><span>Remaining Balance</span><strong id="aRem"></strong></div>
        <form method="POST" action="payments.php">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="approve_payment">
            <input type="hidden" name="payment_id" id="aId">
            <input type="hidden" name="f_status" value="<?= e($status_filter) ?>">
            <input type="hidden" name="f_search" value="<?= e($search_query) ?>">
            <input type="hidden" name="f_from" value="<?= e($date_from) ?>">
            <input type="hidden" name="f_to" value="<?= e($date_to) ?>">
            <div class="mbtns">
                <button type="button" class="admin-btn secondary js-close">Cancel</button>
                <button type="submit" class="admin-btn primary">Confirm Payment</button>
            </div>
        </form>
    </div>
</div>

<!-- ===== REJECT MODAL ===== -->
<div class="modal-bg" id="rejectModal">
    <div class="modal">
        <h3>Reject Payment <span id="rRes"></span></h3>
        <form method="POST" action="payments.php">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="reject_payment">
            <input type="hidden" name="payment_id" id="rId">
            <input type="hidden" name="f_status" value="<?= e($status_filter) ?>">
            <input type="hidden" name="f_search" value="<?= e($search_query) ?>">
            <input type="hidden" name="f_from" value="<?= e($date_from) ?>">
            <input type="hidden" name="f_to" value="<?= e($date_to) ?>">
            <label for="rReason" style="font-weight:600; font-size:14px;">Reason for rejection:</label>
            <textarea name="admin_remarks" id="rReason" rows="4" required minlength="3" maxlength="1000" placeholder="e.g. Receipt is unclear / amount does not match"></textarea>
            <div class="mbtns">
                <button type="button" class="admin-btn secondary js-close">Cancel</button>
                <button type="submit" class="admin-btn primary" style="background:#c0392b;">Reject Payment</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const approve = document.getElementById('approveModal');
    const reject = document.getElementById('rejectModal');
    document.querySelectorAll('.js-approve').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('aId').value = b.dataset.id;
            document.getElementById('aRes').textContent = '#' + b.dataset.res;
            document.getElementById('aAmount').textContent = b.dataset.amount;
            document.getElementById('aReq').textContent = b.dataset.required;
            document.getElementById('aRem').textContent = b.dataset.remaining;
            approve.classList.add('open');
        });
    });
    document.querySelectorAll('.js-reject').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('rId').value = b.dataset.id;
            document.getElementById('rRes').textContent = '(Reservation #' + b.dataset.res + ')';
            document.getElementById('rReason').value = '';
            reject.classList.add('open');
        });
    });
    document.querySelectorAll('.js-close').forEach(function (b) {
        b.addEventListener('click', function () { approve.classList.remove('open'); reject.classList.remove('open'); });
    });
    [approve, reject].forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) m.classList.remove('open'); });
    });
})();
</script>
</body>
</html>