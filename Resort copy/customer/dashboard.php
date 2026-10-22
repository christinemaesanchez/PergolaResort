<?php
// ---------- 1. CONNECTION + SESSION ----------
require_once "../config.php";
require_once "../payment_lib.php";   // auth_guard(), notifications, shared helpers
$auth_user = auth_guard($conn, "customer", "../login.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- 2. CUSTOMER AUTHENTICATION ----------
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "customer") {
    header("Location: ../login.php");
    exit();
}

$customer_id = (int)$_SESSION["user_id"];

// ---------- 3. FETCH CUSTOMER INFO ----------
$cust_stmt = $conn->prepare("SELECT full_name, email, created_at FROM users WHERE id = ? LIMIT 1");
$cust_stmt->bind_param("i", $customer_id);
$cust_stmt->execute();
$customer = $cust_stmt->get_result()->fetch_assoc();
$cust_stmt->close();

if (!$customer) {
    header("Location: ../logout.php");
    exit();
}

// ---------- 4. FETCH BOOKING STATS & RECENT BOOKINGS ----------
$stats_stmt = $conn->prepare("SELECT
        (SELECT COUNT(*) FROM reservations WHERE user_id = ?) AS total_bookings,
        (SELECT COALESCE(SUM(p.amount), 0)
            FROM payments p
            JOIN reservations r ON r.id = p.reservation_id
            WHERE r.user_id = ? AND p.status = 'paid') AS total_paid,
        (SELECT COUNT(*)
            FROM reservations r
            JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
            WHERE r.user_id = ? AND r.status <> 'cancelled' AND p.status = 'pending') AS pending_payments,
        (SELECT COUNT(*)
            FROM reservations r
            JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
            WHERE r.user_id = ? AND r.status <> 'cancelled' AND p.status = 'rejected') AS rejected_payments");
$stats_stmt->bind_param("iiii", $customer_id, $customer_id, $customer_id, $customer_id);
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// Next upcoming stay
$next_stmt = $conn->prepare(
    "SELECT r.check_in, a.name AS accommodation_name
     FROM reservations r
     JOIN accommodations a ON a.id = r.accommodation_id
     WHERE r.user_id = ? AND r.status IN ('pending', 'confirmed', 'pending_payment_verification')
       AND r.check_in >= CURDATE()
     ORDER BY r.check_in ASC LIMIT 1"
);
$next_stmt->bind_param("i", $customer_id);
$next_stmt->execute();
$next_stay = $next_stmt->get_result()->fetch_assoc();
$next_stmt->close();

// Recent 5 bookings
$book_stmt = $conn->prepare(
    "SELECT r.id, r.check_in, r.check_out, r.total_amount, r.status, r.created_at, a.name AS accommodation_name,
            p.status AS pay_status
     FROM reservations r 
     JOIN accommodations a ON a.id = r.accommodation_id 
     LEFT JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
     WHERE r.user_id = ? 
     ORDER BY r.created_at DESC LIMIT 5"
);
$book_stmt->bind_param("i", $customer_id);
$book_stmt->execute();
$recent_bookings = $book_stmt->get_result();
$book_stmt->close();

$resort_name = "Pergola Resort";
$res_info = $conn->query("SELECT resort_name FROM resort_info LIMIT 1");
if ($res_info && $res_info->num_rows > 0) {
    $resort_name = $res_info->fetch_assoc()["resort_name"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Dashboard | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="../css/customer.css">
    <link rel="stylesheet" href="../css/ui.css">
    <style>
        <?= pay_badge_css() ?>

        /* HIDE NOTIFICATION BELL, ICON BOXES & EXTRA TOGGLES */
        .nf-wrap, .nf-bell, .nf-customer, .topbar-bell, .notification-bell, .nav-links i, .nav-links svg {
            display: none !important;
        }

        /* BLUE ACTION LINKS */
        .link-view, .blue-link {
            color: #0047ff !important;
            font-weight: 700 !important;
            font-size: 13px;
            text-decoration: none !important;
        }
        .link-view:hover, .blue-link:hover {
            text-decoration: underline !important;
        }

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

        /* WHITESPACE & SECTION HEADERS */
        .section-title {
            border-bottom: 1px solid #ede8dc;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        /* MICRO-INTERACTIONS: TABLE ROWS HOVER STATES */
        .table-wrap table tbody tr {
            transition: background-color 0.2s ease;
        }
        .table-wrap table tbody tr:hover {
            background-color: #f7f5ee !important;
        }

        /* Merged Next Stay Card */
        .next-stay-card {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 24px;
            flex-wrap: wrap;
            border: 1px solid #ede8dc;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .next-stay-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(52, 74, 48, 0.08);
        }
        .next-stay-card .next-stay-info h3 { margin: 0; }
        .next-stay-card .next-stay-info p { margin: 0; }
        .next-stay-card .book-btn {
            width: auto;
            min-width: 200px;
            text-align: center;
            white-space: nowrap;
            background: #6f8761;
            color: #ffffff;
            padding: 10px 22px;
            border-radius: 8px;
            font-weight: 700;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .next-stay-card .book-btn:hover {
            background: #526b49;
            transform: translateY(-1px);
        }

        /* Empty state for Recent Bookings */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            padding: 36px 20px 40px;
        }
        .empty-state .empty-icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #eef2ea;
            color: #344a30;
            margin-bottom: 16px;
        }
        .empty-state h4 {
            margin: 0 0 6px;
            font-size: 18px;
            color: #344a30;
        }
        .empty-state p {
            margin: 0 0 22px;
            max-width: 380px;
            font-size: 14px;
            line-height: 1.6;
            color: #666;
        }
        .browse-btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 26px;
            background: #6f8761;
            border: 1px solid #526b49;
            border-radius: 8px;
            color: #fff;
            font-size: 14px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(52, 74, 48, 0.15);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }
        .browse-btn:hover {
            background: #526b49;
            box-shadow: 0 6px 16px rgba(52, 74, 48, 0.25);
            transform: translateY(-1px);
        }

        @media (max-width: 600px) {
            .next-stay-card { flex-direction: column; align-items: stretch; }
            .next-stay-card .book-btn { width: 100%; }
            .browse-btn { width: 100%; justify-content: center; }
        }
    </style>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('.nf-wrap, .nf-bell, .notification-bell').forEach(el => el.remove());
        });
    </script>
