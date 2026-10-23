<?php
ob_start();
// =========================================================================
// 1. CONNECTION + SESSION INITIALIZATION
// =========================================================================
require_once "../config.php";

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
// 3. HELPERS & SCHEMA CHECK
// =========================================================================
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function peso($n) { return "₱" . number_format((float)$n, 2); }

function ensure_user_status_column($conn) {
    $res = $conn->query("SHOW COLUMNS FROM users LIKE 'status'");
    if ($res && $res->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
    } else {
        // Ensure it's VARCHAR so 'deleted' is permitted even if it was an ENUM
        $conn->query("ALTER TABLE users MODIFY COLUMN status VARCHAR(20) DEFAULT 'active'");
    }
}
ensure_user_status_column($conn);

// Valid ID files live in /uploads/ids/ at the project root; this page is inside /admin/
function id_url($p) {
    $p = trim((string)$p);
    if ($p === "") return "";
    if (preg_match('#^https?://#i', $p)) return $p;
    if (strpos($p, "..") !== false) return "";          // never allow path traversal
    return "../" . ltrim($p, "/");
}
function is_image_path($p) { return (bool)preg_match('/\.(jpe?g|png|webp|gif)$/i', (string)$p); }
function status_class($s) {
    $s = strtolower((string)$s);
    if (in_array($s, ["confirmed", "completed", "paid"], true)) return "ok";
    if (in_array($s, ["cancelled", "rejected", "payment_verification_failed"], true)) return "bad";
    return "wait";
}

// =========================================================================
// 4. ACTIONS: DELETE (SOFT), RESTORE, PURGE (PERMANENT)
// =========================================================================
if (isset($_GET["action"], $_GET["id"])) {
    $action = $_GET["action"];
    $target_id = (int)$_GET["id"];
    $search_redirect = trim($_GET["search"] ?? "");
    $redirect_url = "customers.php" . ($search_redirect !== "" ? "?search=" . urlencode($search_redirect) : "");

    if ($target_id > 0) {
        if ($action === "delete") {
            $st = $conn->prepare("UPDATE users SET status = 'deleted' WHERE id = ? AND role = 'customer'");
            if ($st) {
                $st->bind_param("i", $target_id);
                $st->execute();
                $st->close();
            }
        } elseif ($action === "restore") {
            $st = $conn->prepare("UPDATE users SET status = 'active' WHERE id = ? AND role = 'customer'");
            if ($st) {
                $st->bind_param("i", $target_id);
                $st->execute();
                $st->close();
            }
        } elseif ($action === "purge") {
            $st = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'customer'");
            if ($st) {
                $st->bind_param("i", $target_id);
                $st->execute();
                $st->close();
            }
        }
        
        if (ob_get_length()) ob_clean();
        header("Location: " . $redirect_url);
        exit();
    }
}

// =========================================================================
// 5. SEARCH & FILTER
// =========================================================================
$search_query = trim($_GET["search"] ?? "");

// Active Customers Query
$sql = "SELECT u.id, u.full_name, u.email, u.phone, u.valid_id, u.created_at,
               COUNT(r.id) AS total_reservations,
               COALESCE(SUM(CASE WHEN r.status <> 'cancelled' THEN r.total_amount ELSE 0 END), 0) AS total_spent
        FROM users u
        LEFT JOIN reservations r ON r.user_id = u.id
        WHERE u.role = 'customer' AND (u.status IS NULL OR u.status = 'active')";

$params = [];
$types = "";

