<?php
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";   // e(), peso(), res_status_info(), pay_status_info(), pay_badge_css()

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

// =========================================================================
// 3. HELPER FUNCTIONS
// =========================================================================
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

function cal_state(string $status): string {
    if ($status === "confirmed") return "confirmed";
    if ($status === "completed") return "completed";
    return "held";   // pending, pending_payment_verification, payment_verification_failed
}

function cal_rank(string $state): int { 
    return ["completed" => 1, "held" => 2, "confirmed" => 3][$state] ?? 0; 
}

function cal_state_label(string $state): string { 
    return ["held" => "Pending", "confirmed" => "Confirmed", "completed" => "Completed"][$state] ?? $state; 
}

function cal_url(int $m, int $y, int $acc): string { 
    return "calendar.php?month=$m&year=$y" . ($acc > 0 ? "&acc=$acc" : ""); 
}

function first_name(string $full): string { 
    return explode(" ", trim($full))[0] ?: $full; 
}

// =========================================================================
// 4. MONTH / YEAR / ROOM CALCULATIONS
// =========================================================================
$month = isset($_GET["month"]) ? (int)$_GET["month"] : (int)date("m");
$year  = isset($_GET["year"])  ? (int)$_GET["year"]  : (int)date("Y");

if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$first_ts      = mktime(0, 0, 0, $month, 1, $year);
$days_in_month = (int)date("t", $first_ts);
$start_dow     = (int)date("w", $first_ts);
$month_start   = sprintf("%04d-%02d-01", $year, $month);
$month_end     = sprintf("%04d-%02d-%02d", $year, $month, $days_in_month);
$today         = date("Y-m-d");
$today_day     = ($today >= $month_start && $today <= $month_end) ? (int)date("j") : 0;

$prev_month = $month - 1; 
$prev_year = $year; 
if ($prev_month < 1) { 
    $prev_month = 12; 
    $prev_year--; 
}

$next_month = $month + 1; 
$next_year = $year; 
if ($next_month > 12) { 
    $next_month = 1;  
    $next_year++; 
}

// =========================================================================
// 5. ACCOMMODATIONS / ROOMS LIST
// =========================================================================
$rooms = [];
$rr = $conn->query("SELECT * FROM accommodations ORDER BY name ASC");
while ($row = $rr->fetch_assoc()) {
    $rooms[(int)$row["id"]] = $row;
}

$acc_id = (int)($_GET["acc"] ?? 0);
if (!isset($rooms[$acc_id])) {
    $acc_id = 0; // 0 = all rooms overview
}

// =========================================================================
// 6. FETCH RESERVATIONS TOUCHING THIS MONTH
// =========================================================================
$sql = "SELECT r.id, r.accommodation_id, r.check_in, r.check_out, r.status, r.total_amount,
               u.full_name, p.method AS pay_method, p.status AS pay_status
        FROM reservations r
        JOIN users u ON u.id = r.user_id
        LEFT JOIN payments p ON p.id = (SELECT MAX(id) FROM payments WHERE reservation_id = r.id)
        WHERE r.status <> 'cancelled' AND DATE(r.check_in) <= ? AND DATE(r.check_out) >= ?";

$params = [$month_end, $month_start];
$types  = "ss";

if ($acc_id > 0) { 
    $sql .= " AND r.accommodation_id = ?"; 
    $params[] = $acc_id; 
    $types .= "i"; 
}

$sql .= " ORDER BY r.check_in ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();

$occ  = []; // $occ[room_id][day] = reservation occupying that NIGHT
$outs = []; // $outs[room_id][day][] = reservations checking out that day
$list = [];

while ($r = $res->fetch_assoc()) {
    $r["ci"] = substr((string)$r["check_in"], 0, 10);
    $r["co"] = substr((string)$r["check_out"], 0, 10);
    $r["state"] = cal_state((string)$r["status"]);
    $list[] = $r;
    
    $aid = (int)$r["accommodation_id"];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $iso = sprintf("%04d-%02d-%02d", $year, $month, $d);
        if ($iso >= $r["ci"] && $iso < $r["co"]) {
            $cur = $occ[$aid][$d] ?? null;
            if (!$cur || cal_rank($r["state"]) > cal_rank($cur["state"])) {
                $occ[$aid][$d] = $r;
            }
        }
        if ($iso === $r["co"]) {
            $outs[$aid][$d][] = $r;
        }
    }
}
$stmt->close();

