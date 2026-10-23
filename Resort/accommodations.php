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
// 2. FETCH RESORT INFO, ACCOMMODATIONS & ADD-ONS
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

/* Add-ons: the admin page saves them in the `addons` table (pricing_method, is_active).
   Falls back to the old `resort_addons` table if `addons` isn't there. */
$addon_rows = [];
$addon_src = null;
$t = $conn->query("SHOW TABLES LIKE 'addons'");
if ($t && $t->num_rows > 0) {
    $fc = [];
    $r = $conn->query("SHOW COLUMNS FROM addons");
    while ($c = $r->fetch_assoc()) { $fc[$c["Field"]] = true; }
    if (isset($fc["name"], $fc["price"], $fc["pricing_method"])) {
        $addon_src = $conn->query("SELECT name, price, pricing_method AS ptype, description FROM addons"
            . (isset($fc["is_active"]) ? " WHERE is_active = 1" : "") . " ORDER BY id ASC");
    }
}
if (!$addon_src) {
    $t2 = $conn->query("SHOW TABLES LIKE 'resort_addons'");
    if ($t2 && $t2->num_rows > 0) {
        $addon_src = $conn->query("SELECT name, price, type AS ptype, description FROM resort_addons ORDER BY id ASC");
    }
}
if ($addon_src) {
    while ($a = $addon_src->fetch_assoc()) { $addon_rows[] = $a; }
}

// =========================================================================
// 3. HELPERS
// =========================================================================
if (!function_exists('e')) {
    function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('peso')) {
    function peso($n) { return "₱" . number_format((float)$n, 2); }
}
function lower_str($s) { return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s); }

$is_logged_in = !empty($_SESSION["user_id"]);
$is_customer = ($is_logged_in && ($_SESSION["role"] ?? "") === "customer");
$is_admin = ($is_logged_in && ($_SESSION["role"] ?? "") === "admin");

