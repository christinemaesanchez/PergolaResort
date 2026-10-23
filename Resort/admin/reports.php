<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";   // auth_guard(), notifications, shared helpers

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth_user = auth_guard($conn, "admin", "../login.php");

// =========================================================================
// 2. ADMIN AUTHENTICATION
// =========================================================================
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit();
}

// =========================================================================
// 3. HELPERS
// =========================================================================
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('peso')) {
    function peso($n) { return "₱" . number_format((float)$n, 2); }
}

// Readable names for the reservation statuses stored in the database
$status_labels = [
    "pending"                     => "Pending",
    "pending_payment_verification" => "Pending Payment",
    "confirmed"                   => "Confirmed",
    "completed"                   => "Completed",
    "cancelled"                   => "Cancelled",
    "payment_verification_failed" => "Rejected",
];

// =========================================================================
// 4. DATE RANGE & FILTERS
// =========================================================================
$start_date = $_GET["start_date"] ?? date("Y-m-01"); // Default to start of current month
$end_date   = $_GET["end_date"]   ?? date("Y-m-t");  // Default to end of current month

// Keep only valid dates (otherwise fall back to the current month)
foreach (["start_date" => date("Y-m-01"), "end_date" => date("Y-m-t")] as $k => $fallback) {
    $d = DateTime::createFromFormat("Y-m-d", (string)$$k);
    if (!$d || $d->format("Y-m-d") !== (string)$$k) { $$k = $fallback; }
}
if ($start_date > $end_date) { [$start_date, $end_date] = [$end_date, $start_date]; }

// The end date must include the WHOLE day (timestamps are compared up to 23:59:59)
$start_dt = $start_date . " 00:00:00";
$end_dt   = $end_date . " 23:59:59";

// =========================================================================
// 5. FETCH ANALYTICS DATA
// =========================================================================
// Total revenue within date range
$rev_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE status = 'paid' AND payment_date BETWEEN ? AND ?");
$rev_stmt->bind_param("ss", $start_dt, $end_dt);
$rev_stmt->execute();
$total_revenue = $rev_stmt->get_result()->fetch_assoc()["total"];
$rev_stmt->close();

// Total reservations within date range
$res_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM reservations WHERE created_at BETWEEN ? AND ?");
$res_stmt->bind_param("ss", $start_dt, $end_dt);
$res_stmt->execute();
$total_reservations = $res_stmt->get_result()->fetch_assoc()["total"];
$res_stmt->close();

// Total new customers registered within date range
$cust_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM users WHERE role = 'customer' AND created_at BETWEEN ? AND ?");
$cust_stmt->bind_param("ss", $start_dt, $end_dt);
$cust_stmt->execute();
$total_new_customers = $cust_stmt->get_result()->fetch_assoc()["total"];
$cust_stmt->close();

// Reservations breakdown by status (fixed order; "Rejected" only appears when there is one)
$status_counts = [
    "pending" => 0,
    "pending_payment_verification" => 0,
    "confirmed" => 0,
    "completed" => 0,
    "cancelled" => 0,
];
$status_stmt = $conn->prepare("SELECT status, COUNT(*) AS count FROM reservations WHERE created_at BETWEEN ? AND ? GROUP BY status");
$status_stmt->bind_param("ss", $start_dt, $end_dt);
$status_stmt->execute();
$status_res = $status_stmt->get_result();
while ($row = $status_res->fetch_assoc()) {
    $status_counts[$row["status"]] = (int)$row["count"];
}
$status_stmt->close();

// Top accommodations booked within date range
$top_acc_stmt = $conn->prepare(
    "SELECT a.name, COUNT(r.id) AS bookings, COALESCE(SUM(r.total_amount), 0) AS revenue 
     FROM reservations r 
     JOIN accommodations a ON a.id = r.accommodation_id 
     WHERE r.created_at BETWEEN ? AND ? 
     GROUP BY a.id 
     ORDER BY bookings DESC LIMIT 5"
);
$top_acc_stmt->bind_param("ss", $start_dt, $end_dt);
$top_acc_stmt->execute();
$top_accommodations = $top_acc_stmt->get_result();
$top_acc_stmt->close();

