<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";   // e(), peso(), pay_badge_css()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
            default:                                    return ['Pending', 'b-pending'];
        }
    }
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
        COALESCE(SUM(status IN ('pending','pending_payment_verification')), 0) AS pending,
        COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
        COALESCE(SUM(status = 'completed'), 0) AS completed,
        COALESCE(SUM(status = 'cancelled'), 0) AS cancelled,
        COALESCE(SUM(status = 'payment_verification_failed'), 0) AS rejected
    FROM reservations")->fetch_assoc();

$total_reservations = (int)$rs["total"];
$pending_count      = (int)$rs["pending"];
$confirmed_count    = (int)$rs["confirmed"];
$completed_count    = (int)$rs["completed"];
$cancelled_count    = (int)$rs["cancelled"];
$rejected_count     = (int)$rs["rejected"];

// Only rooms/places the customer can actually book right now
$has_status = ($conn->query("SHOW COLUMNS FROM accommodations LIKE 'status'")->num_rows > 0);
$acc_where  = $has_status ? "WHERE status = 'active' OR status IS NULL" : "";
$ac = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(is_available = 1), 0) AS available FROM accommodations $acc_where")->fetch_assoc();
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
    <link rel="stylesheet" href="../css/admin.css">
    <link rel="stylesheet" href="../css/ui.css">
    <style>
        <?= pay_badge_css() ?>

        /* Same hidden icons / bells as the other admin pages */
        .sidebar-nav a i, .sidebar-nav a svg, .sidebar-nav span i,
        .sb-toggle, .sidebar-collapse-btn, .ui-ico,
        .nf-wrap, .nf-admin, .topbar-bell, .notification-bell, .topbar-icons,
        .badge svg, .pbadge svg, .badge i, .pbadge i {
            display: none !important;
        }

        /* Booking ID column styling matching screenshot reference */
        .booking-id-link {
            color: #3b3b3b !important;
            font-weight: 400 !important;
            text-decoration: none !important;
        }
        .booking-id-link:hover {
            color: #1a1a1a !important;
            text-decoration: underline !important;
        }

        /* Uniform pill-shaped status badges */
        .badge, .pbadge {
            display: inline-block;
            border-radius: 20px !important;
            padding: 5px 12px !important;
            font-size: 11px !important;
            font-weight: 700 !important;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .b-pending   { background: #fcf6df !important; color: #8a6d3b !important; }
        .b-confirmed { background: #e8f4e8 !important; color: #256030 !important; }
        .b-completed { background: #e3eff8 !important; color: #31708f !important; }
        .b-cancelled { background: #fbeae8 !important; color: #a02a35 !important; }

        /* Summary cards that link to the matching reservations */
        a.dashboard-card { text-decoration: none; color: inherit; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        a.dashboard-card:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(52, 74, 48, 0.12); }
        .bar-label a { color: inherit; text-decoration: none; }
        .bar-label a:hover { text-decoration: underline; }
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
                You have <strong><?= $awaiting_payments ?></strong> payment<?= $awaiting_payments > 1 ? "s" : "" ?> waiting for verification.
                <a href="reservations.php?tab=reservations&amp;status=pending" style="color: inherit; text-decoration: underline; margin-left: 8px;">Review payments</a>
            </div>
        <?php endif; ?>

        <!-- Summary Cards Grid -->
        <section class="dashboard-cards">
            <a class="dashboard-card" href="reservations.php?tab=reservations"><div class="card-info"><h3>Total Reservations</h3><div class="stat-value"><?= $total_reservations ?></div></div></a>
            <a class="dashboard-card" href="reservations.php?tab=reservations&amp;status=pending"><div class="card-info"><h3>Pending</h3><div class="stat-value"><?= $pending_count ?></div></div></a>
            <a class="dashboard-card" href="reservations.php?tab=reservations&amp;status=confirmed"><div class="card-info"><h3>Confirmed</h3><div class="stat-value"><?= $confirmed_count ?></div></div></a>
            <a class="dashboard-card" href="reservations.php?tab=reservations&amp;status=completed"><div class="card-info"><h3>Completed</h3><div class="stat-value"><?= $completed_count ?></div></div></a>
            <a class="dashboard-card" href="reservations.php?tab=reservations&amp;status=cancelled"><div class="card-info"><h3>Cancelled</h3><div class="stat-value"><?= $cancelled_count ?></div></div></a>
            <?php if ($rejected_count > 0): ?>
                <a class="dashboard-card" href="reservations.php?tab=reservations&amp;status=rejected"><div class="card-info"><h3>Rejected</h3><div class="stat-value"><?= $rejected_count ?></div></div></a>
            <?php endif; ?>
            <a class="dashboard-card" href="accommodations.php"><div class="card-info"><h3>Available</h3><div class="stat-value"><?= $acc_available ?> / <?= $acc_total ?></div></div></a>
            <a class="dashboard-card" href="reservations.php?tab=reservations"><div class="card-info"><h3>Verified Payments</h3><div class="stat-value"><?= peso($total_revenue) ?></div></div></a>
        </section>

        <div class="grid-2">
            <!-- Recent Reservations Table -->
            <section class="card">
                <div class="card-header-flex">
                    <h3>Recent Reservations</h3>
                    <a href="reservations.php" class="view-btn">View all</a>
                </div>
                <div class="table-wrap">
                <?php if ($recent && $recent->num_rows > 0): ?>
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
                                [$rtext, $badgeClass] = ui_status((string)$r["status"]);
                            ?>
                            <tr>
                                <td><a href="reservations.php?tab=reservations&amp;search=<?= (int)$r["id"] ?>" class="booking-id-link">#<?= (int)$r["id"] ?></a></td>
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
                    <?php
                    $bars = [
                        "Pending"   => [$pending_count,   "pending"],
                        "Confirmed" => [$confirmed_count, "confirmed"],
                        "Completed" => [$completed_count, "completed"],
                        "Cancelled" => [$cancelled_count, "cancelled"],
                    ];
                    if ($rejected_count > 0) $bars["Rejected"] = [$rejected_count, "rejected"];
                    foreach ($bars as $label => [$count, $filter]): ?>
                        <div class="bar-row">
                            <div class="bar-label">
                                <a href="reservations.php?tab=reservations&amp;status=<?= e($filter) ?>"><?= e($label) ?></a>
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
<script src="../js/app.js" data-base="../" data-role="admin"></script>
</body>
</html>