/* ---------- Group accommodations by TYPE ---------- */
$type_order = ["Room", "Suite", "Cottage", "Villa", "Function Hall", "Other"];
$type_labels = ["Room" => "Rooms", "Suite" => "Suites", "Cottage" => "Cottages", "Villa" => "Villas", "Function Hall" => "Function Halls", "Other" => "Other"];
$groups = [];
$total_items = 0;
if ($accommodations) {
    while ($row = $accommodations->fetch_assoc()) {
        $tp = trim((string)($row["type"] ?? ""));
        if ($tp === "") { $tp = "Room"; }
        $groups[$tp][] = $row;
        $total_items++;
    }
}
$ordered = [];
foreach ($type_order as $tn) { if (isset($groups[$tn])) { $ordered[$tn] = $groups[$tn]; unset($groups[$tn]); } }
ksort($groups);
foreach ($groups as $tn => $rows) { $ordered[$tn] = $rows; }
$groups = $ordered;
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

        /* ===== LARGER IMAGES & CAROUSEL SLIDER STYLING ===== */
        .card-img-wrap {
            position: relative;
            width: 100%;
            height: 300px;
            background-size: cover;
            background-position: center;
            background-color: #f7f5ee;
            border-bottom: 1px solid #ede8dc;
            transition: background-image 0.3s ease;
        }

        .slider-arrow {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            background: rgba(52, 74, 48, 0.75);
            color: #fff;
            border: none;
            width: 38px;
            height: 38px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            transition: background 0.2s, transform 0.2s;
            z-index: 2;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }
        .slider-arrow:hover {
            background: rgba(52, 74, 48, 0.95);
            transform: translateY(-50%) scale(1.05);
        }
        .slider-prev { left: 14px; }
        .slider-next { right: 14px; }

        .thumb-gallery {
            display: flex;
            gap: 8px;
            margin-bottom: 14px;
            overflow-x: auto;
            padding-bottom: 4px;
        }
        .thumb-gallery img {
            width: 75px;
            height: 55px;
            object-fit: cover;
            border-radius: 6px;
            border: 2px solid #e3dec9;
            cursor: pointer;
            opacity: 0.65;
            transition: all 0.2s ease;
        }
        .thumb-gallery img.active-thumb, .thumb-gallery img:hover {
            opacity: 1;
            border-color: #6f8761;
            box-shadow: 0 2px 8px rgba(52, 74, 48, 0.2);
        }

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

        /* Buttons */
        .primary-btn, .browse-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 26px;
            background: #6f8761 !important;
            border: 1px solid #526b49 !important;
            border-radius: 8px !important;
            color: #fff !important;
            font-family: inherit;
            font-size: 14px;
            font-weight: 700 !important;
            letter-spacing: 0.3px;
            text-decoration: none !important;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(52, 74, 48, 0.15);
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease !important;
        }
        .primary-btn:hover, .browse-btn:hover {
            background: #526b49 !important;
            box-shadow: 0 6px 16px rgba(52, 74, 48, 0.25);
            transform: translateY(-1px);
        }
        .browse-btn.full-width { display: flex; width: 100%; box-sizing: border-box; }
        .btn-disabled {
            display: flex; align-items: center; justify-content: center; width: 100%; box-sizing: border-box;
            padding: 12px 26px; border-radius: 8px; border: 1px solid #e3dec9; background: #f0ede4; color: #888;
            font-size: 14px; font-weight: 700; letter-spacing: 0.3px; cursor: not-allowed;
        }

        /* Search + filter toolbar */
        .toolbar {
            background: #f7f5ee;
            border: 1px solid #ede8dc;
            border-radius: 12px;
            padding: 16px;
            margin-bottom: 10px;
        }
        .toolbar-top { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .search-box { position: relative; flex: 1 1 280px; }
        .search-box input {
            width: 100%; box-sizing: border-box; padding: 11px 36px 11px 14px; font: inherit; font-size: 14px;
            border: 1px solid #d6cfba; border-radius: 8px; background: #fff; color: #344a30;
        }
        .search-box input:focus, .toolbar select:focus { outline: none; border-color: #6f8761; }
        .search-clear {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%); display: none;
            border: 0; background: none; color: #888; font-size: 18px; line-height: 1; cursor: pointer; padding: 4px 6px;
        }
        .toolbar select {
            padding: 11px 12px; font: inherit; font-size: 14px; border: 1px solid #d6cfba; border-radius: 8px; background: #fff; color: #344a30;
        }
        .check-label { display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; color: #344a30; font-weight: 600; cursor: pointer; white-space: nowrap; }
        .check-label input { accent-color: #6f8761; width: 16px; height: 16px; margin: 0; }

        .type-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 14px; }
        .type-tab {
            border: 1px solid #d6cfba; background: #fff; color: #344a30; border-radius: 20px; padding: 7px 14px;
            font: inherit; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.15s;
        }
        .type-tab:hover { border-color: #6f8761; }
        .type-tab.active { background: #6f8761; border-color: #526b49; color: #fff; }
        .type-tab .n { opacity: 0.7; font-weight: 700; margin-left: 4px; }

        .result-line { font-size: 13px; color: #777; margin: 12px 2px 0; }

        .type-section { margin-top: 35px; }
        .type-section:first-of-type { margin-top: 25px; }
        .type-heading {
            display: flex; align-items: baseline; justify-content: space-between; gap: 10px;
            border-bottom: 1px solid #ede8dc; padding-bottom: 10px; margin-bottom: 20px;
        }
        .type-heading h2 { font-size: 21px; color: #344a30; margin: 0; font-weight: 700; }
        .type-heading span { font-size: 13px; color: #777; }

        .empty-state { display: none; text-align: center; padding: 50px 20px; color: #666; }
        .empty-state .browse-btn { margin-top: 12px; }
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
                
            <?php else: ?>
                <a href="login.php" class="nav-btn-outline">Sign In</a>
                <a href="register.php" class="btn primary-btn" style="padding: 9px 20px;">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<!-- ============ MAIN CONTENT ============ -->
<main class="container" style="padding: 40px 20px;">

    <div class="section-title">
        <h1>Our Accommodations & Resort Fees</h1>
        <p>Explore our comfortable rooms, suites, cottages, function halls, and available resort add-on fees.</p>
    </div>

    <?php if ($total_items > 0): ?>
    <!-- Search + filters -->
    <div class="toolbar">
        <div class="toolbar-top">
            <div class="search-box">
                <input type="search" id="q" placeholder="Search by name, type, or description..." autocomplete="off" aria-label="Search accommodations">
                <button type="button" class="search-clear" id="qClear" aria-label="Clear search">&times;</button>
            </div>
            <select id="fSort" aria-label="Sort by">
                <option value="default">Sort: Recommended</option>
                <option value="price_asc">Price: Low to High</option>
                <option value="price_desc">Price: High to Low</option>
                <option value="cap_desc">Capacity: Largest first</option>
                <option value="name">Name: A to Z</option>
            </select>
            <label class="check-label"><input type="checkbox" id="fOpen"> Available only</label>
        </div>

        <div class="type-tabs" id="tabs">
            <button type="button" class="type-tab active" data-type="all">All<span class="n"><?= (int)$total_items ?></span></button>
            <?php foreach ($groups as $tn => $rows): ?>
                <button type="button" class="type-tab" data-type="<?= e(lower_str($tn)) ?>"><?= e($type_labels[$tn] ?? $tn) ?><span class="n"><?= count($rows) ?></span></button>
            <?php endforeach; ?>
        </div>
        <p class="result-line" id="resultLine"></p>
    </div>

    <!-- One section (and card grid) per type -->
    <?php foreach ($groups as $tn => $rows): $slug = lower_str($tn); ?>
    <section class="type-section" data-section="<?= e($slug) ?>">
        <div class="type-heading">
            <h2><?= e($type_labels[$tn] ?? $tn) ?></h2>
            <span><span class="shown"><?= count($rows) ?></span> of <?= count($rows) ?></span>
        </div>

        <div class="grid-3">
            <?php foreach ($rows as $row):
                $imgs = json_decode((string)($row["image_urls"] ?? "[]"), true);
                $imgs = is_array($imgs) ? array_values(array_filter($imgs, 'is_string')) : [];
                if (empty($imgs) && !empty($row["image"])) { $imgs = [$row["image"]]; }
                $main_img = !empty($imgs) ? $imgs[0] : "";
                $is_open = ((int)($row["is_available"] ?? 1) === 1);
                $rid = (int)$row["id"];
            ?>
                <div class="card"
                     id="card-<?= $rid ?>"
                     data-name="<?= e(lower_str($row["name"] ?? "")) ?>"
                     data-search="<?= e(lower_str(($row["name"] ?? "") . " " . ($row["type"] ?? "") . " " . ($row["description"] ?? ""))) ?>"
                     data-price="<?= e((float)$row["price"]) ?>"
                     data-cap="<?= (int)$row["capacity"] ?>"
                     data-open="<?= $is_open ? 1 : 0 ?>"
                     data-order="<?= $rid ?>"
                     data-images='<?= e(json_encode($imgs)) ?>'
                     data-current-index="0">
                    
                    <?php if ($main_img !== ""): ?>
                        <div class="card-img-wrap" id="main-img-<?= $rid ?>" style="background-image: url('<?= e($main_img) ?>');">
                            <?php if (count($imgs) > 1): ?>
                                <button type="button" class="slider-arrow slider-prev" onclick="slideImage(<?= $rid ?>, -1)" aria-label="Previous photo">&#10094;</button>
                                <button type="button" class="slider-arrow slider-next" onclick="slideImage(<?= $rid ?>, 1)" aria-label="Next photo">&#10095;</button>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="card-img-wrap" style="display: flex; align-items: center; justify-content: center; color: #777; font-size: 13.5px;">No Image Available</div>
                    <?php endif; ?>

                    <div style="padding: 20px; display: flex; flex-direction: column; flex-grow: 1; justify-content: space-between;">
                        <div>
                            <?php if (count($imgs) > 1): ?>
                                <div class="thumb-gallery">
                                    <?php foreach ($imgs as $idx => $img_path): ?>
                                        <img src="<?= e($img_path) ?>" 
                                             class="<?= $idx === 0 ? 'active-thumb' : '' ?>" 
                                             onclick="setMainImage(<?= $rid ?>, <?= $idx ?>, '<?= e($img_path) ?>')" 
                                             alt="Thumbnail">
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <h3 style="font-size: 18px; color: #344a30; margin-bottom: 6px; font-weight: 700;"><?= e($row["name"]) ?></h3>
                            <p style="font-size: 16px; color: #344a30; margin-bottom: 8px;"><strong><?= peso($row["price"]) ?></strong> / night</p>
                            <p style="font-size: 13px; color: #666; margin-bottom: 4px;">Type: <?= e($row["type"]) ?></p>
                            <p style="font-size: 13px; color: #666; margin-bottom: 12px;">Max Capacity: <?= (int)$row["capacity"] ?> Persons</p>

                            <?php if (trim((string)($row["description"] ?? "")) !== ""): ?>
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
                                <a href="book.php?id=<?= $rid ?>" class="browse-btn full-width"><span>Book Now</span></a>
                            <?php else: ?>
                                <span class="btn-disabled">Not available for booking</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endforeach; ?>

    <div class="empty-state" id="emptyState">
        <p><strong>No matching accommodations found.</strong><br>Try a different keyword or category.</p>
        <button type="button" id="resetAll" class="browse-btn">Clear filters</button>
    </div>

    <?php else: ?>
        <p style="text-align: center; color: #666; padding: 40px;">No accommodations available at the moment.</p>
    <?php endif; ?>

    <!-- Optional Add-ons & Fees Showcase Section -->
    <?php if (!empty($addon_rows)): ?>
        <div class="addon-box">
            <h3 style="color: #344a30; font-size: 20px; margin-bottom: 6px; font-weight: 700;">Resort Add-ons & Optional Fees</h3>
            <p style="font-size: 13.5px; color: #666; margin-bottom: 0;">Enhance your stay with these optional amenities and add-on services.</p>
            <div class="addon-grid">
                <?php foreach ($addon_rows as $ao): ?>
                    <div class="addon-item">
                        <strong style="display: block; color: #344a30; font-size: 15px; margin-bottom: 4px;"><?= e($ao["name"]) ?></strong>
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                            <span style="font-size: 14px; font-weight: 700; color: #444;"><?= peso($ao["price"]) ?></span>
                            <span style="font-size: 11px; color: #777; font-weight: 600; background: #f7f5ee; padding: 2px 8px; border-radius: 4px;"><?= $ao["ptype"] === 'per_night' ? 'Per Night' : 'One-time' ?></span>
                        </div>
                        <?php if (!empty($ao["description"])): ?>
                            <p style="font-size: 12.5px; color: #666; margin-top: 6px; margin-bottom: 0; line-height: 1.4;"><?= e($ao["description"]) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
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

<script>
// ----- Image Carousel / Next-Prev navigation functions -----
window.slideImage = function (rid, direction) {
    var card = document.getElementById('card-' + rid);
    if (!card) return;
    var imgs = JSON.parse(card.dataset.images || '[]');
    if (imgs.length <= 1) return;
    var idx = parseInt(card.dataset.currentIndex || '0', 10);
    idx = (idx + direction + imgs.length) % imgs.length;
    card.dataset.currentIndex = idx;

    var mainImg = document.getElementById('main-img-' + rid);
    if (mainImg) mainImg.style.backgroundImage = "url('" + imgs[idx] + "')";

    var thumbs = card.querySelectorAll('.thumb-gallery img');
    thumbs.forEach(function (t, i) {
        if (i === idx) t.classList.add('active-thumb');
        else t.classList.remove('active-thumb');
    });
};

window.setMainImage = function (rid, idx, path) {
    var card = document.getElementById('card-' + rid);
    if (!card) return;
    card.dataset.currentIndex = idx;

    var mainImg = document.getElementById('main-img-' + rid);
    if (mainImg) mainImg.style.backgroundImage = "url('" + path + "')";

    var thumbs = card.querySelectorAll('.thumb-gallery img');
    thumbs.forEach(function (t, i) {
        if (i === idx) t.classList.add('active-thumb');
        else t.classList.remove('active-thumb');
    });
};

(function () {
    var q = document.getElementById('q');
    if (!q) return;
    var qClear = document.getElementById('qClear'), fSort = document.getElementById('fSort'), fOpen = document.getElementById('fOpen');
    var tabs = document.querySelectorAll('#tabs .type-tab');
    var sections = Array.prototype.slice.call(document.querySelectorAll('.type-section'));
    var emptyState = document.getElementById('emptyState'), resultLine = document.getElementById('resultLine');
    var activeType = 'all';

    function apply() {
        var words = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
        var onlyOpen = fOpen.checked, sort = fSort.value, total = 0, shownAll = 0;
        qClear.style.display = q.value ? 'block' : 'none';

        sections.forEach(function (sec) {
            var grid = sec.querySelector('.grid-3');
            var cards = Array.prototype.slice.call(grid.querySelectorAll('.card'));
            var typeOk = (activeType === 'all' || sec.dataset.section === activeType);
            var shown = 0;

            cards.sort(function (a, b) {
                if (sort === 'price_asc') return a.dataset.price - b.dataset.price;
                if (sort === 'price_desc') return b.dataset.price - a.dataset.price;
                if (sort === 'cap_desc') return b.dataset.cap - a.dataset.cap;
                if (sort === 'name') return a.dataset.name.localeCompare(b.dataset.name);
                if (a.dataset.open !== b.dataset.open) return b.dataset.open - a.dataset.open;
                return b.dataset.order - a.dataset.order;
            }).forEach(function (c) { grid.appendChild(c); });

            cards.forEach(function (c) {
                var ok = typeOk;
                if (ok && words.length) ok = words.every(function (w) { return c.dataset.search.indexOf(w) !== -1; });
                if (ok && onlyOpen) ok = c.dataset.open === '1';
                c.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            sec.style.display = shown ? '' : 'none';
            sec.querySelector('.shown').textContent = shown;
            total += cards.length;
            shownAll += shown;
        });

        emptyState.style.display = shownAll ? 'none' : 'block';
        resultLine.textContent = 'Showing ' + shownAll + ' of ' + total + ' accommodations';
    }

    tabs.forEach(function (t) {
        t.addEventListener('click', function () {
            tabs.forEach(function (x) { x.classList.remove('active'); });
            t.classList.add('active');
            activeType = t.dataset.type;
            apply();
        });
    });
    q.addEventListener('input', apply);
    fSort.addEventListener('change', apply);
    fOpen.addEventListener('change', apply);
    qClear.addEventListener('click', function () { q.value = ''; q.focus(); apply(); });
    document.getElementById('resetAll').addEventListener('click', function () {
        q.value = ''; fSort.value = 'default'; fOpen.checked = false; activeType = 'all';
        tabs.forEach(function (x) { x.classList.toggle('active', x.dataset.type === 'all'); });
        apply();
    });

    var p = new URLSearchParams(location.search);
    if (p.get('q')) q.value = p.get('q');
    if (p.get('type')) tabs.forEach(function (t) { if (t.dataset.type === p.get('type').toLowerCase()) t.click(); });
    apply();
})();
</script>

<script src="js/responsive.js"></script>
</body>
</html>