<?php
// ---------- 1. CONNECTION + SESSION ----------
require_once "../config.php";
require_once "../payment_lib.php";   // auth_guard(), e(), csrf_token(), csrf_valid(), pay_handle_upload()
$auth_user = auth_guard($conn, "customer", "../login.php");

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------- 2. CUSTOMER AUTHENTICATION ----------
if (empty($_SESSION["user_id"]) || ($_SESSION["role"] ?? "") !== "customer") {
    header("Location: ../login.php");
    exit();
}

$user_id = (int)$_SESSION["user_id"];
$success = "";
$error = "";

// ---------- 3. HANDLE PROFILE UPDATE ----------
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (empty($_POST) && (int)($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        // PHP drops the whole form when the upload is bigger than post_max_size
        $error = "The file is too large for the server (limit " . ini_get("post_max_size") . ").";
    } elseif (!csrf_valid($_POST["csrf"] ?? null)) {
        $error = "Your session expired. Please refresh the page and try again.";
    } else {
        $name = trim($_POST["name"] ?? "");
        $phone = trim($_POST["phone"] ?? "");
        $address = trim($_POST["address"] ?? "");

        // Validate the text fields first (before any file is uploaded)
        $phone_digits = preg_replace('/[\s\-()]/', '', $phone);   // ignore spaces, dashes, brackets

        if (empty($name)) {
            $error = "Full name is required.";
        } elseif (mb_strlen($name) > 100) {
            $error = "Full name must be 100 characters or fewer.";
        } elseif ($phone !== "" && !preg_match('/^\+?[0-9]{10,13}$/', $phone_digits)) {
            $error = "Please enter a valid contact number, for example 09123456789.";
        } elseif (mb_strlen($address) > 255) {
            $error = "Home address must be 255 characters or fewer.";
        }

        // Fetch current user data for valid ID fallback
        $curr_stmt = $conn->prepare("SELECT valid_id FROM users WHERE id = ?");
        $curr_stmt->bind_param("i", $user_id);
        $curr_stmt->execute();
        $curr_data = $curr_stmt->get_result()->fetch_assoc();
        $curr_stmt->close();

        $valid_id_path = $curr_data["valid_id"] ?? "";
        $new_id_file = "";   // full path of a newly uploaded ID (so we can undo it if saving fails)

        // Handle Valid ID Upload (checks the real file type, size and contents)
        if (empty($error) && isset($_FILES["valid_id_file"]) && $_FILES["valid_id_file"]["error"] !== UPLOAD_ERR_NO_FILE) {
            $up = pay_handle_upload(
                $_FILES["valid_id_file"],
                PAY_RECEIPT_MIMES,                    // JPG, PNG, WEBP, PDF
                PERGOLA_ROOT . "/uploads/ids/",
                "uploads/ids/",
                "id_" . $user_id,
                "public"                              // blocks PHP files; keeps your existing ID links working
            );
            if ($up["ok"]) {
                $valid_id_path = $up["path"];
                $new_id_file = $up["abs"];
            } else {
                $error = $up["error"];
            }
        }

        if (empty($error)) {
            try {
                $upd = $conn->prepare("UPDATE users SET full_name = ?, phone = ?, address = ?, valid_id = ? WHERE id = ?");
                $upd->bind_param("ssssi", $name, $phone, $address, $valid_id_path, $user_id);
                $upd->execute();
                $upd->close();

                $_SESSION["full_name"] = $name;
                $success = "Profile successfully updated.";
            } catch (Throwable $ex) {
                if ($new_id_file !== "") @unlink($new_id_file);   // undo the upload
                error_log("profile.php: " . $ex->getMessage());
                $error = "We couldn't save your profile. Please try again.";
            }
        }
    }
}