</head>
<body>

<!-- ============ NAVIGATION BAR ============ -->
<header class="navbar">
    <div class="nav-container">
        <a href="home.php" class="nav-brand">
            <h2><?= e($resort_name) ?></h2>
        </a>
        <nav class="nav-links">
            <a href="home.php">Home</a>
            <a href="../accommodations.php">Accommodations</a>
            <a href="dashboard.php" class="active">Dashboard</a>
            <a href="my_bookings.php">My Bookings</a>
            <a href="profile.php">Profile</a>
            <a href="../logout.php" class="nav-btn-outline">Sign Out</a>
        </nav>
    </div>
</header>

<!-- ============ MAIN CONTENT ============ -->
<main class="container" style="padding: 40px 20px;">
    
    <div class="section-title">
        <h1>Welcome back, <?= e($customer["full_name"] ?? "Guest") ?>!</h1>
        <p>Manage your resort reservations and view your booking summary below.</p>
    </div>

    <?php if ((int)$stats["rejected_payments"] > 0): ?>
        <div style="padding:14px 18px; margin-bottom:25px; border-radius:10px; background:#fbeae8; color:#9c2e25; font-size:14px; border:1px solid #f5c7c2;">
            <strong>Action needed:</strong>
            <?= (int)$stats["rejected_payments"] ?> payment<?= (int)$stats["rejected_payments"] > 1 ? "s were" : " was" ?> rejected.
            <a href="my_bookings.php" style="color:#9c2e25; font-weight:700; text-decoration:underline; margin-left:6px;">Upload a new receipt in My Bookings &rarr;</a>
        </div>
    <?php endif; ?>

    <!-- Quick Stats Cards -->
    <div class="grid-3" style="margin-bottom: 30px;">
        <div class="card" style="padding: 20px;">
            <h3>Total Bookings</h3>
            <p style="font-size: 24px; font-weight: 700; margin-top: 10px; color: #344a30;"><?= (int)$stats["total_bookings"] ?></p>
        </div>
        <div class="card" style="padding: 20px;">
            <h3>Total Paid (Verified)</h3>
            <p style="font-size: 24px; font-weight: 700; margin-top: 10px; color: #344a30;"><?= peso($stats["total_paid"]) ?></p>
        </div>
        <div class="card" style="padding: 20px;">
            <h3>Awaiting Verification</h3>
            <p style="font-size: 24px; font-weight: 700; margin-top: 10px; color: #344a30;"><?= (int)$stats["pending_payments"] ?></p>
        </div>

        <!-- Next Stay + Book New Stay (merged card) -->
        <div class="card next-stay-card" style="padding: 20px;">
            <div class="next-stay-info">
                <h3 style="color:#777; font-size:12px; text-transform:uppercase; letter-spacing:0.5px;">Next Stay</h3>
                <?php if ($next_stay): ?>
                    <p style="font-size: 20px; font-weight: 700; margin-top: 6px; color: #344a30;"><?= e(date("M d, Y", strtotime($next_stay["check_in"]))) ?></p>
                    <p style="font-size: 13px; color: #666; margin-top:2px;"><?= e($next_stay["accommodation_name"]) ?></p>
                <?php else: ?>
                    <p style="font-size: 14px; margin-top: 6px; color: #666; font-weight:600;">No upcoming stay yet.</p>
                <?php endif; ?>
            </div>
            <a href="../accommodations.php" class="book-btn">Book New Stay</a>
        </div>
    </div>

    <!-- Recent Bookings Table -->
    <div class="card" style="padding: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="font-size: 18px; color:#344a30;">Recent Bookings</h3>
            <a href="my_bookings.php" class="blue-link" style="font-size: 14px;">View All</a>
        </div>
        
        <div class="table-wrap">
        <?php if ($recent_bookings && $recent_bookings->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Booking ID</th>
                        <th>Accommodation</th>
                        <th>Check-In</th>
                        <th>Check-Out</th>
                        <th>Amount</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $recent_bookings->fetch_assoc()):
                        [$rtext, $rcls] = res_status_info((string)$row["status"]);
                        
                        // Map status to standard color-coded badges
                        $resBadgeClass = 'b-pending';
                        if ($row["status"] === 'confirmed') $resBadgeClass = 'b-confirmed';
                        elseif ($row["status"] === 'completed') $resBadgeClass = 'b-completed';
                        elseif ($row["status"] === 'cancelled') $resBadgeClass = 'b-cancelled';

                        $payBadgeClass = 'b-pending';
                        if (isset($row["pay_status"])) {
                            [$ptext, $pcls] = pay_status_info((string)$row["pay_status"]);
                            if ($row["pay_status"] === 'paid' || $row["pay_status"] === 'verified') $payBadgeClass = 'b-confirmed';
                            elseif ($row["pay_status"] === 'rejected' || $row["pay_status"] === 'failed') $payBadgeClass = 'b-cancelled';
                        } else {
                            $ptext = "Unpaid";
                        }
                    ?>
                    <tr>
                        <td><a href="my_bookings.php#res-<?= (int)$row["id"] ?>" class="blue-link">#<?= (int)$row["id"] ?></a></td>
                        <td><strong><?= e($row["accommodation_name"]) ?></strong></td>
                        <td><?= e(date("M d, Y", strtotime($row["check_in"]))) ?></td>
                        <td><?= e(date("M d, Y", strtotime($row["check_out"]))) ?></td>
                        <td><strong style="color:#344a30;"><?= peso($row["total_amount"]) ?></strong></td>
                        <td><span class="badge <?= $payBadgeClass ?>"><?= e($ptext) ?></span></td>
                        <td><span class="badge <?= $resBadgeClass ?>"><?= e($rtext) ?></span></td>
                        <td>
                            <a class="blue-link" href="my_bookings.php#res-<?= (int)$row["id"] ?>">View</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="17" rx="2"></rect>
                        <path d="M16 2v4M8 2v4M3 10h18"></path>
                    </svg>
                </div>
                <h4>No bookings yet</h4>
                <p>You have no recent bookings. Reserve a stay and it will appear here.</p>
                <a href="../accommodations.php" class="browse-btn">
                    <span>Browse Accommodations Now</span>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"></path></svg>
                </a>
            </div>
        <?php endif; ?>
        </div>
    </div>

</main>

<!-- ============ FOOTER ============ -->
<footer class="footer">
    <div class="container">
        <p>&copy; <?= date("Y") ?> <?= e($resort_name) ?>. All rights reserved.</p>
    </div>
</footer>

<script src="../js/app.js" data-base="../" data-role="customer"></script>
</body>
</html>