if (!empty($search_query)) {
    $sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$sql .= " GROUP BY u.id ORDER BY u.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$customers = $stmt->get_result();
$stmt->close();

// Deleted Customers Query
$del_sql = "SELECT u.id, u.full_name, u.email, u.phone, u.valid_id, u.created_at,
                   COUNT(r.id) AS total_reservations,
                   COALESCE(SUM(CASE WHEN r.status <> 'cancelled' THEN r.total_amount ELSE 0 END), 0) AS total_spent
            FROM users u
            LEFT JOIN reservations r ON r.user_id = u.id
            WHERE u.role = 'customer' AND u.status = 'deleted'";

if (!empty($search_query)) {
    $del_sql .= " AND (u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $del_stmt = $conn->prepare($del_sql);
    $del_stmt->bind_param($types, ...$params);
    $del_stmt->execute();
    $deleted_customers = $del_stmt->get_result();
    $del_stmt->close();
} else {
    $del_sql .= " GROUP BY u.id ORDER BY u.created_at DESC";
    $deleted_customers = $conn->query($del_sql);
}

// =========================================================================
// 6. CUSTOMER PROFILE (opened with ?profile=ID)
// =========================================================================
$profile = null;
$p_stats = ["c" => 0, "s" => 0];
$p_recent = [];
$view_id = (int)($_GET["profile"] ?? 0);

if ($view_id > 0) {
    $ps = $conn->prepare("SELECT id, full_name, email, phone, address, valid_id, created_at, status
                          FROM users WHERE id = ? AND role = 'customer' LIMIT 1");
    $ps->bind_param("i", $view_id);
    $ps->execute();
    $profile = $ps->get_result()->fetch_assoc();
    $ps->close();

    if ($profile) {
        $st = $conn->prepare("SELECT COUNT(*) AS c,
                                     COALESCE(SUM(CASE WHEN status <> 'cancelled' THEN total_amount ELSE 0 END), 0) AS s
                              FROM reservations WHERE user_id = ?");
        $st->bind_param("i", $view_id);
        $st->execute();
        $p_stats = $st->get_result()->fetch_assoc() ?: $p_stats;
        $st->close();

        try {
            $rs = $conn->prepare("SELECT r.id, r.check_in, r.check_out, r.total_amount, r.status,
                                         a.name AS accommodation_name
                                  FROM reservations r
                                  LEFT JOIN accommodations a ON a.id = r.accommodation_id
                                  WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 5");
            $rs->bind_param("i", $view_id);
            $rs->execute();
            $rr = $rs->get_result();
            while ($row = $rr->fetch_assoc()) $p_recent[] = $row;
            $rs->close();
        } catch (Throwable $ex) { $p_recent = []; }
    }
}

// Links that keep the current search
function list_url($extra = []) {
    global $search_query;
    $q = array_filter(array_merge(["search" => $search_query], $extra), function ($v) { return $v !== "" && $v !== null; });
    return "customers.php" . ($q ? "?" . http_build_query($q) : "");
}

$current = "customers";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Directory | Pergola Resort Admin</title>
    <link rel="stylesheet" href="../css/admin.css">
    <style>
        /* FORCE HIDE ALL SIDEBAR ICONS & BELLS */
        .sidebar-nav a i, .sidebar-nav a svg, .sidebar-nav span i,
        .sb-toggle, .sidebar-collapse-btn, .ui-ico,
        .nf-wrap, .nf-admin, .topbar-bell, .notification-bell, .topbar-icons { 
            display: none !important; 
        }

        /* ===== Action Buttons Styling ===== */
        .btn-delete { background: #fbeae8; color: #a02a35; border: 1px solid #f5c6cb; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-block; transition: background 0.2s; cursor: pointer; }
        .btn-delete:hover { background: #f8d7da; }

        .btn-restore { background: #e8f4e8; color: #256030; border: 1px solid #c3e6cb; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-block; transition: background 0.2s; cursor: pointer; }
        .btn-restore:hover { background: #d4edda; }

        .btn-purge { background: #343a40; color: #fff; border: 1px solid #343a40; padding: 5px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; text-decoration: none; display: inline-block; transition: background 0.2s; cursor: pointer; }
        .btn-purge:hover { background: #23272b; }

        .section-divider { margin-top: 40px; }

        /* =====================================================
           CONFIRM / NOTIFICATION DIALOGS (Same as Reservations)
           ===================================================== */
        .modal-bg { display: none; position: fixed; inset: 0; background: rgba(245, 241, 232, .78); backdrop-filter: blur(3px); -webkit-backdrop-filter: blur(3px); z-index: 2000; align-items: center; justify-content: center; padding: 20px; }
        .modal-bg.open { display: flex; }
        .confirm-card {
            background: #fff;
            border: 1px solid #e3dec9;
            border-radius: 20px;
            padding: 38px 40px 34px;
            max-width: 480px;
            width: 100%;
            text-align: center;
            box-shadow: 0 18px 48px rgba(52, 74, 48, .14);
        }
        .confirm-card h3 { margin: 0 0 8px; font-size: 26px; font-weight: 800; color: #344a30; }
        .confirm-card p { margin: 0; font-size: 15.5px; line-height: 1.5; color: #6b6b6b; }
        .confirm-actions { display: flex; justify-content: center; gap: 12px; margin-top: 26px; flex-wrap: wrap; }
        .dlg-btn {
            height: 46px;
            padding: 0 28px;
            font-family: inherit;
            font-size: 15px;
            font-weight: 700;
            border-radius: 12px;
            border: 2px solid #3b4a32;
            cursor: pointer;
            transition: background-color .18s, color .18s, box-shadow .18s, transform .05s;
        }
        .dlg-btn:active { transform: translateY(1px); }
        .dlg-btn:focus-visible { outline: 3px solid rgba(111, 135, 97, .35); outline-offset: 2px; }
        .dlg-yes { background: #3b4a32; color: #fff; }
        .dlg-yes:hover { background: #2b3825; border-color: #2b3825; box-shadow: 0 4px 12px rgba(52, 74, 48, .25); }
        .dlg-no { background: #fff; color: #3b4a32; }
        .dlg-no:hover { background: #f0ede4; }
        .dlg-yes.danger { background: #9c2e25; border-color: #9c2e25; }
        .dlg-yes.danger:hover { background: #7f241d; border-color: #7f241d; }

        /* ===== Customer profile pop-up ===== */
        .pf-overlay { position: fixed; inset: 0; z-index: 2000; background: rgba(30, 42, 28, 0.55); display: flex; align-items: flex-start; justify-content: center; padding: 40px 16px; overflow-y: auto; }
        .pf-backdrop { position: fixed; inset: 0; cursor: default; }
        .pf-modal { position: relative; z-index: 1; width: 100%; max-width: 720px; background: #fff; border-radius: 14px; box-shadow: 0 20px 50px rgba(0,0,0,.25); overflow: hidden; }
        .pf-head { display: flex; align-items: center; gap: 16px; padding: 22px 26px; background: #f5f3eb; border-bottom: 1px solid #e6e1d3; }
        .pf-avatar { flex: 0 0 56px; width: 56px; height: 56px; border-radius: 50%; background: #344a30; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; font-weight: 700; text-transform: uppercase; }
        .pf-head h2 { font-size: 20px; color: #344a30; line-height: 1.25; margin: 0; }
        .pf-head p { font-size: 13px; color: #6b6f66; margin: 2px 0 0; }
        .pf-close { margin-left: auto; text-decoration: none; color: #344a30; font-size: 26px; line-height: 1; padding: 4px 10px; border-radius: 8px; }
        .pf-close:hover { background: #e6e1d3; }
        .pf-body { padding: 24px 26px 28px; }
        .pf-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 28px; margin-bottom: 24px; }
        .pf-grid .full { grid-column: 1 / -1; }
        .pf-label { font-size: 11px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: #6b6f66; margin-bottom: 3px; }
        .pf-value { font-size: 14.5px; color: #2c3e2b; overflow-wrap: anywhere; }
        .pf-muted { color: #8a8a80; font-style: italic; }
        .pf-section { margin-top: 6px; padding-top: 20px; border-top: 1px solid #e6e1d3; }
        .pf-section h3 { font-size: 15px; color: #344a30; margin: 0 0 12px; }
        .pf-id-box { background: #faf9f6; border: 1px solid #e6e1d3; border-radius: 10px; padding: 16px; }
        .pf-id-box img { max-width: 100%; max-height: 320px; border-radius: 8px; border: 1px solid #e6e1d3; display: block; margin-top: 12px; }
        .pf-pill { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
        .pf-pill.ok { background: #e8f4e8; color: #256030; }
        .pf-pill.bad { background: #fbeae8; color: #a02a35; }
        .pf-pill.wait { background: #fcf6df; color: #8a6d3b; }
        .pf-link { color: #3b6b3a; font-weight: 700; text-decoration: none; font-size: 13.5px; }
        .pf-link:hover { text-decoration: underline; }
        .pf-stats { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; margin-bottom: 18px; }
        .pf-stat { background: #faf9f6; border: 1px solid #e6e1d3; border-radius: 10px; padding: 14px 16px; }
        .pf-stat b { display: block; font-size: 22px; color: #344a30; margin-top: 2px; }
        .pf-table { width: 100%; border-collapse: collapse; font-size: 13.5px; }
        .pf-table th, .pf-table td { padding: 9px 8px; border-bottom: 1px solid #e6e1d3; text-align: left; }
        .pf-table th { font-size: 11px; text-transform: uppercase; letter-spacing: .5px; color: #6b6f66; }
        .pf-actions { display: flex; gap: 14px; justify-content: flex-end; align-items: center; margin-top: 22px; }
        @media (max-width: 600px) { .pf-grid, .pf-stats { grid-template-columns: 1fr; } .pf-overlay { padding: 16px 10px; } }
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
        <a href="customers.php" class="active">Customers</a>
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
            <span class="section-label">DIRECTORY</span>
            <h1>Customer Management</h1>
            <p>View registered accounts, client profiles, valid IDs, and lifetime booking value.</p>
        </div>
        <button class="menu-btn" onclick="document.getElementById('sidebar').classList.toggle('open')">Menu</button>
    </header>

    <!-- Search Bar Card -->
    <section class="card filter-card">
        <form method="GET" action="customers.php" class="filter-form">
            <div class="form-group-inline search-group" style="flex: 2;">
                <label for="search">Search Customer</label>
                <input type="text" name="search" id="search" placeholder="Search by name, email or contact number..." value="<?= e($search_query) ?>">
            </div>
            <div class="form-group-inline btn-group">
                <button type="submit" class="admin-btn primary">Search Records</button>
                <a href="customers.php" class="admin-btn secondary">Reset</a>
            </div>
        </form>
    </section>

    <!-- Active Customers List Table -->
    <section class="card content-card full-width-card">
        <div class="card-header-flex">
            <h3>Registered Customers</h3>
            <span class="record-count"><?= $customers->num_rows ?> active accounts</span>
        </div>
        <div class="table-wrap">
        <?php if ($customers->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer Name</th>
                        <th>Email Address</th>
                        <th>Contact Number</th>
                        <th>Valid ID</th>
                        <th>Member Since</th>
                        <th>Total Bookings</th>
                        <th>Lifetime Spend</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $customers->fetch_assoc()): ?>
                    <tr>
                        <td>#<?= (int)$row["id"] ?></td>
                        <td><strong><?= e($row["full_name"]) ?></strong></td>
                        <td><span class="sub-text"><?= e($row["email"]) ?></span></td>
                        <td><?= trim((string)$row["phone"]) !== "" ? e($row["phone"]) : '<span class="sub-text">Not provided</span>' ?></td>
                        <td><?= !empty($row["valid_id"]) ? '<span class="pf-pill ok">Uploaded</span>' : '<span class="pf-pill bad">Missing</span>' ?></td>
                        <td><?= e(date("M d, Y", strtotime($row["created_at"]))) ?></td>
                        <td><?= (int)$row["total_reservations"] ?> Bookings</td>
                        <td><strong><?= peso($row["total_spent"]) ?></strong></td>
                        <td style="white-space:nowrap;">
                            <a href="<?= e(list_url(["profile" => (int)$row["id"]])) ?>"><strong>View Profile</strong></a>
                            &nbsp;|&nbsp;
                            <a href="reservations.php?tab=reservations&amp;user=<?= (int)$row["id"] ?>">Reservations</a>
                            &nbsp;|&nbsp;
                            <a href="customers.php?action=delete&amp;id=<?= (int)$row["id"] ?><?= !empty($search_query) ? '&amp;search=' . urlencode($search_query) : '' ?>" class="btn-delete" data-confirm-title="Delete customer?" data-confirm-text="Are you sure you want to move customer #<?= (int)$row["id"] ?> (<?= e($row["full_name"]) ?>) to archive?" data-confirm-yes="Yes, delete" data-confirm-no="Cancel">Delete</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="empty">No active customer accounts found matching your search.</p>
        <?php endif; ?>
        </div>
    </section>

    <!-- Deleted Customers Section -->
    <section class="card content-card full-width-card section-divider">
        <div class="card-header-flex">
            <h3 style="color: #a02a35;">Deleted / Archived Customers</h3>
            <span class="record-count"><?= $deleted_customers->num_rows ?> archived accounts</span>
        </div>
        <div class="table-wrap">
        <?php if ($deleted_customers->num_rows > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Customer Name</th>
                        <th>Email Address</th>
                        <th>Contact Number</th>
                        <th>Member Since</th>
                        <th>Total Bookings</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($drow = $deleted_customers->fetch_assoc()): ?>
                    <tr>
                        <td>#<?= (int)$drow["id"] ?></td>
                        <td><strong><?= e($drow["full_name"]) ?></strong></td>
                        <td><span class="sub-text"><?= e($drow["email"]) ?></span></td>
                        <td><?= trim((string)$drow["phone"]) !== "" ? e($drow["phone"]) : '<span class="sub-text">Not provided</span>' ?></td>
                        <td><?= e(date("M d, Y", strtotime($drow["created_at"]))) ?></td>
                        <td><?= (int)$drow["total_reservations"] ?> Bookings</td>
                        <td style="white-space:nowrap;">
                            <a href="customers.php?action=restore&amp;id=<?= (int)$drow["id"] ?><?= !empty($search_query) ? '&amp;search=' . urlencode($search_query) : '' ?>" class="btn-restore" data-confirm-title="Restore customer?" data-confirm-text="Are you sure you want to restore customer #<?= (int)$drow["id"] ?> (<?= e($drow["full_name"]) ?>)?" data-confirm-yes="Yes, restore" data-confirm-no="Cancel">Restore</a>
                            &nbsp;
                            <a href="customers.php?action=purge&amp;id=<?= (int)$drow["id"] ?><?= !empty($search_query) ? '&amp;search=' . urlencode($search_query) : '' ?>" class="btn-purge" data-confirm-title="Permanently delete?" data-confirm-text="WARNING: This will permanently delete customer #<?= (int)$drow["id"] ?> from the database. This action cannot be undone." data-confirm-yes="Yes, permanently delete" data-confirm-no="Cancel" data-danger="true">Delete Permanently</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="empty">No deleted customer accounts.</p>
        <?php endif; ?>
        </div>
    </section>

</main>

<?php if ($view_id > 0): ?>
<!-- ============ CUSTOMER PROFILE POP-UP ============ -->
<div class="pf-overlay" id="pfOverlay" role="dialog" aria-modal="true" aria-label="Customer profile">
    <a class="pf-backdrop" href="<?= e(list_url()) ?>" aria-label="Close"></a>
    <div class="pf-modal">
    <?php if ($profile):
        $id_url = id_url($profile["valid_id"] ?? "");
        $initial = mb_substr(trim((string)$profile["full_name"]) !== "" ? trim($profile["full_name"]) : "?", 0, 1);
    ?>
        <div class="pf-head">
            <div class="pf-avatar"><?= e($initial) ?></div>
            <div>
                <h2><?= e($profile["full_name"]) ?></h2>
                <p>Customer #<?= (int)$profile["id"] ?> &middot; Member since <?= e(date("M d, Y", strtotime($profile["created_at"]))) ?>
                   <?php if (($profile["status"] ?? 'active') === 'deleted'): ?>
                       &middot; <strong style="color: #a02a35;">[ARCHIVED/DELETED]</strong>
                   <?php endif; ?>
                </p>
            </div>
            <a class="pf-close" href="<?= e(list_url()) ?>" aria-label="Close">&times;</a>
        </div>

        <div class="pf-body">
            <div class="pf-grid">
                <div>
                    <div class="pf-label">Email address</div>
                    <div class="pf-value"><a class="pf-link" href="mailto:<?= e($profile["email"]) ?>"><?= e($profile["email"]) ?></a></div>
                </div>
                <div>
                    <div class="pf-label">Contact number</div>
                    <div class="pf-value">
                        <?php if (trim((string)$profile["phone"]) !== ""): ?>
                            <a class="pf-link" href="tel:<?= e(preg_replace('/[^0-9+]/', '', $profile["phone"])) ?>"><?= e($profile["phone"]) ?></a>
                        <?php else: ?><span class="pf-muted">Not provided</span><?php endif; ?>
                    </div>
                </div>
                <div class="full">
                    <div class="pf-label">Home address</div>
                    <div class="pf-value">
                        <?= trim((string)($profile["address"] ?? "")) !== "" ? nl2br(e($profile["address"])) : '<span class="pf-muted">Not provided</span>' ?>
                    </div>
                </div>
            </div>

            <!-- Valid ID -->
            <div class="pf-section">
                <h3>Valid ID Verification</h3>
                <div class="pf-id-box">
                    <?php if ($id_url !== ""): ?>
                        <span class="pf-pill ok">ID uploaded</span>
                        &nbsp; <a class="pf-link" href="<?= e($id_url) ?>" target="_blank" rel="noopener">Open full size &rarr;</a>
                        <?php if (is_image_path($profile["valid_id"])): ?>
                            <a href="<?= e($id_url) ?>" target="_blank" rel="noopener"><img src="<?= e($id_url) ?>" alt="Valid ID of <?= e($profile["full_name"]) ?>" loading="lazy"></a>
                        <?php else: ?>
                            <p class="pf-muted" style="margin-top:10px;">This ID is a PDF file. Use &ldquo;Open full size&rdquo; to view it.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="pf-pill bad">No valid ID uploaded</span>
                        <p class="pf-muted" style="margin-top:10px;">The customer has not uploaded a government-issued ID yet.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Booking summary -->
            <div class="pf-section">
                <h3>Booking Summary</h3>
                <div class="pf-stats">
                    <div class="pf-stat"><div class="pf-label">Total bookings</div><b><?= (int)$p_stats["c"] ?></b></div>
                    <div class="pf-stat"><div class="pf-label">Lifetime spend</div><b><?= peso($p_stats["s"]) ?></b></div>
                </div>

                <?php if (!empty($p_recent)): ?>
                    <div style="overflow-x:auto;">
                    <table class="pf-table">
                        <thead><tr><th>ID</th><th>Accommodation</th><th>Stay</th><th>Amount</th><th>Status</th></tr></thead>
                        <tbody>
                        <?php foreach ($p_recent as $b): ?>
                            <tr>
                                <td>#<?= (int)$b["id"] ?></td>
                                <td><?= e($b["accommodation_name"] ?? "—") ?></td>
                                <td><?= e(date("M d", strtotime($b["check_in"]))) ?> &ndash; <?= e(date("M d, Y", strtotime($b["check_out"]))) ?></td>
                                <td><?= peso($b["total_amount"]) ?></td>
                                <td><span class="pf-pill <?= status_class($b["status"]) ?>"><?= e(ucwords(str_replace("_", " ", (string)$b["status"]))) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php else: ?>
                    <p class="pf-muted">This customer has no reservations yet.</p>
                <?php endif; ?>
            </div>

            <div class="pf-actions">
                <a class="pf-link" href="reservations.php?tab=reservations&amp;user=<?= (int)$profile["id"] ?>">View all reservations &rarr;</a>
                <a class="admin-btn secondary" href="<?= e(list_url()) ?>">Close</a>
            </div>
        </div>
    <?php else: ?>
        <div class="pf-head">
            <div><h2>Customer not found</h2><p>This account doesn't exist or isn't a customer.</p></div>
            <a class="pf-close" href="<?= e(list_url()) ?>" aria-label="Close">&times;</a>
        </div>
        <div class="pf-body"><a class="admin-btn secondary" href="<?= e(list_url()) ?>">Back to customers</a></div>
    <?php endif; ?>
    </div>
</div>
<script>
// Esc closes the profile pop-up
document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape') window.location.href = <?= json_encode(list_url()) ?>;
});
document.body.style.overflow = 'hidden';
</script>
<?php endif; ?>

<!-- Confirm Dialog (Custom Modal Notifications matching Reservations page) -->
<div id="confirmModal" class="modal-bg" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
    <div class="confirm-card">
        <h3 id="confirmTitle">Are you sure?</h3>
        <p id="confirmText"></p>
        <div class="confirm-actions">
            <button type="button" class="dlg-btn dlg-yes" id="confirmYes">Yes</button>
            <button type="button" class="dlg-btn dlg-no" id="confirmNo">Cancel</button>
        </div>
    </div>
</div>

<script>
    var confirmEl = document.getElementById('confirmModal');
    var confirmOnYes = null, confirmOnNo = null;

    function askConfirm(opt, onYes, onNo) {
        document.getElementById('confirmTitle').textContent = opt.title || 'Are you sure?';
        document.getElementById('confirmText').textContent = opt.text || '';
        
        var yesBtn = document.getElementById('confirmYes');
        yesBtn.textContent = opt.yes || 'Yes';
        
        if (opt.danger) {
            yesBtn.classList.add('danger');
        } else {
            yesBtn.classList.remove('danger');
        }

        document.getElementById('confirmNo').textContent = opt.no || 'Cancel';
        confirmOnYes = onYes || null;
        confirmOnNo = onNo || null;
        confirmEl.classList.add('open');
        yesBtn.focus();
    }

    function closeConfirm(accepted) {
        confirmEl.classList.remove('open');
        var cb = accepted ? confirmOnYes : confirmOnNo;
        confirmOnYes = confirmOnNo = null;
        if (cb) cb();
    }

    document.getElementById('confirmYes').addEventListener('click', function () { closeConfirm(true); });
    document.getElementById('confirmNo').addEventListener('click', function () { closeConfirm(false); });
    confirmEl.addEventListener('click', function (ev) { if (ev.target === confirmEl) closeConfirm(false); });

    // Intercept delete, restore, and purge links to trigger the custom styled dialog
    document.querySelectorAll('[data-confirm-title]').forEach(function (btn) {
        btn.addEventListener('click', function (ev) {
            ev.preventDefault();
            var targetUrl = btn.getAttribute('href');
            askConfirm({
                title: btn.dataset.confirmTitle,
                text: btn.dataset.confirmText,
                yes: btn.dataset.confirmYes,
                no: btn.dataset.confirmNo,
                danger: btn.dataset.danger === 'true'
            }, function () {
                window.location.href = targetUrl;
            });
        });
    });

    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && confirmEl.classList.contains('open')) {
            closeConfirm(false);
        }
    });
</script>
<script src="../js/app.js" data-base="../" data-role="admin"></script>
</body>
</html>
<?php
ob_end_flush();
?>