// ---------- 4. FETCH USER DATA ----------
$stmt = $conn->prepare("SELECT full_name, email, phone, address, valid_id FROM users WHERE id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// ---------- 5. RESORT INFO ----------
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
    <title>My Profile | <?= e($resort_name) ?></title>
    <link rel="stylesheet" href="../css/customer.css">
    <link rel="stylesheet" href="../css/ui.css">
    <style>
        /* HIDE NOTIFICATION BELLS & EXTRA ICONS */
        .nf-wrap, .nf-bell, .notification-bell, .nav-links i, .nav-links svg {
            display: none !important;
        }

        .section-title {
            border-bottom: 1px solid #ede8dc;
            padding-bottom: 15px;
            margin-bottom: 25px;
        }

        .card {
            border: 1px solid #ede8dc !important;
            border-radius: 12px !important;
            box-shadow: 0 4px 20px rgba(52, 74, 48, 0.04) !important;
        }

        .field {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid #ede8dc;
            border-radius: 8px;
            font-size: 14px;
            background: #f7f5ee;
            color: #344a30;
            box-sizing: border-box;
            outline: none;
            transition: border-color 0.2s ease;
        }
        .field:focus {
            border-color: #6f8761;
            background: #fff;
        }

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
        <a href="home.php" class="nav-brand">
            <h2><?= e($resort_name) ?></h2>
        </a>
        <nav class="nav-links">
            <a href="home.php">Home</a>
            <a href="../accommodations.php">Accommodations</a>
            <a href="dashboard.php">Dashboard</a>
            <a href="my_bookings.php">My Bookings</a>
            <a href="profile.php" class="active">Profile</a>
            <a href="../logout.php" class="nav-btn-outline">Sign Out</a>
        </nav>
    </div>
</header>

<!-- ============ MAIN CONTENT ============ -->
<main class="container" style="padding: 40px 20px; max-width: 700px;">
    
    <div class="section-title">
        <h1>Customer Profile & Verification</h1>
        <p>Update your personal information and upload a valid ID for reservation security and smooth bookings.</p>
    </div>

    <?php if (!empty($success)): ?>
        <div style="background: #e5f4e7; color: #256030; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border: 1px solid #cfe3c7;">
            <?= e($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div style="background: #fbeae8; color: #9c2e25; padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; border: 1px solid #f5c7c2;">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="card" style="padding: 30px;">
        <form method="POST" action="profile.php" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            
            <div style="margin-bottom: 20px;">
                <label for="email" style="display: block; font-weight: 700; margin-bottom: 8px; font-size: 13.5px; color: #344a30;">Email Address (Read-only)</label>
                <input type="email" id="email" value="<?= e($user["email"] ?? "") ?>" disabled style="width: 100%; padding: 11px 14px; border: 1px solid #ede8dc; border-radius: 8px; font-size: 14px; background: #f0ede4; color: #666;">
            </div>

            <div style="margin-bottom: 20px;">
                <label for="name" style="display: block; font-weight: 700; margin-bottom: 8px; font-size: 13.5px; color: #344a30;">Full Name</label>
                <input class="field" type="text" name="name" id="name" required maxlength="100" value="<?= e($user["full_name"] ?? "") ?>" style="background: #fff;">
            </div>

            <div style="display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <label for="phone" style="display: block; font-weight: 700; margin-bottom: 8px; font-size: 13.5px; color: #344a30;">Contact Number / Mobile Number</label>
                    <input class="field" type="text" name="phone" id="phone" maxlength="20" value="<?= e($user["phone"] ?? "") ?>" placeholder="e.g. 09123456789" style="background: #fff;">
                </div>
            </div>

            <div style="margin-bottom: 20px;">
                <label for="address" style="display: block; font-weight: 700; margin-bottom: 8px; font-size: 13.5px; color: #344a30;">Home Address</label>
                <textarea class="field" name="address" id="address" rows="3" maxlength="255" placeholder="Enter your residential address..." style="background: #fff; resize: vertical;"><?= e($user["address"] ?? "") ?></textarea>
            </div>

            <div style="margin-bottom: 25px; background: #f9f8f5; padding: 20px; border-radius: 10px; border: 1px solid #ede8dc;">
                <label for="valid_id_file" style="display: block; font-weight: 700; margin-bottom: 8px; font-size: 13.5px; color: #344a30;">Valid ID for Verification</label>
                <input type="file" name="valid_id_file" id="valid_id_file" accept="image/jpeg,image/png,image/webp,application/pdf" style="width: 100%; padding: 10px; background: #fff; border: 1px dashed #c9b8b8; border-radius: 8px; font-size: 14px; margin-bottom: 10px;">
                <span style="font-size: 12px; color: #666; display: block;">Upload a government-issued ID (Passport, Driver's License, PhilID, etc.). Accepted: JPG, PNG, WEBP, PDF — max 5MB. Leave empty to keep your current ID.</span>
                
                <?php if (!empty($user["valid_id"])): ?>
                    <div style="margin-top: 15px;">
                        <span style="font-size: 13px; font-weight: 700; color: #256030;">Current Valid ID Status: Uploaded</span><br>
                        <a href="../<?= e($user["valid_id"]) ?>" target="_blank" rel="noopener" style="font-size: 13px; color: #0047ff; font-weight: 700; text-decoration: underline;">View Uploaded ID</a>
                    </div>
                <?php else: ?>
                    <div style="margin-top: 10px; font-size: 13px; color: #9c2e25; font-weight: 600;">No Valid ID uploaded yet.</div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn primary-btn full-width" style="padding: 14px; font-size: 16px;">Save Changes</button>
        </form>
    </div>

</main>

<!-- ============ FOOTER ============ -->
<footer class="footer">
    <div class="container">
        <p>&copy; <?= date("Y") ?> <?= e($resort_name) ?>. All rights reserved.</p>
    </div>
</footer>

<script src="../js/app.js" data-base="../" data-role="customer"></script>
<script src="../js/responsive.js"></script>
</body>
</html>