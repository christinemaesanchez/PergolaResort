<?php
// =========================================================================
// 1. CONNECTION + SESSION + CUSTOMER GUARD (same as dashboard.php)
// =========================================================================
require_once "../config.php";
require_once "../payment_lib.php";
$auth_user = auth_guard($conn, "customer", "../login.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "customer") {
    header("Location: ../login.php");
    exit();
}
$customer_id = (int)$_SESSION["user_id"];

// =========================================================================
// 2. HELPERS
// =========================================================================
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('peso')) {
    function peso($n) { return "₱" . number_format((float)$n, 2); }
}
// Image paths are saved relative to the site root; this page lives in /customer
function img_src($p) {
    $p = trim((string)$p);
    if ($p === "" || preg_match('#^(https?:)?//#i', $p) || $p[0] === "/") return $p;
    return "../" . ltrim($p, "/");
}
// Only allow real web links
function safe_url($u) {
    $u = trim((string)$u);
    if ($u === "") return "";
    if (!preg_match('#^https?://#i', $u)) $u = "https://" . $u;
    return filter_var($u, FILTER_VALIDATE_URL) ? $u : "";
}

// =========================================================================
// 3. RESORT INFO (everything the admin saves in Resort Info shows here)
// =========================================================================
$resort = [
    "resort_name" => "Pergola Resort", "tagline" => "", "contact_number" => "", "email" => "",
    "location" => "", "description" => "", "facebook_page" => "", "social_links" => "",
];
$ri = $conn->query("SELECT * FROM resort_info LIMIT 1");
if ($ri && $ri->num_rows > 0) {
    $row_ri = $ri->fetch_assoc();
    foreach ($resort as $k => $v) {
        if (isset($row_ri[$k]) && trim((string)$row_ri[$k]) !== "") $resort[$k] = trim((string)$row_ri[$k]);
    }
}
$resort_name = $resort["resort_name"];
$tagline     = $resort["tagline"];
$phone       = $resort["contact_number"];
$phone_href  = preg_replace('/[^0-9+]/', '', $phone);
$email       = $resort["email"];
$location    = $resort["location"];
$about_text  = $resort["description"];
$fb_url      = safe_url($resort["facebook_page"]);
$social      = $resort["social_links"];
$social_url  = preg_match('#^https?://#i', $social) ? safe_url($social) : "";

// Customer name for the greeting
$first_name = "";
$cs = $conn->prepare("SELECT full_name FROM users WHERE id = ? LIMIT 1");
$cs->bind_param("i", $customer_id);
$cs->execute();
$cu = $cs->get_result()->fetch_assoc();
$cs->close();
if ($cu && !empty($cu["full_name"])) $first_name = trim(explode(" ", trim($cu["full_name"]))[0]);

