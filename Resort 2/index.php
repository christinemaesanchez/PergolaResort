<?php
// =========================================================================
// 1. CONNECTION + SESSION
// =========================================================================
require_once "config.php";
require_once "payment_lib.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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
    <title>Pergola Resort</title>
    <link rel="stylesheet" href="css/landing.css">
</head>
<body>

    <!-- Navigation Bar -->
    <nav class="navbar">
        <div class="logo">
            <span>🌿</span>
            <?= e($resort_name) ?>
        </div>

        <div class="nav-links">
            <a href="#home">Home</a>
            <a href="#about">About Us</a>
            <a href="#rooms">Rooms</a>
            <a href="#amenities">Amenities</a>
            <a href="login.php" class="login-btn">Login</a>
            <a href="register.php" class="register-btn">Register</a>
        </div>
    </nav>


    <!-- Hero Section -->
    <section class="hero" id="home">

        <div class="hero-overlay"></div>

        <div class="hero-content">

            <p class="welcome-text">WELCOME TO</p>

            <h1><?= e($resort_name) ?></h1>

            <h2>Your peaceful escape awaits.</h2>

            <p class="hero-description">
                Relax, unwind, and enjoy a memorable stay surrounded by
                nature, comfort, and beautiful resort scenery.
            </p>

            <div class="hero-buttons">
                <a href="login.php" class="book-btn">Book Now</a>
                <a href="#about" class="learn-btn">Explore Resort</a>
            </div>

        </div>

    </section>


    <!-- About Section -->
    <section class="about section" id="about">

        <div class="section-content">

            <div class="about-text">

                <p class="section-label">ABOUT US</p>

                <h2>A place to relax and enjoy</h2>

                <p>
                    Pergola Resort is a relaxing destination where guests
                    can enjoy comfortable accommodations, refreshing
                    surroundings, and quality leisure time.
                </p>

                <p>
                    Our resort booking system makes it easy for guests to
                    view available rooms and cottages, make reservations,
                    and manage their bookings online.
                </p>

            </div>

            <div class="about-card">
                <div class="about-icon"></div>
                <h3>Relax & Enjoy</h3>
                <p>
                    Experience a peaceful environment perfect for
                    families, friends, and special occasions.
                </p>
            </div>

        </div>

    </section>


    <!-- Rooms Section -->
    <section class="rooms section" id="rooms">

        <p class="section-label">OUR ACCOMMODATIONS</p>

        <h2>Rooms & Cottages</h2>

        <p class="section-description">
            Choose a comfortable place to stay during your visit.
        </p>

        <div class="card-container">

            <div class="resort-card">

                <div class="card-image room-image"></div>

                <div class="card-content">

                    <h3>Deluxe Room</h3>

                    <p>
                        A comfortable room perfect for guests looking
                        for a relaxing and peaceful stay.
                    </p>

                    <a href="login.php" class="card-btn">
                        View Room
                    </a>

                </div>

            </div>


            <div class="resort-card">

                <div class="card-image cottage-image"></div>

                <div class="card-content">

                    <h3>Family Cottage</h3>

                    <p>
                        A spacious cottage suitable for families and
                        groups looking for a comfortable getaway.
                    </p>

                    <a href="login.php" class="card-btn">
                        View Cottage
                    </a>

                </div>

            </div>


            <div class="resort-card">

                <div class="card-image pool-image"></div>

                <div class="card-content">

                    <h3>Poolside Stay</h3>

                    <p>
                        Enjoy a refreshing stay near the pool and
                        beautiful resort surroundings.
                    </p>

                    <a href="login.php" class="card-btn">
                        View Stay
                    </a>

                </div>

            </div>

        </div>

    </section>


    <!-- Amenities Section -->
    <section class="amenities section" id="amenities">

        <p class="section-label">RESORT FEATURES</p>

        <h2>Enjoy Our Amenities</h2>

        <div class="amenities-container">

            <div class="amenity">
                <div class="amenity-icon"></div>
                <h3>Swimming Pool</h3>
                <p>Relax and enjoy our refreshing pool.</p>
            </div>

            <div class="amenity">
                <div class="amenity-icon"></div>
                <h3>Garden Area</h3>
                <p>Enjoy a peaceful environment surrounded by nature.</p>
            </div>

            <div class="amenity">
                <div class="amenity-icon"></div>
                <h3>Comfortable Cottages</h3>
                <p>Stay in comfortable accommodations for your group.</p>
            </div>

            <div class="amenity">
                <div class="amenity-icon"></div>
                <h3>Easy Booking</h3>
                <p>Reserve your preferred room or cottage online.</p>
            </div>

        </div>

    </section>


    <!-- Booking Section -->
    <section class="booking-section">

        <div class="booking-content">

            <h2>Ready for a relaxing getaway?</h2>

            <p>
                Reserve your room or cottage and start planning
                your memorable stay at Pergola Resort.
            </p>

            <a href="register.php" class="book-btn">
                Make a Reservation
            </a>

        </div>

    </section>


    <!-- Footer -->
    <footer>

        <div class="footer-logo">
            🌿 <?= e($resort_name) ?>
        </div>

        <p>
            Your peaceful destination for relaxation and memorable moments.
        </p>

        <p class="copyright">
            © <?= date("Y") ?> <?= e($resort_name) ?>. All Rights Reserved.
        </p>

    </footer>

</body>
</html>