$current = "reports";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics &amp; Reports | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
    /* FORCE HIDE ALL ICONS, SB-TOGGLE, AND NOTIFICATION BELLS */
    .sidebar-nav a i, .sidebar-nav a svg, .sidebar-nav span i,
    .sb-toggle, .sidebar-collapse-btn, .ui-ico,
    .nf-wrap, .nf-admin, .topbar-bell, .notification-bell, .topbar-icons { 
        display: none !important; 
    }

    .filter-card { margin-bottom: 25px; }
    .filter-form { display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end; }
    .form-group-inline { display: flex; flex-direction: column; }
    .form-group-inline label { font-size: 11.5px; font-weight: 700; color: #344a30; margin-bottom: 5px; text-transform: uppercase; letter-spacing: 0.5px; }
    .form-group-inline input[type="date"] { padding: 9px 14px; border: 1px solid #e3dec9; border-radius: 8px; background: #f7f5ee; color: #344a30; font-size: 13.5px; outline: none; }
    .form-group-inline input[type="date"]:focus { border-color: #6f8761; background: white; }
    
    /* FIX: Ensure buttons sit side-by-side horizontally */
    .form-group-inline.btn-group { 
        flex-direction: row !important; 
        align-items: center; 
        gap: 10px; 
    }

    .dashboard-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 25px; }
    .stat-card { background: #fff; border: 1px solid #ede8dc; border-radius: 12px; padding: 22px; box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04); }
    .stat-card h3 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #777; margin-bottom: 8px; font-weight: 700; }
    .stat-card .stat-value { font-size: 26px; font-weight: 700; color: #344a30; }

    .status-bars-container { display: flex; flex-direction: column; gap: 14px; margin-top: 15px; }
    .bar-row { display: flex; flex-direction: column; gap: 6px; }
    .bar-label { display: flex; justify-content: space-between; font-size: 13px; color: #444; }
    .track { width: 100%; background: #f0ede4; border-radius: 6px; height: 10px; overflow: hidden; }
    .fill { height: 100%; background: #6f8761; border-radius: 6px; transition: width 0.4s ease; }
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
        <a href="reservations.php">Reservations</a>
        <a href="calendar.php">Calendar</a>
        <a href="accommodations.php">Accommodations</a>
        <a href="customers.php">Customers</a>
        <a href="resort.php">Resort Info</a>
        <a href="reports.php" class="active">Reports</a>
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
            <span class="section-label">ANALYTICS</span>
            <h1>Performance Reports</h1>
            <p>Generate financial metrics, reservation breakdowns, and booking statistics.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <!-- Date Range Filter Card -->
    <section class="card filter-card">
        <form method="GET" action="reports.php" class="filter-form">
            <div class="form-group-inline">
                <label for="start_date">Start Date</label>
                <input type="date" name="start_date" id="start_date" value="<?= e($start_date) ?>">
            </div>
            <div class="form-group-inline">
                <label for="end_date">End Date</label>
                <input type="date" name="end_date" id="end_date" value="<?= e($end_date) ?>">
            </div>
            <div class="form-group-inline btn-group">
                <button type="submit" class="admin-btn primary">Generate Report</button>
                <a href="reports.php" class="admin-btn secondary">Reset</a>
            </div>
        </form>
    </section>

    <!-- Summary Metric Cards -->
    <section class="dashboard-cards">
        <div class="dashboard-card stat-card">
            <div class="card-info">
                <h3>Period Revenue</h3>
                <div class="stat-value"><?= peso($total_revenue) ?></div>
            </div>
        </div>
        <div class="dashboard-card stat-card">
            <div class="card-info">
                <h3>Total Reservations</h3>
                <div class="stat-value"><?= (int)$total_reservations ?></div>
            </div>
        </div>
        <div class="dashboard-card stat-card">
            <div class="card-info">
                <h3>New Customers</h3>
                <div class="stat-value"><?= (int)$total_new_customers ?></div>
            </div>
        </div>
    </section>

    <!-- Two Column Breakdown -->
    <section class="grid-2">
        <!-- Reservation Status Breakdown -->
        <div class="card content-card">
            <h3>Reservation Status Summary</h3>
            <div class="status-bars-container">
            <?php 
            $sum_status = array_sum($status_counts);
            // Order: the fixed statuses first, then "Rejected" only when there is at least one
            $shown = ["pending", "pending_payment_verification", "confirmed", "completed", "cancelled"];
            if (!empty($status_counts["payment_verification_failed"])) $shown[] = "payment_verification_failed";
            foreach ($shown as $status_key):
                $count = (int)($status_counts[$status_key] ?? 0);
                $status_name = $status_labels[$status_key] ?? ucwords(str_replace("_", " ", $status_key));
                $pct = $sum_status > 0 ? round(($count / $sum_status) * 100) : 0; 
            ?>
                <div class="bar-row">
                    <div class="bar-label">
                        <span><?= e($status_name) ?></span>
                        <span><strong><?= $count ?></strong> (<?= $pct ?>%)</span>
                    </div>
                    <div class="track"><div class="fill" style="width:<?= $pct ?>%"></div></div>
                </div>
            <?php endforeach; ?>
            </div>
        </div>

        <!-- Top Accommodations Performance -->
        <div class="card content-card">
            <h3>Top Accommodations</h3>
            <div class="table-wrap" style="margin-top: 15px;">
            <?php if ($top_accommodations->num_rows > 0): ?>
                <table>
                    <thead>
                        <tr><th>Name</th><th>Bookings</th><th>Revenue</th></tr>
                    </thead>
                    <tbody>
                        <?php while ($acc = $top_accommodations->fetch_assoc()): ?>
                        <tr>
                            <td><strong><?= e($acc["name"]) ?></strong></td>
                            <td><?= (int)$acc["bookings"] ?></td>
                            <td><strong><?= peso($acc["revenue"]) ?></strong></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No booking data found for this period.</p>
            <?php endif; ?>
            </div>
        </div>
    </section>

</main>
<script src="../js/app.js" data-base="../" data-role="admin"></script>
<script src="../js/responsive.js"></script>
</body>
</html>