// =========================================================================
// 4. FEATURED ACCOMMODATIONS
// =========================================================================
ensure_payment_schema($conn);
$has_status = ($conn->query("SHOW COLUMNS FROM accommodations LIKE 'status'")->num_rows > 0);
$has_avail  = ($conn->query("SHOW COLUMNS FROM accommodations LIKE 'is_available'")->num_rows > 0);
$status_where = $has_status ? "WHERE status = 'active' OR status IS NULL" : "";
$order = ($has_avail ? "is_available DESC, " : "") . "id DESC";
$accommodations = $conn->query("SELECT * FROM accommodations $status_where ORDER BY $order LIMIT 3");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="../css/customer.css">
    <link rel="stylesheet" href="../css/ui.css">
    <style>
        /* Same palette as dashboard.php and accommodations.php */
        :root {
            --g-dark: #344a30;
            --g-mid: #6f8761;
            --g-hover: #526b49;
            --line: #ede8dc;
            --soft: #f7f5ee;
            --muted: #666;
        }

        .nf-wrap, .nf-bell, .notification-bell, .nav-links i, .nav-links svg { display: none !important; }

        /* ===== Hero ===== */
        .hero {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 0;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid var(--g-dark);
            background: var(--g-dark);
            margin-bottom: 50px;
        }
        .hero-main { padding: 56px 48px; color: #fff; }
        .hero-hello { font-size: 14px; color: #cfdcc8; margin: 0 0 14px; }
        .hero-main h1 { font-size: 40px; line-height: 1.15; margin: 0 0 12px; font-weight: 800; color: #fff; }
        .hero-tag { font-size: 18px; color: #e9efe4; margin: 0 0 28px; max-width: 480px; line-height: 1.5; }
        .hero-actions { display: flex; gap: 12px; flex-wrap: wrap; }

        .hero-side { background: var(--soft); padding: 40px 34px; display: flex; flex-direction: column; justify-content: center; gap: 20px; }
        .hero-side h2 { font-size: 16px; color: var(--g-dark); margin: 0; font-weight: 700; }
        .fact { border-left: 3px solid var(--g-mid); padding-left: 14px; }
        .fact b { display: block; font-size: 12.5px; color: var(--muted); font-weight: 600; margin-bottom: 2px; }
        .fact span, .fact a { font-size: 14.5px; color: var(--g-dark); font-weight: 600; text-decoration: none; word-break: break-word; }
        .fact a:hover { text-decoration: underline; }

        /* ===== Buttons (same as dashboard / accommodations) ===== */
        .btn-solid, .btn-ghost {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 12px 26px; border-radius: 8px; font-size: 14px; font-weight: 700;
            letter-spacing: 0.3px; text-decoration: none; cursor: pointer; transition: all 0.2s ease;
        }
        .btn-solid { background: var(--g-mid); border: 1px solid var(--g-hover); color: #fff; box-shadow: 0 4px 12px rgba(52,74,48,.15); }
        .btn-solid:hover { background: var(--g-hover); transform: translateY(-1px); }
        .hero .btn-solid { background: #fff; border-color: #fff; color: var(--g-dark); }
        .hero .btn-solid:hover { background: var(--soft); }
        .btn-ghost { background: transparent; border: 1px solid rgba(255,255,255,.55); color: #fff; }
        .btn-ghost:hover { background: rgba(255,255,255,.12); }
        .btn-solid.block { display: flex; width: 100%; box-sizing: border-box; }

        /* ===== Sections ===== */
        .block-section { padding: 0 0 50px; }
        .block-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid var(--line); padding-bottom: 12px; margin-bottom: 24px; }
        .block-head h2 { margin: 0; font-size: 24px; color: var(--g-dark); font-weight: 700; }
        .block-head p { margin: 0; font-size: 14px; color: var(--muted); }
        .link-green { color: var(--g-mid); font-weight: 700; font-size: 14px; text-decoration: none; }
        .link-green:hover { color: var(--g-dark); text-decoration: underline; }

        .about-wrap { display: grid; grid-template-columns: 1.3fr 1fr; gap: 40px; align-items: start; }
        .about-wrap p { font-size: 15px; line-height: 1.75; color: #555; margin: 0 0 14px; max-width: 62ch; }
        .about-note { background: var(--soft); border: 1px solid var(--line); border-radius: 12px; padding: 24px; }
        .about-note h3 { margin: 0 0 8px; font-size: 17px; color: var(--g-dark); font-weight: 700; }
        .about-note p { font-size: 14px; margin: 0; }
        .social-line { margin-top: 14px; font-size: 14px; color: var(--g-dark); font-weight: 600; word-break: break-word; }
        .social-line a { color: var(--g-mid); }

        /* ===== Accommodation cards (same look as accommodations.php) ===== */
        .acc-card { background: #fff; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 4px 20px rgba(52,74,48,.04); overflow: hidden; display: flex; flex-direction: column; transition: transform .2s ease, box-shadow .2s ease; }
        .acc-card:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(52,74,48,.08); }
        .acc-img { height: 210px; background-size: cover; background-position: center; background-color: var(--soft); border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: center; color: #777; font-size: 13.5px; }
        .acc-body { padding: 20px; display: flex; flex-direction: column; flex-grow: 1; justify-content: space-between; gap: 16px; }
        .acc-body h3 { font-size: 18px; color: var(--g-dark); margin: 0 0 6px; font-weight: 700; }
        .acc-body .price { font-size: 16px; color: var(--g-dark); margin: 0 0 8px; }
        .acc-body .meta { font-size: 13px; color: var(--muted); margin: 0 0 4px; }
        .pill { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: .3px; text-transform: uppercase; margin-top: 10px; }
        .pill.available { background: #e5f4e7; color: #256030; }
        .pill.unavailable { background: #f0ede4; color: #666; }
        .btn-off { display: flex; align-items: center; justify-content: center; padding: 12px; border-radius: 8px; border: 1px solid #e3dec9; background: #f0ede4; color: #888; font-size: 14px; font-weight: 700; cursor: not-allowed; }

        /* ===== Amenities ===== */
        .amenity-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 26px 34px; }
        .amenity-list div { border-top: 2px solid var(--g-mid); padding-top: 12px; }
        .amenity-list h3 { margin: 0 0 6px; font-size: 16px; color: var(--g-dark); font-weight: 700; }
        .amenity-list p { margin: 0; font-size: 13.5px; line-height: 1.55; color: var(--muted); }

        /* ===== Closing call to action ===== */
        .cta { background: var(--soft); border: 1px solid var(--line); border-radius: 12px; padding: 36px 40px; display: flex; align-items: center; justify-content: space-between; gap: 24px; flex-wrap: wrap; margin-bottom: 20px; }
        .cta h2 { margin: 0 0 6px; font-size: 22px; color: var(--g-dark); font-weight: 700; }
        .cta p { margin: 0; font-size: 14.5px; color: var(--muted); max-width: 52ch; line-height: 1.6; }

        /* ===== Footer: explicit colors so the text is always readable ===== */
        .footer { background: var(--g-dark) !important; color: #e9efe4 !important; padding: 36px 0 !important; margin-top: 40px; }
        .footer p { color: #e9efe4 !important; margin: 0 0 6px; font-size: 13.5px; }
        .footer .f-name { color: #ffffff !important; font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .footer .f-tag { color: #cfdcc8 !important; margin-bottom: 14px; }
        .footer a { color: #ffffff !important; font-weight: 700; text-decoration: underline; }
        .footer .copyright { margin-top: 14px; padding-top: 14px; border-top: 1px solid rgba(255,255,255,.18); font-size: 12px; color: #b9c9b1 !important; }

        @media (max-width: 860px) {
            .hero, .about-wrap { grid-template-columns: 1fr; }
            .hero-main { padding: 40px 26px; }
            .hero-main h1 { font-size: 32px; }
            .cta { padding: 28px 24px; }
        }
        @media (max-width: 600px) {
            .hero-actions a { width: 100%; box-sizing: border-box; }
        }
    </style>
</head>
<body>

<!-- ============ NAVIGATION BAR ============ -->
<header class="navbar">
    <div class="nav-container">
        <a href="home.php" class="nav-brand">
            <h2><?= e($resort_name) ?></h2>
        </a>
        <nav class="nav-links">
            <a href="home.php" class="active">Home</a>
            <a href="../accommodations.php">Accommodations</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="my_bookings.php">My Bookings</a>
            <a href="profile.php">Profile</a>
            <a href="../logout.php" class="nav-btn-outline">Sign Out</a>
        </nav>
    </div>
</header>

<!-- ============ MAIN CONTENT ============ -->
<main class="container" style="padding: 40px 20px;">

    <!-- Hero: name + tagline on the left, contact details from Resort Info on the right -->
    <section class="hero">
        <div class="hero-main">
            <p class="hero-hello"><?= $first_name !== "" ? "Welcome back, " . e($first_name) . "." : "Welcome." ?></p>
            <h1><?= e($resort_name) ?></h1>
            <p class="hero-tag"><?= $tagline !== "" ? e($tagline) : "Your peaceful escape awaits." ?></p>
            <div class="hero-actions">
                <a href="../accommodations.php" class="btn-solid">Book a stay</a>
                <a href="#about" class="btn-ghost">About the resort</a>
            </div>
        </div>
        <aside class="hero-side">
            <h2>Plan your visit</h2>
            <?php if ($location !== ""): ?>
                <div class="fact"><b>Location</b><span><?= e($location) ?></span></div>
            <?php endif; ?>
            <?php if ($phone !== ""): ?>
                <div class="fact"><b>Contact number</b><a href="tel:<?= e($phone_href) ?>"><?= e($phone) ?></a></div>
            <?php endif; ?>
            <?php if ($email !== ""): ?>
                <div class="fact"><b>Email</b><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></div>
            <?php endif; ?>
            <?php if ($fb_url !== ""): ?>
                <div class="fact"><b>Facebook</b><a href="<?= e($fb_url) ?>" target="_blank" rel="noopener">Visit our page</a></div>
            <?php endif; ?>
            <?php if ($location === "" && $phone === "" && $email === "" && $fb_url === ""): ?>
                <p style="margin:0; font-size:14px; color:var(--muted);">Contact details will appear here once the resort adds them.</p>
            <?php endif; ?>
        </aside>
    </section>

    <!-- About -->
    <section class="block-section" id="about">
        <div class="block-head">
            <h2>About <?= e($resort_name) ?></h2>
        </div>
        <div class="about-wrap">
            <div>
                <?php if ($about_text !== ""): ?>
                    <p><?= nl2br(e($about_text)) ?></p>
                <?php else: ?>
                    <p><?= e($resort_name) ?> is a relaxing destination with comfortable accommodations, gardens, and space for events and getaways.</p>
                    <p>Browse available rooms and cottages, reserve online, and manage your bookings from your dashboard.</p>
                <?php endif; ?>
                <?php if ($social !== ""): ?>
                    <div class="social-line">
                        Also find us on:
                        <?php if ($social_url !== ""): ?>
                            <a href="<?= e($social_url) ?>" target="_blank" rel="noopener"><?= e($social) ?></a>
                        <?php else: ?>
                            <?= e($social) ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="about-note">
                <h3>Relax and enjoy</h3>
                <p>A quiet place for families, friends, celebrations, and special occasions, away from the busy city.</p>
            </div>
        </div>
    </section>

    <!-- Accommodations -->
    <section class="block-section" id="rooms">
        <div class="block-head">
            <h2>Rooms and cottages</h2>
            <a href="../accommodations.php" class="link-green">View all accommodations</a>
        </div>

        <div class="grid-3">
            <?php if ($accommodations && $accommodations->num_rows > 0): ?>
                <?php while ($row = $accommodations->fetch_assoc()):
                    $imgs = json_decode((string)($row["image_urls"] ?? "[]"), true);
                    $imgs = is_array($imgs) ? array_values(array_filter($imgs, 'is_string')) : [];
                    if (empty($imgs) && !empty($row["image"])) { $imgs = [$row["image"]]; }
                    $main_img = !empty($imgs) ? img_src($imgs[0]) : "";
                    $is_open = ((int)($row["is_available"] ?? 1) === 1);
                ?>
                    <div class="acc-card">
                        <?php if ($main_img !== ""): ?>
                            <div class="acc-img" style="background-image: url('<?= e($main_img) ?>');"></div>
                        <?php else: ?>
                            <div class="acc-img">No image available</div>
                        <?php endif; ?>
                        <div class="acc-body">
                            <div>
                                <h3><?= e($row["name"]) ?></h3>
                                <p class="price"><strong><?= peso($row["price"]) ?></strong> / night</p>
                                <p class="meta">Type: <?= e($row["type"] ?? "Room") ?></p>
                                <p class="meta">Max capacity: <?= (int)($row["capacity"] ?? 0) ?> persons</p>
                                <span class="pill <?= $is_open ? 'available' : 'unavailable' ?>"><?= $is_open ? 'Available' : 'Currently unavailable' ?></span>
                            </div>
                            <?php if ($is_open): ?>
                                <a href="../book.php?id=<?= (int)$row["id"] ?>" class="btn-solid block">Book now</a>
                            <?php else: ?>
                                <span class="btn-off">Not available for booking</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p style="grid-column: 1 / -1; color: var(--muted); text-align: center; padding: 30px;">No accommodations are available right now. Please check back soon.</p>
            <?php endif; ?>
        </div>
    </section>

    <!-- Amenities -->
    <section class="block-section" id="amenities">
        <div class="block-head">
            <h2>What you can enjoy</h2>
            <p>Everything you need for a comfortable stay.</p>
        </div>
        <div class="amenity-list">
            <div><h3>Swimming pool</h3><p>Cool off in our pool area, open to all guests.</p></div>
            <div><h3>Gardens and events area</h3><p>Open, green spaces for gatherings, celebrations, and quiet time.</p></div>
            <div><h3>Cozy cottages</h3><p>Comfortable stays sized for families and groups.</p></div>
            <div><h3>Online booking</h3><p>Pick your dates, pay the down payment, and track it in My Bookings.</p></div>
        </div>
    </section>

    <!-- Closing call to action -->
    <section class="cta">
        <div>
            <h2>Ready to plan your stay?</h2>
            <p>Choose your dates, pick a room or cottage, and reserve in a few steps.</p>
        </div>
        <a href="../accommodations.php" class="btn-solid">See availability</a>
    </section>

</main>

<!-- ============ FOOTER ============ -->
<footer class="footer">
    <div class="container" style="text-align: center;">
        <p class="f-name"><?= e($resort_name) ?></p>
        <?php if ($tagline !== ""): ?><p class="f-tag"><?= e($tagline) ?></p><?php endif; ?>
        <?php if ($location !== ""): ?><p><?= e($location) ?></p><?php endif; ?>
        <?php
            $contact_bits = [];
            if ($phone !== "") $contact_bits[] = "Tel/Mobile: " . e($phone);
            if ($email !== "") $contact_bits[] = "Email: " . e($email);
            if ($contact_bits) echo "<p>" . implode(" &nbsp;|&nbsp; ", $contact_bits) . "</p>";
        ?>
        <?php if ($fb_url !== ""): ?>
            <p><a href="<?= e($fb_url) ?>" target="_blank" rel="noopener">Visit our Facebook page</a></p>
        <?php endif; ?>
        <p class="copyright">&copy; <?= date("Y") ?> <?= e($resort_name) ?>. All rights reserved.</p>
    </div>
</footer>

<script src="../js/app.js" data-base="../" data-role="customer"></script>
<script>
    document.addEventListener("DOMContentLoaded", function () {
        document.querySelectorAll('.nf-wrap, .nf-bell, .notification-bell').forEach(function (el) { el.remove(); });
    });
</script>
<script src="../js/responsive.js"></script>
</body>
</html>