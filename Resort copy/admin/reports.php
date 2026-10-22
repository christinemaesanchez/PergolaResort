<?php
// ---------- 1. CONNECTION + SESSION ----------
require_once "../config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- 2. ADMIN AUTHENTICATION ----------
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../login.php");
    exit();
}

// ---------- 3. HELPERS ----------
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function peso($n) { return "₱" . number_format((float)$n, 2); }

// ---------- 4. DATE RANGE & FILTERS ----------
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

// ---------- 5. FETCH ANALYTICS DATA ----------
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

// Reservations breakdown by status
$status_counts = ["pending" => 0, "confirmed" => 0, "cancelled" => 0, "completed" => 0];
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
    <title>Analytics & Reports | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
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
            foreach ($status_counts as $status_name => $count):
                $pct = $sum_status > 0 ? round($count / $sum_status * 100) : 0; 
            ?>
                <div class="bar-row">
                    <div class="bar-label">
                        <span><?= e(ucfirst($status_name)) ?></span>
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
</body>
</html>