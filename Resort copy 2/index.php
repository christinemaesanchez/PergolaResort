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
$accommodations = $conn->query("SELECT * FROM accommodations $status_where ORDER BY is_available DESC, id DESC LIMIT 3");
 
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
    <title>Welcome | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="css/customer.css">
    <link rel="stylesheet" href="css/ui.css">
    <style>
        /* HIDE NOTIFICATION BELLS & EXTRA ICONS */
        .nf-wrap, .nf-bell, .notification-bell, .nav-links i, .nav-links svg {
            display: none !important;
        }

        /* Hero Section Styling */
        .hero {
            position: relative;
            background: linear-gradient(135deg, rgba(52, 74, 48, 0.85), rgba(82, 107, 73, 0.75)), url('images/hero-bg.jpg') center/cover no-repeat;
            background-color: #344a30;
            color: #fff;
            padding: 100px 20px;
            text-align: center;
            border-radius: 16px;
            margin-bottom: 30px;
            box-shadow: 0 8px 30px rgba(52, 74, 48, 0.12);
        }
        .welcome-text {
            display: inline-block;
            background: rgba(255, 255, 255, 0.15);
            padding: 6px 16px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1px;
            text-transform: uppercase;
            margin-bottom: 16px;
            backdrop-filter: blur(4px);
        }
        .hero h1 { font-size: 42px; margin-bottom: 12px; font-weight: 800; letter-spacing: -0.5px; }
        .hero h2 { font-size: 20px; font-weight: 500; margin-bottom: 16px; color: #f7f5ee; }
        .hero-description { font-size: 16px; max-width: 600px; margin: 0 auto 30px auto; color: #f7f5ee; line-height: 1.6; }

        /* Contact Info Ribbon */
        .info-ribbon {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 15px;
            background: #f7f5ee;
            border: 1px solid #ede8dc;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 40px;
            text-align: center;
        }
        .info-ribbon div { font-size: 13.5px; color: #344a30; font-weight: 600; }
        .info-ribbon span { display: block; font-size: 12px; color: #666; font-weight: 400; margin-top: 2px; }

        /* Section Layouts */
        .section { padding: 40px 0; border-bottom: 1px solid #ede8dc; margin-bottom: 40px; }
        .section-label { font-size: 12px; font-weight: 700; color: #526b49; letter-spacing: 1px; text-transform: uppercase; margin-bottom: 8px; }
        .section-description { color: #666; font-size: 14px; margin-bottom: 30px; }

        /* About Grid */
        .about-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: center; }
        .about-card { background: #f7f5ee; border: 1px solid #ede8dc; padding: 30px; border-radius: 12px; box-shadow: 0 4px 20px rgba(52, 74, 48, 0.02); }
        .about-card h3 { color: #344a30; font-size: 18px; margin-bottom: 10px; font-weight: 700; }
        .about-card p { color: #666; font-size: 14px; line-height: 1.6; margin: 0; }

        /* Amenities Grid */
        .amenities-container { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-top: 25px; }
        .amenity { background: #f7f5ee; border: 1px solid #ede8dc; padding: 24px; border-radius: 12px; text-align: center; transition: transform 0.2s ease; }
        .amenity:hover { transform: translateY(-3px); box-shadow: 0 6px 20px rgba(52, 74, 48, 0.06); }
        .amenity h3 { color: #344a30; font-size: 16px; font-weight: 700; margin-bottom: 6px; }
        .amenity p { color: #666; font-size: 13.5px; margin: 0; line-height: 1.5; }

        /* Booking CTA Box */
        .booking-section { background: #f7f5ee; border: 1px solid #ede8dc; border-radius: 16px; padding: 50px 20px; text-align: center; margin: 50px 0; }
        .booking-section h2 { color: #344a30; font-size: 28px; font-weight: 800; margin-bottom: 10px; }
        .booking-section p { color: #666; font-size: 15px; max-width: 500px; margin: 0 auto 25px auto; line-height: 1.6; }

        /* Card & Pill Styling */
        .card { border: 1px solid #ede8dc !important; border-radius: 12px !important; box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04) !important; transition: transform 0.2s ease, box-shadow 0.2s ease; }
        .card:hover { transform: translateY(-3px); box-shadow: 0 8px 28px rgba(52, 74, 48, 0.1) !important; }
        
        .status-badge { display: inline-block; padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; letter-spacing: 0.3px; text-transform: uppercase; margin-bottom: 12px; }
        .status-badge.available { background: #e5f4e7; color: #256030; }
        .status-badge.unavailable { background: #f0ede4; color: #666; }

        /* Buttons */
        .book-btn, .primary-btn { background: #6f8761 !important; border: 1px solid #526b49 !important; border-radius: 8px !important; font-weight: 700 !important; padding: 12px 28px; color: #fff; text-decoration: none; display: inline-block; transition: all 0.2s ease; }
        .book-btn:hover, .primary-btn:hover { background: #526b49 !important; transform: translateY(-1px); }
        .learn-btn { background: #fff; border: 1.5px solid #6f8761; color: #344a30; border-radius: 8px; font-weight: 700; padding: 12px 28px; text-decoration: none; display: inline-block; transition: all 0.2s ease; }
        .learn-btn:hover { background: #f7f5ee; transform: translateY(-1px); }

        @media (max-width: 768px) { .about-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

    <!-- Navigation Bar -->
    <header class="navbar">
        <div class="nav-container">
            <a href="index.php" class="nav-brand">
                <h2>🌿 <?= e($resort_name) ?></h2>
            </a>
            <nav class="nav-links">
                <a href="#home" class="active">Home</a>
                <a href="#about">About Us</a>
                <a href="#rooms">Rooms</a>
                <a href="#amenities">Amenities</a>
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

    <!-- Main Container -->
    <main class="container" style="padding: 20px;">

        <!-- Hero Section -->
        <section class="hero" id="home">
            <div class="hero-content">
                <span class="welcome-text">WELCOME TO</span>
                <h1><?= e($resort_name) ?></h1>
                <h2>Your peaceful escape awaits.</h2>
                <p class="hero-description">
                    Relax, unwind, and enjoy a memorable stay surrounded by
                    nature, comfort, and beautiful resort scenery.
                </p>
                <div style="display: flex; gap: 14px; justify-content: center; flex-wrap: wrap;">
                    <a href="<?= $is_customer ? 'customer/dashboard.php' : 'login.php' ?>" class="book-btn">Book Now</a>
                    <a href="#about" class="learn-btn">Explore Resort</a>
                </div>
            </div>
        </section>

        <!-- Quick Info Ribbon -->
        <div class="info-ribbon">
            <div>📍 Location <span>Purok 17, Poblacion, Manticao, MisOr</span></div>
            <div>📞 Contact <span>0966 043 4559</span></div>
            <div>✉️ Email <span>pergolagardens@gmail.com</span></div>
            <div>
                🌐 Social
                <span><a href="https://www.facebook.com/profile.php?id=100087187057803" target="_blank" rel="noopener" style="color: #0047ff; font-weight: 700; text-decoration: underline;">Facebook Page</a></span>
            </div>
        </div>

        <!-- About Section -->
        <section class="section" id="about">
            <div class="about-grid">
                <div class="about-text">
                    <p class="section-label">ABOUT US</p>
                    <h2 style="color: #344a30; font-size: 26px; font-weight: 800; margin-bottom: 15px;">Pergola Private Resort, Gardens, and Events</h2>
                    <p style="color: #666; font-size: 14.5px; line-height: 1.7; margin-bottom: 12px;">
                        Pergola Resort is a relaxing destination where guests
                        can enjoy comfortable accommodations, refreshing
                        surroundings, gardens, and quality leisure time for events and getaways.
                    </p>
                    <p style="color: #666; font-size: 14.5px; line-height: 1.7; margin: 0;">
                        Our resort booking system makes it easy for guests to
                        view available rooms and cottages, make reservations,
                        and manage their bookings online.
                    </p>
                </div>
                <div class="about-card">
                    <h3>🌿 Relax & Enjoy</h3>
                    <p>
                        Experience a peaceful environment perfect for
                        families, friends, celebrations, and special occasions away from the busy city life.
                    </p>
                </div>
            </div>
        </section>


        <!-- Rooms Section -->
        <section class="section" id="rooms">
            <p class="section-label">OUR ACCOMMODATIONS</p>
            <h2 style="color: #344a30; font-size: 26px; font-weight: 800; margin-bottom: 6px;">Rooms & Cottages</h2>
            <p class="section-description">Choose a comfortable place to stay during your visit.</p>

            <div class="grid-3">
                <?php if ($accommodations && $accommodations->num_rows > 0): ?>
                    <?php while ($row = $accommodations->fetch_assoc()):
                        $imgs = json_decode($row["image_urls"] ?? "[]", true);
                        if (empty($imgs) && !empty($row["image"])) { $imgs = [$row["image"]]; }
                        $main_img = !empty($imgs) ? $imgs[0] : "";
                        $is_open = ((int)($row["is_available"] ?? 1) === 1);
                    ?>
                        <div class="card" style="overflow: hidden; display: flex; flex-direction: column;">
                            <?php if ($main_img !== ""): ?>
                                <div style="width: 100%; height: 200px; background-image: url('<?= e($main_img) ?>'); background-size: cover; background-position: center;"></div>
                            <?php else: ?>
                                <div style="width: 100%; height: 200px; background: #f7f5ee; display: flex; align-items: center; justify-content: center; color: #777; font-size: 13.5px; border-bottom: 1px solid #ede8dc;">No Image Available</div>
                            <?php endif; ?>

                            <div style="padding: 20px; display: flex; flex-direction: column; flex-grow: 1; justify-content: space-between;">
                                <div>
                                    <h3 style="font-size: 18px; color: #344a30; margin-bottom: 6px; font-weight: 700;"><?= e($row["name"]) ?></h3>
                                    <p style="font-size: 16px; color: #344a30; margin-bottom: 6px;"><strong><?= peso($row["price"]) ?></strong> / night</p>
                                    <p style="font-size: 13px; color: #666; margin-bottom: 4px;">Type: <?= e($row["type"]) ?></p>
                                    <p style="font-size: 13px; color: #666; margin-bottom: 12px;">Max Capacity: <?= (int)$row["capacity"] ?> Persons</p>
                                    
                                    <?php if ($is_open): ?>
                                        <span class="status-badge available">Available</span>
                                    <?php else: ?>
                                        <span class="status-badge unavailable">Currently unavailable</span>
                                    <?php endif; ?>
                                </div>

                                <?php if ($is_open): ?>
                                    <a href="book.php?id=<?= (int)$row["id"] ?>" class="btn primary-btn full-width" style="text-align: center; text-decoration: none; padding: 10px 0; margin-top: 15px;">Book Now</a>
                                <?php else: ?>
                                    <span class="btn full-width" style="text-align: center; background: #f0ede4; color: #888; cursor: not-allowed; border: none; padding: 10px 0; margin-top: 15px; display: block; border-radius: 8px; font-weight: 600;">Not available</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <p style="grid-column: 1 / -1; color: #666; text-align: center; padding: 30px;">No accommodations currently available.</p>
                <?php endif; ?>
            </div>
            
            <div style="text-align: center; margin-top: 30px;">
                <a href="accommodations.php" class="learn-btn">View All Accommodations &rarr;</a>
            </div>
        </section>


        <!-- Amenities Section -->
        <section class="section" id="amenities">
            <p class="section-label">RESORT FEATURES</p>
            <h2 style="color: #344a30; font-size: 26px; font-weight: 800; margin-bottom: 6px;">Enjoy Our Amenities</h2>
            <p class="section-description">Everything you need for a comfortable and refreshing experience.</p>

            <div class="amenities-container">
                <div class="amenity">
                    <h3>🏊‍♂️ Swimming Pool</h3>
                    <p>Relax and enjoy our refreshing pool area.</p>
                </div>
                <div class="amenity">
                    <h3>🌳 Garden & Events Area</h3>
                    <p>Beautiful scenic spaces ideal for gatherings and relaxation.</p>
                </div>
                <div class="amenity">
                    <h3>🏡 Cozy Cottages</h3>
                    <p>Stay in comfortable accommodations built for groups and families.</p>
                </div>
                <div class="amenity">
                    <h3>⚡ Easy Booking</h3>
                    <p>Reserve your preferred room or cottage online in seconds.</p>
                </div>
            </div>
        </section>


        <!-- Booking CTA Section -->
        <section class="booking-section">
            <h2>Ready for a relaxing getaway?</h2>
            <p>
                Reserve your room or cottage today and start planning
                your memorable stay at <?= e($resort_name) ?>.
            </p>
            <a href="<?= $is_logged_in ? 'accommodations.php' : 'register.php' ?>" class="book-btn">
                Make a Reservation
            </a>
        </section>

    </main>


    <!-- Footer -->
    <footer class="footer">
        <div class="container" style="text-align: center;">
            <p style="font-weight: 700; color: #344a30; margin-bottom: 6px; font-size: 16px;">🌿 Pergola Private Resort, Gardens, and Events</p>
            <p style="color: #666; font-size: 13.5px; margin-bottom: 4px;">Purok 17, Poblacion, Manticao, MisOr</p>
            <p style="color: #666; font-size: 13.5px; margin-bottom: 12px;">Tel/Mobile: 0966 043 4559 &nbsp;|&nbsp; Email: pergolagardens@gmail.com</p>
            <p style="margin-bottom: 16px;">
                <a href="https://www.facebook.com/profile.php?id=100087187057803" target="_blank" rel="noopener" style="color: #0047ff; font-weight: 700; text-decoration: none;">Visit our Facebook Page &rarr;</a>
            </p>
            <p class="copyright" style="font-size: 12px; color: #888;">© <?= date("Y") ?> <?= e($resort_name) ?>. All Rights Reserved.</p>
        </div>
    </footer>

</body>
</html>