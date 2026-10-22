<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";   // e(), peso(), res_status_info(), pay_badge_css()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =========================================================================
// 2. ADMIN AUTHENTICATION
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

ensure_payment_schema($conn);

// =========================================================================
// 3. SUMMARY NUMBERS
// =========================================================================
$rs = $conn->query("SELECT COUNT(*) AS total,
        COALESCE(SUM(status IN ('pending','pending_payment_verification','payment_verification_failed')), 0) AS pending,
        COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
        COALESCE(SUM(status = 'completed'), 0) AS completed,
        COALESCE(SUM(status = 'cancelled'), 0) AS cancelled
    FROM reservations")->fetch_assoc();

$total_reservations = (int)$rs["total"];
$pending_count      = (int)$rs["pending"];
$confirmed_count    = (int)$rs["confirmed"];
$completed_count    = (int)$rs["completed"];
$cancelled_count    = (int)$rs["cancelled"];

$ac = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(is_available = 1), 0) AS available FROM accommodations")->fetch_assoc();
$acc_total     = (int)$ac["total"];
$acc_available = (int)$ac["available"];

$awaiting_payments = (int)$conn->query("SELECT COUNT(*) AS c FROM payments WHERE status = 'pending'")->fetch_assoc()["c"];
$total_revenue     = (float)$conn->query("SELECT COALESCE(SUM(amount), 0) AS t FROM payments WHERE status = 'paid'")->fetch_assoc()["t"];

// =========================================================================
// 4. RECENT RESERVATIONS
// =========================================================================
$recent = $conn->query("SELECT r.id, u.full_name, a.name AS accommodation, r.check_in, r.check_out, r.guests, r.total_amount, r.status
                        FROM reservations r
                        JOIN users u ON u.id = r.user_id
                        JOIN accommodations a ON a.id = r.accommodation_id
                        ORDER BY r.created_at DESC, r.id DESC
                        LIMIT 5");

$bar_total = max($total_reservations, 1);
$current = "dashboard";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | Pergola Resort Admin</title>
    <!-- Connected directly to external admin.css for a clean & uniform UI -->
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        <?= pay_badge_css() ?>
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
            <a href="dashboard.php" class="active">Dashboard</a>
            <a href="reservations.php">Reservations</a>
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
                <span class="section-label">OVERVIEW</span>
                <h1>Welcome, <?= e($admin["full_name"]) ?></h1>
                <p>A quick summary of reservations and accommodations at Pergola Resort.</p>
            </div>
            <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
        </header>

        <?php if ($awaiting_payments > 0): ?>
            <div class="message error-message">
                You have <strong><?= $awaiting_payments ?></strong> payment(s) waiting for verification.
                <a href="reservations.php?tab=payments" style="color: inherit; text-decoration: underline; margin-left: 8px;">Review payments</a>
            </div>
        <?php endif; ?>

        <!-- Summary Cards Grid -->
        <section class="dashboard-cards">
            <div class="dashboard-card"><div class="card-info"><h3>Total Reservations</h3><div class="stat-value"><?= $total_reservations ?></div></div></div>
            <div class="dashboard-card"><div class="card-info"><h3>Pending</h3><div class="stat-value"><?= $pending_count ?></div></div></div>
            <div class="dashboard-card"><div class="card-info"><h3>Confirmed</h3><div class="stat-value"><?= $confirmed_count ?></div></div></div>
            <div class="dashboard-card"><div class="card-info"><h3>Completed</h3><div class="stat-value"><?= $completed_count ?></div></div></div>
            <div class="dashboard-card"><div class="card-info"><h3>Cancelled</h3><div class="stat-value"><?= $cancelled_count ?></div></div></div>
            <div class="dashboard-card"><div class="card-info"><h3>Available</h3><div class="stat-value"><?= $acc_available ?> / <?= $acc_total ?></div></div></div>
            <div class="dashboard-card"><div class="card-info"><h3>Verified Payments</h3><div class="stat-value"><?= peso($total_revenue) ?></div></div></div>
        </section>

        <div class="grid-2">
            <!-- Recent Reservations Table -->
            <section class="card">
                <div class="card-header-flex">
                    <h3>Recent Reservations</h3>
                    <a href="reservations.php" class="view-btn">View all</a>
                </div>
                <div class="table-wrap">
                <?php if ($recent->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Guest Name</th>
                                <th>Accommodation</th>
                                <th>Check-in / Out</th>
                                <th>Guests</th>
                                <th>Total</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($r = $recent->fetch_assoc()):
                                [$rtext, $rcls] = res_status_info((string)$r["status"]);
                                
                                // Map status to standard admin.css badge classes
                                $badgeClass = 'b-pending';
                                if ($r["status"] === 'confirmed') $badgeClass = 'b-confirmed';
                                elseif ($r["status"] === 'completed') $badgeClass = 'b-completed';
                                elseif ($r["status"] === 'cancelled') $badgeClass = 'b-cancelled';
                            ?>
                            <tr>
                                <td>#<?= (int)$r["id"] ?></td>
                                <td><strong><?= e($r["full_name"]) ?></strong></td>
                                <td><?= e($r["accommodation"]) ?></td>
                                <td>
                                    <span class="date-block">In: <?= e(date("M d, Y", strtotime($r["check_in"]))) ?></span>
                                    <span class="date-block">Out: <?= e(date("M d, Y", strtotime($r["check_out"]))) ?></span>
                                </td>
                                <td><?= (int)$r["guests"] ?> Pax</td>
                                <td><strong><?= peso($r["total_amount"]) ?></strong></td>
                                <td><span class="badge <?= $badgeClass ?>"><?= e($rtext) ?></span></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="empty">No reservations yet.</p>
                <?php endif; ?>
                </div>
            </section>

            <!-- Status Breakdown -->
            <section class="card">
                <h3>Reservation Status</h3>
                <div class="status-bars-container">
                    <?php foreach ([
                        "Pending"   => $pending_count,
                        "Confirmed" => $confirmed_count,
                        "Completed" => $completed_count,
                        "Cancelled" => $cancelled_count,
                    ] as $label => $count): ?>
                        <div class="bar-row">
                            <div class="bar-label">
                                <span><?= e($label) ?></span>
                                <strong><?= (int)$count ?></strong>
                            </div>
                            <div class="track">
                                <div class="fill" style="width: <?= round(($count / $bar_total) * 100) ?>%;"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

    </main>
</body>
</html>