// =========================================================================
// 7. TODAY'S SNAPSHOT METRICS
// =========================================================================
$total_rooms = count($rooms);
$busy_today = [];
$tq = $conn->query("SELECT accommodation_id, status FROM reservations
                    WHERE status <> 'cancelled' AND DATE(check_in) <= CURDATE() AND DATE(check_out) > CURDATE()");

while ($t = $tq->fetch_assoc()) {
    $aid = (int)$t["accommodation_id"];
    $st = cal_state((string)$t["status"]);
    if (!isset($busy_today[$aid]) || cal_rank($st) > cal_rank($busy_today[$aid])) {
        $busy_today[$aid] = $st;
    }
}

$held_today      = count(array_filter($busy_today, fn($s) => $s === "held"));
$conf_today      = count(array_filter($busy_today, fn($s) => $s !== "held"));
$avail_today     = max($total_rooms - count($busy_today), 0);
$checkins_today  = (int)$conn->query("SELECT COUNT(*) c FROM reservations WHERE status <> 'cancelled' AND DATE(check_in) = CURDATE()")->fetch_assoc()["c"];
$checkouts_today = (int)$conn->query("SELECT COUNT(*) c FROM reservations WHERE status <> 'cancelled' AND DATE(check_out) = CURDATE()")->fetch_assoc()["c"];
$awaiting        = (int)$conn->query("SELECT COUNT(*) c FROM reservations WHERE status = 'pending_payment_verification'")->fetch_assoc()["c"];

$current = "calendar";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Calendar | Pergola Resort Admin</title>
    <!-- Connected directly to external admin.css for a clean & uniform UI -->
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

        /* UNIFORM DASHBOARD CARDS SIZING */
        .dashboard-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        .dashboard-card {
            background: #fff;
            border: 1px solid #ede8dc;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: center;
            min-height: 90px;
        }
        .dashboard-card h3 {
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #777;
            margin-bottom: 6px;
            font-weight: 700;
        }
        .dashboard-card .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #344a30;
        }

        /* Toolbar & Horizontal Navigation Group */
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-bottom: 20px; }
        .toolbar form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .toolbar select { padding: 9px 14px; border: 1px solid #e3dec9; border-radius: 8px; background: #f7f5ee; color: #344a30; font-size: 13.5px; outline: none; }
        .toolbar select:focus { border-color: #6f8761; background: white; }
        
        .cal-nav-group { display: inline-flex; align-items: center; gap: 8px; }

        .legend { display: flex; flex-wrap: wrap; gap: 20px; font-size: 12.5px; color: #555; margin-bottom: 20px; }
        .legend i { display: inline-block; width: 14px; height: 14px; border-radius: 4px; margin-right: 6px; vertical-align: -2px; }
        
        /* State Colours */
        .st-free { background: #e5f4e7; } 
        .st-held { background: #fdf5df; color: #8a6a10; }
        .st-confirmed { background: #e5f4e7; color: #256030; } 
        .st-completed { background: #e9f0f8; color: #28537d; }
        
        /* Overview Matrix Table */
        .m-wrap { overflow-x: auto; }
        .m-table { border-collapse: separate; border-spacing: 3px; min-width: 100%; font-size: 12.5px; }
        .m-table th, .m-table td { text-align: center; padding: 0; height: 42px; min-width: 36px; }
        .m-table thead th { font-weight: 600; color: #526b49; background: #f7f5ee; height: auto; padding: 6px 0; border-radius: 6px; }
        .m-table thead th small { display: block; font-weight: 400; font-size: 10.5px; color: #888; }
        .m-table .m-room { text-align: left; min-width: 190px; padding: 0 12px; position: sticky; left: 0; background: #fff; z-index: 1; font-weight: 600; border-radius: 6px; border: 1px solid #ede8dc; }
        .m-table .m-room small { display: block; font-weight: 400; color: #777; font-size: 11px; }
        .m-table .m-room a { color: #344a30; text-decoration: none; }
        .m-table .m-room a:hover { color: #6f8761; text-decoration: underline; }
        .m-table .m-occ { min-width: 75px; font-size: 12.5px; color: #444; font-weight: 600; }
        .m-table td.m-free { background: #f7f5ee; border-radius: 6px; border: 1px solid #eef2eb; }
        .m-table td.m-book { border-radius: 6px; padding: 0 8px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; max-width: 0; font-weight: 600; }
        .m-table td.m-book a { color: inherit; text-decoration: none; }
        .m-table .m-today { outline: 2px solid #6f8761; outline-offset: -2px; border-radius: 6px; }
        
        /* Single Room Grid */
        .g-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 10px; }
        .g-head { text-align: center; font-weight: 700; padding: 12px; background: #f7f5ee; color: #526b49; border-radius: 8px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; }
        .g-cell { border: 1px solid #ede8dc; border-radius: 10px; min-height: 110px; padding: 10px; font-size: 12.5px; background: #fff; box-shadow: 0 2px 6px rgba(52, 74, 48, 0.02); }
        .g-cell.empty { background: #faf9f5; opacity: .4; }
        .g-cell .dn { font-weight: 700; font-size: 14px; color: #344a30; margin-bottom: 6px; }
        .g-cell.today { outline: 2px solid #6f8761; }
        .g-cell.s-free { background: #faf9f5; } 
        .g-cell.s-held { background: #fdf5df; border-color: #f5e2b8; }
        .g-cell.s-confirmed { background: #e5f4e7; border-color: #c2e2c6; } 
        .g-cell.s-completed { background: #e9f0f8; border-color: #c9ddec; }
        .g-cell a { color: #344a30; font-weight: 600; text-decoration: none; display: block; }
        .g-cell a:hover { text-decoration: underline; }
        .g-cell .sub { font-size: 11.5px; opacity: .85; margin-top: 2px; }
        .g-cell .out { margin-top: 6px; font-size: 11px; color: #666; border-top: 1px dashed #d5d0c0; padding-top: 4px; }
        @media (max-width: 700px){ .g-cell { min-height: 80px; font-size: 11px; } }
    </style>
    <script>
        document.addEventListener("DOMContentLoaded", () => {
            document.querySelectorAll('.sidebar-nav a i, .sidebar-nav a svg, .sb-toggle, .nf-wrap').forEach(el => el.remove());
        });
        try{if(localStorage.getItem("pergolaSidebar")==="1"&&window.innerWidth>768)document.documentElement.classList.add("sb-collapsed")}catch(e){}
    </script>
</head>
<body>

    <!-- =================================================================== -->
    <!-- SIDE NAVIGATION BAR                                                 -->
    <!-- =================================================================== -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <h2>Pergola</h2>
            <p>Resort Management</p>
        </div>
        <nav class="sidebar-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="reservations.php">Reservations</a>
            <a href="calendar.php" class="active">Calendar</a>
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

        <!-- Header -->
        <header class="topbar">
            <div class="topbar-title">
                <span class="section-label">SCHEDULE</span>
                <h1>Reservation Calendar</h1>
                <p>See which room or place is available, pending, or booked, by day.</p>
            </div>
            <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
        </header>

        <!-- Payment Verification Alert Banner -->
        <?php if ($awaiting > 0): ?>
            <div class="message error-message">
                You have <strong><?= $awaiting ?></strong> payment(s) waiting for verification.
                <a href="reservations.php?tab=reservations&amp;status=pending" style="color: inherit; text-decoration: underline; margin-left: 8px;">Review payments</a>
            </div>
        <?php endif; ?>

        <!-- Uniform Summary Cards Grid -->
        <section class="dashboard-cards">
            <div class="dashboard-card"><h3>Available Today</h3><div class="stat-value"><?= $avail_today ?> / <?= $total_rooms ?></div></div>
            <div class="dashboard-card"><h3>Confirmed Today</h3><div class="stat-value"><?= $conf_today ?></div></div>
            <div class="dashboard-card"><h3>Pending Today</h3><div class="stat-value"><?= $held_today ?></div></div>
            <div class="dashboard-card"><h3>Check-ins Today</h3><div class="stat-value"><?= $checkins_today ?></div></div>
            <div class="dashboard-card"><h3>Check-outs Today</h3><div class="stat-value"><?= $checkouts_today ?></div></div>
        </section>

        <section class="card content-card full-width-card" style="margin-top: 25px;">
            <!-- Navigation Toolbar & Filters -->
            <div class="toolbar">
                <div class="cal-nav-group">
                    <a href="<?= e(cal_url($prev_month, $prev_year, $acc_id)) ?>" class="admin-btn secondary">&laquo; Prev</a>
                    <a href="calendar.php<?= $acc_id ? "?acc=$acc_id" : "" ?>" class="admin-btn secondary">Today</a>
                    <a href="<?= e(cal_url($next_month, $next_year, $acc_id)) ?>" class="admin-btn secondary">Next &raquo;</a>
                </div>
                <form method="GET" action="calendar.php">
                    <select name="acc" onchange="this.form.submit()" aria-label="Room or place">
                        <option value="0">All rooms / places (overview)</option>
                        <?php foreach ($rooms as $rid => $rm): ?>
                            <option value="<?= $rid ?>" <?= $rid === $acc_id ? "selected" : "" ?>><?= e($rm["name"]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="month" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $month ? "selected" : "" ?>><?= date("F", mktime(0, 0, 0, $m, 1)) ?></option>
                        <?php endfor; ?>
                    </select>
                    <select name="year" onchange="this.form.submit()">
                        <?php for ($y = (int)date("Y") - 2; $y <= (int)date("Y") + 3; $y++): ?>
                            <option value="<?= $y ?>" <?= $y === $year ? "selected" : "" ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                    <button type="submit" class="admin-btn primary">Go</button>
                </form>
            </div>

            <h3 style="margin-bottom: 12px; color: #344a30;"><?= e(date("F Y", $first_ts)) ?> &middot; <?= $acc_id ? e($rooms[$acc_id]["name"]) : "All rooms / places" ?></h3>

            <!-- Color Legend -->
            <div class="legend">
                <span><i class="st-free" style="border: 1px solid #c2e2c6;"></i>Available</span>
                <span><i class="st-held" style="border: 1px solid #f5e2b8;"></i>Held (pending payment / verification)</span>
                <span><i class="st-confirmed" style="border: 1px solid #c2e2c6;"></i>Confirmed (paid)</span>
                <span><i class="st-completed" style="border: 1px solid #c9ddec;"></i>Completed</span>
            </div>

            <?php if (!$rooms): ?>
                <p class="empty">No accommodations yet. Add some under Accommodations.</p>

            <?php elseif ($acc_id === 0): ?>
            <!-- =============================================================== -->
            <!-- OVERVIEW MATRIX: One row per room, one column per day           -->
            <!-- =============================================================== -->
            <div class="m-wrap">
                <table class="m-table">
                    <thead>
                        <tr>
                            <th class="m-room">Room / Place</th>
                            <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
                                <th class="<?= $d === $today_day ? "m-today" : "" ?>"><?= $d ?><small><?= ["Su","Mo","Tu","We","Th","Fr","Sa"][(int)date("w", mktime(0, 0, 0, $month, $d, $year))] ?></small></th>
                            <?php endfor; ?>
                            <th class="m-occ">Booked</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rooms as $rid => $rm):
                        $nights = count($occ[$rid] ?? []);
                        $pct = $days_in_month ? round(($nights / $days_in_month) * 100) : 0;
                    ?>
                        <tr>
                            <th class="m-room">
                                <a href="<?= e(cal_url($month, $year, $rid)) ?>"><?= e($rm["name"]) ?></a>
                                <small><?= (int)($rm["capacity"] ?? 0) ?> pax &middot; <?= peso($rm["price"] ?? 0) ?>/night</small>
                            </th>
                            <?php
                            $d = 1;
                            while ($d <= $days_in_month):
                                $r = $occ[$rid][$d] ?? null;
                                if (!$r):
                            ?>
                                <td class="m-free<?= $d === $today_day ? " m-today" : "" ?>" title="Available"></td>
                            <?php
                                    $d++;
                                    continue;
                                endif;
                                $run = 1;
                                while ($d + $run <= $days_in_month && isset($occ[$rid][$d + $run]) && (int)$occ[$rid][$d + $run]["id"] === (int)$r["id"]) {
                                    $run++;
                                }
                                [$rtext] = ui_status((string)$r["status"]);
                                $tip = "#{$r["id"]} " . $r["full_name"] . " | " . $r["ci"] . " to " . $r["co"] . " | " . $rtext
                                     . " | " . strtoupper((string)($r["pay_method"] ?? "no payment")) . (isset($r["pay_status"]) ? " " . ui_status((string)$r["pay_status"])[0] : "");
                            ?>
                                <td class="m-book st-<?= e($r["state"]) ?>" colspan="<?= $run ?>" title="<?= e($tip) ?>">
                                    <a href="reservations.php?search=<?= (int)$r["id"] ?>"><?= e(first_name((string)$r["full_name"])) ?></a>
                                </td>
                            <?php
                                $d += $run;
                            endwhile;
                            ?>
                            <td class="m-occ"><?= $nights ?>/<?= $days_in_month ?> (<?= $pct ?>%)</td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="sub-text" style="margin-top: 12px;">Click a room name to open its month calendar. Click a guest to open the reservation. A check-out day is free for the next guest.</p>

            <?php else: ?>
            <!-- =============================================================== -->
            <!-- SINGLE ROOM GRID: Month calendar layout                         -->
            <!-- =============================================================== -->
            <?php
                $room = $rooms[$acc_id];
                $nights = count($occ[$acc_id] ?? []);
                $available_nights = max($days_in_month - $nights, 0);
            ?>
            <p class="sub-text" style="margin-bottom: 15px;">
                <?= (int)($room["capacity"] ?? 0) ?> pax &middot; <?= peso($room["price"] ?? 0) ?>/night &middot;
                <strong><?= $nights ?></strong> of <?= $days_in_month ?> nights booked, <strong><?= $available_nights ?></strong> available this month
            </p>
            <div class="g-grid">
                <?php foreach (["Sun","Mon","Tue","Wed","Thu","Fri","Sat"] as $h): ?><div class="g-head"><?= $h ?></div><?php endforeach; ?>
                <?php for ($i = 0; $i < $start_dow; $i++): ?><div class="g-cell empty"></div><?php endfor; ?>
                <?php for ($d = 1; $d <= $days_in_month; $d++):
                    $r = $occ[$acc_id][$d] ?? null;
                    $state = $r ? $r["state"] : "free";
                ?>
                    <div class="g-cell s-<?= e($state) ?><?= $d === $today_day ? " today" : "" ?>">
                        <div class="dn"><?= $d ?></div>
                        <?php if ($r): ?>
                            <a href="reservations.php?search=<?= (int)$r["id"] ?>" title="<?= e($r["full_name"]) ?>"><?= e(first_name((string)$r["full_name"])) ?></a>
                            <div class="sub"><?= e(cal_state_label($state)) ?><?= $r["ci"] === sprintf("%04d-%02d-%02d", $year, $month, $d) ? " &middot; Check-in" : "" ?></div>
                            <div class="sub" style="color: #344a30; font-weight: 700;"><?= e(strtoupper((string)($r["pay_method"] ?? ""))) ?></div>
                        <?php else: ?>
                            <div class="sub" style="color: #2e6b2e; font-weight: 600;">Available</div>
                        <?php endif; ?>
                        <?php foreach ($outs[$acc_id][$d] ?? [] as $o): ?>
                            <div class="out">Out: <?= e(first_name((string)$o["full_name"])) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
        </section>

        <!-- =================================================================== -->
        <!-- BOOKINGS LIST FOR THE MONTH TABLE                                   -->
        <!-- =================================================================== -->
        <section class="card content-card full-width-card" style="margin-top: 25px;">
            <div class="card-header-flex">
                <h3>Bookings in <?= e(date("F Y", $first_ts)) ?></h3>
                <span class="record-count"><?= count($list) ?> records</span>
            </div>
            <div class="table-wrap">
            <?php if ($list): ?>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Room / Place</th>
                            <th>Guest</th>
                            <th>Check-in</th>
                            <th>Check-out</th>
                            <th>Status</th>
                            <th>Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($list as $r):
                        [$rtext, $resBadgeClass] = ui_status((string)$r["status"]);
                        $has_pay = $r["pay_status"] !== null;
                        $ptext = ""; $payBadgeClass = "b-pending";
                        if ($has_pay) { [$ptext, $payBadgeClass] = ui_status((string)$r["pay_status"]); }
                    ?>
                        <tr>
                            <td><a href="reservations.php?search=<?= (int)$r["id"] ?>" class="booking-id-link">#<?= (int)$r["id"] ?></a></td>
                            <td><?= e($rooms[(int)$r["accommodation_id"]]["name"] ?? "Room #" . $r["accommodation_id"]) ?></td>
                            <td><strong><?= e($r["full_name"]) ?></strong></td>
                            <td><?= e(date("M d, Y", strtotime($r["ci"]))) ?></td>
                            <td><?= e(date("M d, Y", strtotime($r["co"]))) ?></td>
                            <td><span class="badge <?= $resBadgeClass ?>"><?= e($rtext) ?></span></td>
                            <td>
                                <?php if ($has_pay): ?>
                                    <div style="display: flex; align-items: center; gap: 10px; white-space: nowrap;">
                                        <strong style="color: #344a30; font-weight: 700;"><?= e(strtoupper((string)$r["pay_method"])) ?></strong> 
                                        <span class="badge <?= $payBadgeClass ?>"><?= e($ptext) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="sub-text">No payment record</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p class="empty">No bookings in this month<?= $acc_id ? " for this room" : "" ?>.</p>
            <?php endif; ?>
            </div>
        </section>

    </main>
<script src="../js/app.js" data-base="../" data-role="admin"></script>
<script src="../js/responsive.js"></script>
</body>
</html>