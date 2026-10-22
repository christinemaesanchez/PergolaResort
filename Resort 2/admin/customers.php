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

// ---------- 4. SEARCH & FILTER ----------
$search_query = trim($_GET["search"] ?? "");

$sql = "SELECT u.id, u.full_name, u.email, u.phone, u.valid_id, u.created_at,
               COUNT(r.id) AS total_reservations,
               COALESCE(SUM(CASE WHEN r.status <> 'cancelled' THEN r.total_amount ELSE 0 END), 0) AS total_spent
        FROM users u
        LEFT JOIN reservations r ON r.user_id = u.id
        WHERE u.role = 'customer'";

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

// ---------- 5. CUSTOMER PROFILE (opened with ?profile=ID) ----------
$profile = null;
$p_stats = ["c" => 0, "s" => 0];
$p_recent = [];
$view_id = (int)($_GET["profile"] ?? 0);

if ($view_id > 0) {
    $ps = $conn->prepare("SELECT id, full_name, email, phone, address, valid_id, created_at
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

    <!-- Customers List Table -->
    <section class="card content-card full-width-card">
        <div class="card-header-flex">
            <h3>Registered Customers</h3>
            <span class="record-count"><?= $customers->num_rows ?> total accounts</span>
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
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="empty">No customer accounts found matching your search.</p>
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
                <p>Customer #<?= (int)$profile["id"] ?> &middot; Member since <?= e(date("M d, Y", strtotime($profile["created_at"]))) ?></p>
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

</body>
</html>