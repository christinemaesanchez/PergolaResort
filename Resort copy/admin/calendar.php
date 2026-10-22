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
/** held = waiting for payment/verification, confirmed = paid & confirmed, completed = finished stay */
function cal_state(string $status): string {
    if ($status === "confirmed") return "confirmed";
    if ($status === "completed") return "completed";
    return "held";   // pending, pending_payment_verification, payment_verification_failed
}

function cal_rank(string $state): int { 
    return ["completed" => 1, "held" => 2, "confirmed" => 3][$state] ?? 0; 
}

function cal_state_label(string $state): string { 
    return ["held" => "Held", "confirmed" => "Confirmed", "completed" => "Completed"][$state] ?? $state; 
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
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        <?= pay_badge_css() ?>
        .stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px; }
        .stat { background: #fff; border: 1px solid var(--border-color); border-radius: 10px; padding: 14px 16px; }
        .stat b { display: block; font-size: 24px; color: var(--primary-color); }
        .stat span { font-size: 12px; color: #666; }
        
        /* Toolbar & Horizontal Navigation Group */
        .toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 14px; margin-bottom: 14px; }
        .toolbar form { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .toolbar select { padding: 8px 12px; border: 1px solid var(--border-color); border-radius: 6px; background: #fff; font-size: 14px; }
        
        .cal-nav-group { display: inline-flex; align-items: center; gap: 8px; }

        .legend { display: flex; flex-wrap: wrap; gap: 16px; font-size: 12px; color: #555; margin-bottom: 14px; }
        .legend i { display: inline-block; width: 14px; height: 14px; border-radius: 3px; margin-right: 6px; vertical-align: -2px; }
        
        /* State Colours */
        .st-free { background: #eaf5e6; } 
        .st-held { background: #fff3cd; color: #8a6d00; }
        .st-confirmed { background: #f8d7da; color: #a02a35; } 
        .st-completed { background: #e4e4e4; color: #555; }
        
        /* Overview Matrix Table */
        .m-wrap { overflow-x: auto; }
        .m-table { border-collapse: separate; border-spacing: 2px; min-width: 100%; font-size: 12px; }
        .m-table th, .m-table td { text-align: center; padding: 0; height: 38px; min-width: 34px; }
        .m-table thead th { font-weight: 600; color: #666; height: auto; padding: 4px 0; }
        .m-table thead th small { display: block; font-weight: 400; font-size: 10px; color: #999; }
        .m-table .m-room { text-align: left; min-width: 170px; padding: 0 10px; position: sticky; left: 0; background: #fff; z-index: 1; font-weight: 600; }
        .m-table .m-room small { display: block; font-weight: 400; color: #888; font-size: 11px; }
        .m-table .m-room a { color: var(--primary-color); text-decoration: none; }
        .m-table .m-occ { min-width: 64px; font-size: 12px; color: #555; }
        .m-table td.m-free { background: #eaf5e6; border-radius: 4px; }
        .m-table td.m-book { border-radius: 4px; padding: 0 6px; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; max-width: 0; }
        .m-table td.m-book a { color: inherit; font-weight: 600; text-decoration: none; }
        .m-table .m-today { outline: 2px solid var(--primary-color); outline-offset: -2px; border-radius: 4px; }
        
        /* Single Room Grid */
        .g-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
        .g-head { text-align: center; font-weight: 600; padding: 10px; background: #f9f8f5; color: var(--primary-color); border-radius: 6px; }
        .g-cell { border: 1px solid var(--border-color); border-radius: 8px; min-height: 96px; padding: 8px; font-size: 12px; background: #fff; }
        .g-cell.empty { background: #fbfafa; opacity: .5; }
        .g-cell .dn { font-weight: 700; font-size: 13px; color: var(--primary-color); margin-bottom: 4px; }
        .g-cell.today { outline: 2px solid var(--primary-color); }
        .g-cell.s-free { background: #eaf5e6; } 
        .g-cell.s-held { background: #fff3cd; }
        .g-cell.s-confirmed { background: #f8d7da; } 
        .g-cell.s-completed { background: #ececec; }
        .g-cell a { color: inherit; font-weight: 600; text-decoration: none; display: block; }
        .g-cell .sub { font-size: 11px; opacity: .85; }
        .g-cell .out { margin-top: 4px; font-size: 10px; color: #555; border-top: 1px dashed #bbb; padding-top: 3px; }
        
        .alert-bar { padding: 12px 16px; margin-bottom: 16px; border-radius: 6px; background: #fff3cd; color: #8a6d00; }
        .alert-bar a { color: #8a6d00; font-weight: 700; }
        @media (max-width: 700px){ .g-cell { min-height: 70px; font-size: 10px; } }
    </style>
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
                <p>See which room or place is available, held, or booked, by day.</p>
            </div>
            <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
        </header>

        <!-- Payment Verification Alert Banner -->
        <?php if ($awaiting > 0): ?>
            <div class="alert-bar">⏳ <strong><?= $awaiting ?></strong> booking<?= $awaiting > 1 ? "s are" : " is" ?> held, waiting for payment verification.
                <a href="payments.php?status=pending">Review payments →</a></div>
        <?php endif; ?>

        <!-- Today's Snapshot Metrics Row -->
        <div class="stat-row">
            <div class="stat"><b><?= $avail_today ?>/<?= $total_rooms ?></b><span>Available today</span></div>
            <div class="stat"><b><?= $conf_today ?></b><span>Occupied (confirmed) today</span></div>
            <div class="stat"><b><?= $held_today ?></b><span>Held (unverified) today</span></div>
            <div class="stat"><b><?= $checkins_today ?></b><span>Check-ins today</span></div>
            <div class="stat"><b><?= $checkouts_today ?></b><span>Check-outs today</span></div>
        </div>

        <section class="card content-card full-width-card">
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

            <h3 style="margin-bottom: 10px;"><?= e(date("F Y", $first_ts)) ?> &middot; <?= $acc_id ? e($rooms[$acc_id]["name"]) : "All rooms / places" ?></h3>

            <!-- Color Legend -->
            <div class="legend">
                <span><i class="st-free" style="border: 1px solid #cfe3c7"></i>Available</span>
                <span><i class="st-held"></i>Held (pending payment / verification)</span>
                <span><i class="st-confirmed"></i>Confirmed (paid)</span>
                <span><i class="st-completed"></i>Completed</span>
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
                                [$rtext] = res_status_info((string)$r["status"]);
                                $tip = "#{$r["id"]} " . $r["full_name"] . " | " . $r["ci"] . " to " . $r["co"] . " | " . $rtext
                                     . " | " . strtoupper((string)($r["pay_method"] ?? "no payment")) . " " . ($r["pay_status"] ?? "");
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
            <p class="sub-text" style="margin-top: 10px;">Click a room name to open its month calendar. Click a guest to open the reservation. A check-out day is free for the next guest.</p>

            <?php else: ?>
            <!-- =============================================================== -->
            <!-- SINGLE ROOM GRID: Month calendar layout                         -->
            <!-- =============================================================== -->
            <?php
                $room = $rooms[$acc_id];
                $nights = count($occ[$acc_id] ?? []);
                $available_nights = max($days_in_month - $nights, 0);
            ?>
            <p class="sub-text" style="margin-bottom: 12px;">
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
                        <?php if ($r): [$rtext] = res_status_info((string)$r["status"]); ?>
                            <a href="reservations.php?search=<?= (int)$r["id"] ?>" title="<?= e($r["full_name"]) ?>"><?= e(first_name((string)$r["full_name"])) ?></a>
                            <div class="sub"><?= e(cal_state_label($state)) ?><?= $r["ci"] === sprintf("%04d-%02d-%02d", $year, $month, $d) ? " &middot; Check-in" : "" ?></div>
                            <div class="sub"><?= e(strtoupper((string)($r["pay_method"] ?? ""))) ?></div>
                        <?php else: ?>
                            <div class="sub" style="color: #2e6b2e;">Available</div>
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
        <section class="card content-card full-width-card" style="margin-top: 24px;">
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
                        [$rtext, $rcls] = res_status_info((string)$r["status"]);
                        $has_pay = $r["pay_status"] !== null;
                        if ($has_pay) {
                            [$ptext, $pcls] = pay_status_info((string)$r["pay_status"]);
                        }
                    ?>
                        <tr>
                            <td><a href="reservations.php?search=<?= (int)$r["id"] ?>">#<?= (int)$r["id"] ?></a></td>
                            <td><?= e($rooms[(int)$r["accommodation_id"]]["name"] ?? "Room #" . $r["accommodation_id"]) ?></td>
                            <td><strong><?= e($r["full_name"]) ?></strong></td>
                            <td><?= e(date("M d, Y", strtotime($r["ci"]))) ?></td>
                            <td><?= e(date("M d, Y", strtotime($r["co"]))) ?></td>
                            <td><span class="pbadge <?= e($rcls) ?>"><?= e($rtext) ?></span></td>
                            <td>
                                <?php if ($has_pay): ?>
                                    <?= e(strtoupper((string)$r["pay_method"])) ?> <span class="pbadge <?= e($pcls) ?>"><?= e($ptext) ?></span>
                                <?php else: ?>
                                    <span class="sub-text">No payment</span>
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
</body>
</html>