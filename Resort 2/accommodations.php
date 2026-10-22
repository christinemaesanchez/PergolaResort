<?php
// =========================================================================
// 1. CONNECTION + SESSION
// =========================================================================
require_once "config.php";
require_once "payment_lib.php";
 
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
 
// =========================================================================
// 2. FETCH RESORT INFO & ACCOMMODATIONS
// =========================================================================
$resort_name = "Pergola Resort";
$res_info = $conn->query("SELECT resort_name FROM resort_info LIMIT 1");
if ($res_info && $res_info->num_rows > 0) {
    $resort_name = $res_info->fetch_assoc()["resort_name"];
}
 
ensure_payment_schema($conn);

$has_status = ($conn->query("SHOW COLUMNS FROM accommodations LIKE 'status'")->num_rows > 0);
$status_where = $has_status ? "WHERE status = 'active' OR status IS NULL" : "";
$accommodations = $conn->query("SELECT * FROM accommodations $status_where ORDER BY is_available DESC, id DESC");
$addons = $conn->query("SELECT * FROM resort_addons ORDER BY id ASC");
 
// =========================================================================
// 3. HELPERS
// =========================================================================
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('peso')) {
    function peso($n) { return "₱" . number_format((float)$n, 2); }
}
 
$is_logged_in = !empty($_SESSION["user_id"]);
$is_customer = ($is_logged_in && ($_SESSION["role"] ?? "") === "customer");
$is_admin = ($is_logged_in && ($_SESSION["role"] ?? "") === "admin");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accommodations & Fees | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="css/customer.css">
    <link rel="stylesheet" href="css/ui.css">
    <style>
        /* HIDE NOTIFICATION BELLS & EXTRA ICONS */
        .nf-wrap, .nf-bell, .notification-bell, .nav-links i, .nav-links svg {
            display: none !important;
        }

        .thumb-gallery { display: flex; gap: 6px; margin-bottom: 12px; }
        .thumb-gallery img { width: 65px; height: 50px; object-fit: cover; border-radius: 6px; border: 1px solid #e3dec9; cursor: pointer; transition: opacity 0.2s; }
        .thumb-gallery img:hover { opacity: 0.8; }
        
        .addon-box { background: #f7f5ee; border: 1px solid #ede8dc; border-radius: 12px; padding: 25px; margin-top: 40px; box-shadow: 0 4px 20px rgba(52, 74, 48, 0.02); }
        .addon-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; margin-top: 15px; }
        .addon-item { background: #fff; padding: 18px; border-radius: 10px; border: 1px solid #ede8dc; box-shadow: 0 2px 6px rgba(52, 74, 48, 0.03); transition: transform 0.2s ease; }
        .addon-item:hover { transform: translateY(-2px); }

        /* Accommodation Card Polish & Equal Height Structure */
        .card {
            border: 1px solid #ede8dc !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04) !important;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: #ffffff;
        }
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(52, 74, 48, 0.08) !important;
        }

        /* Section Header */
        .section-title {
            border-bottom: 1px solid #ede8dc;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        /* Pill status badges */
        .status-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.3px;
            text-transform: uppercase;
            margin-bottom: 12px;
        }
        .status-badge.available { background: #e5f4e7; color: #256030; }
        .status-badge.unavailable { background: #f0ede4; color: #666; }

        /* Button override */
        .primary-btn {
            background: #6f8761 !important;
            border: 1px solid #526b49 !important;
            border-radius: 8px !important;
            font-weight: 700 !important;
            transition: all 0.2s ease !important;
        }
        .primary-btn:hover {
            background: #526b49 !important;
            transform: translateY(-1px);
        }
    </style>
</head>
<body>
 
<!-- ============ NAVIGATION BAR ============ -->
<header class="navbar">
    <div class="nav-container">
        <a href="customer/home.php" class="nav-brand">
            <h2><?= e($resort_name) ?></h2>
        </a>
        <nav class="nav-links">
            <a href="customer/home.php">Home</a>
            <a href="accommodations.php" class="active">Accommodations</a>
            <?php if ($is_customer): ?>
                <a href="customer/dashboard.php">Dashboard</a>
                <a href="customer/my_bookings.php">My Bookings</a>
                <a href="customer/profile.php">Profile</a>
                <a href="logout.php" class="nav-btn-outline">Sign Out</a>
            <?php elseif ($is_admin): ?>
                <a href="admin/dashboard.php">Admin Panel</a>
                <a href="logout.php" class="nav-btn-outline">Sign Out</a>
            <?php else: ?>
                <a href="login.php" class="nav-btn-outline">Sign In</a>
                <a href="register.php" class="btn primary-btn" style="padding: 8px 20px;">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
 
<!-- ============ MAIN CONTENT ============ -->
<main class="container" style="padding: 40px 20px;">
 
    <div class="section-title">
        <h1>Our Accommodations & Resort Fees</h1>
        <p>Explore our comfortable rooms, suites, cottages, and available resort add-on fees.</p>
    </div>
 
    <div class="grid-3">
        <?php if ($accommodations && $accommodations->num_rows > 0): ?>
            <?php while ($row = $accommodations->fetch_assoc()):
                $imgs = json_decode($row["image_urls"] ?? "[]", true);
                if (empty($imgs) && !empty($row["image"])) { $imgs = [$row["image"]]; }
                $main_img = !empty($imgs) ? $imgs[0] : "";
                $is_open = ((int)($row["is_available"] ?? 1) === 1);
            ?>
                <div class="card">
                    <?php if ($main_img !== ""): ?>
                        <div id="main-img-<?= (int)$row["id"] ?>" style="width: 100%; height: 200px; background-image: url('<?= e($main_img) ?>'); background-size: cover; background-position: center; transition: background-image 0.3s;"></div>
                    <?php else: ?>
                        <div style="width: 100%; height: 200px; background: #f7f5ee; display: flex; align-items: center; justify-content: center; color: #777; font-size: 13.5px; border-bottom: 1px solid #ede8dc;">No Image Available</div>
                    <?php endif; ?>

                    <div style="padding: 20px; display: flex; flex-direction: column; flex-grow: 1; justify-content: space-between;">
                        <div>
                            <?php if (count($imgs) > 1): ?>
                                <div class="thumb-gallery">
                                    <?php foreach ($imgs as $img_path): ?>
                                        <img src="<?= e($img_path) ?>" onclick="document.getElementById('main-img-<?= (int)$row["id"] ?>').style.backgroundImage = 'url(\'<?= e($img_path) ?>\')'" alt="Thumbnail">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <h3 style="font-size: 18px; color: #344a30; margin-bottom: 6px; font-weight: 700;"><?= e($row["name"]) ?></h3>
                            <p style="font-size: 16px; color: #344a30; margin-bottom: 8px;"><strong><?= peso($row["price"]) ?></strong> / night</p>
                            <p style="font-size: 13px; color: #666; margin-bottom: 4px;">Type: <?= e($row["type"]) ?></p>
                            <p style="font-size: 13px; color: #666; margin-bottom: 12px;">Max Capacity: <?= (int)$row["capacity"] ?> Persons</p>

                            <?php if (trim((string)$row["description"]) !== ""): ?>
                                <p style="font-size: 13.5px; color: #555; margin-bottom: 15px; line-height: 1.5;"><?= e($row["description"]) ?></p>
                            <?php endif; ?>
                        </div>

                        <div>
                            <div style="margin-bottom: 12px;">
                                <?php if ($is_open): ?>
                                    <span class="status-badge available">Available</span>
                                <?php else: ?>
                                    <span class="status-badge unavailable">Currently unavailable</span>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_open): ?>
                                <a href="book.php?id=<?= (int)$row["id"] ?>" class="btn primary-btn full-width" style="text-align: center; text-decoration: none; padding: 10px 0; display: block;">Book Now</a>
                            <?php else: ?>
                                <span class="btn full-width" style="text-align: center; background: #f0ede4; color: #888; cursor: not-allowed; border: none; padding: 10px 0; display: block; border-radius: 8px;">Not available for booking</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p style="text-align: center; grid-column: 1 / -1; color: #666; padding: 40px;">No accommodations available at the moment.</p>
        <?php endif; ?>
    </div>

    <!-- Optional Add-ons & Fees Showcase Section -->
    <?php if ($addons && $addons->num_rows > 0): ?>
        <div class="addon-box">
            <h3 style="color: #344a30; font-size: 20px; margin-bottom: 6px; font-weight: 700;">Resort Add-ons & Optional Fees</h3>
            <p style="font-size: 13.5px; color: #666; margin-bottom: 0;">Enhance your stay with these optional amenities and add-on services.</p>
            <div class="addon-grid">
                <?php while ($ao = $addons->fetch_assoc()): ?>
                    <div class="addon-item">
                        <strong style="display: block; color: #344a30; font-size: 15px; margin-bottom: 4px;"><?= e($ao["name"]) ?></strong>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 14px; font-weight: 700; color: #444;"><?= peso($ao["price"]) ?></span>
                            <span style="font-size: 11px; color: #777; font-weight: 600; background: #f7f5ee; padding: 2px 8px; border-radius: 4px;"><?= $ao["type"] === 'per_night' ? 'Per Night' : 'One-time' ?></span>
                        </div>
                        <?php if (!empty($ao["description"])): ?>
                            <p style="font-size: 12.5px; color: #666; margin-top: 6px; margin-bottom: 0; line-height: 1.4;"><?= e($ao["description"]) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endwhile; ?>
            </div>
        </div>
    <?php endif; ?>
 
</main>
 
<!-- ============ FOOTER ============ -->
<footer class="footer">
    <div class="container">
        <p>&copy; <?= date("Y") ?> <?= e($resort_name) ?>. All rights reserved.</p>
    </div>
</footer>
 
</body>
</html>