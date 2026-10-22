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

// ---------- 4. SEARCH & FILTER ----------
$search_query = trim($_GET["search"] ?? "");

$sql = "SELECT u.id, u.full_name, u.email, u.phone, u.created_at, 
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

$current = "customers";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Directory | Pergola Resort Admin</title>
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
            <p>View registered accounts, client histories, and lifetime booking value.</p>
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
                        <th>Member Since</th>
                        <th>Total Bookings</th>
                        <th>Lifetime Spend</th>
                        <th>History</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($row = $customers->fetch_assoc()): ?>
                    <tr>
                        <td>#<?= (int)$row["id"] ?></td>
                        <td><strong><?= e($row["full_name"]) ?></strong></td>
                        <td><span class="sub-text"><?= e($row["email"]) ?></span></td>
                        <td><?= trim((string)$row["phone"]) !== "" ? e($row["phone"]) : '<span class="sub-text">Not provided</span>' ?></td>
                        <td><?= e(date("M d, Y", strtotime($row["created_at"]))) ?></td>
                        <td><?= (int)$row["total_reservations"] ?> Bookings</td>
                        <td><strong><?= peso($row["total_spent"]) ?></strong></td>
                        <td><a href="reservations.php?tab=reservations&amp;user=<?= (int)$row["id"] ?>">View Reservations</a></td>
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
</body>
</html>