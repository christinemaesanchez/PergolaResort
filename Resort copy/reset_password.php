<?php
// Set timezone explicitly so PHP timestamp comparisons are accurate
date_default_timezone_set('Asia/Manila');

require_once "config.php";

$error = "";
$success = "";
$email = "";

$rawToken = $_GET["token"] ?? $_POST["token"] ?? "";$rawToken = trim($_GET["token"] ?? $_POST["token"] ?? "");
if ($rawToken === "") {
    $error = "This password reset link is invalid or missing.";
} else {
    $hashedToken = hash("sha256", $rawToken);

    // Fetch token row directly without relying on MySQL's NOW()
    $stmt = $conn->prepare("SELECT id, email, expires_at FROM password_resets WHERE token = ? LIMIT 1");
    $stmt->bind_param("s", $hashedToken);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows !== 1) {
        $error = "This password reset link is invalid or has already been used.";
    } else {
        $reset = $result->fetch_assoc();

        // Check expiration using PHP's current time
        if (strtotime($reset["expires_at"]) < time()) {
            $error = "This password reset link has expired. Please request a new one.";
        } else {
            $email = $reset["email"];

            if ($_SERVER["REQUEST_METHOD"] === "POST") {
                $newPassword = $_POST["new_password"] ?? "";
                $confirmPassword = $_POST["confirm_password"] ?? "";

                if (empty($newPassword) || empty($confirmPassword)) {
                    $error = "Please fill in all fields.";
                } elseif (strlen($newPassword) < 8) {
                    $error = "Password must be at least 8 characters long.";
                } elseif ($newPassword !== $confirmPassword) {
                    $error = "Passwords do not match.";
                } else {
                    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

                    $update = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
                    $update->bind_param("ss", $hashedPassword, $email);

                    if ($update->execute()) {
                        $delete = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
                        $delete->bind_param("s", $email);
                        $delete->execute();

                        $success = "Your password has been reset successfully!";
                        $email = "";
                    } else {
                        $error = "Unable to reset password. Please try again.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Pergola Resort</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-container">
        <div class="brand">
            <h1>Pergola Resort</h1>
            <p>Create a new secure password.</p>
        </div>

        <?php if (!empty($success)): ?>
            <h2>Password Reset Successful</h2>
            <div class="message success-message"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <p class="bottom-text">You can now log in using your new password.</p>
            <a href="login.php" class="btn" style="display: block; text-align: center; text-decoration: none;">Back to Login</a>

        <?php elseif (!empty($email)): ?>
            <h2>Reset Password</h2>
            <p class="description">Create a new password for your account.</p>

            <?php if (!empty($error)): ?>
                <div class="message error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="POST" action="reset_password.php?token=<?php echo urlencode($rawToken); ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8'); ?>">

                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Enter new password" minlength="8" required>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm new password" minlength="8" required>
                </div>

                <button type="submit" class="btn">Reset Password</button>
            </form>

            <p class="bottom-text"><a href="login.php">Back to Login</a></p>

        <?php else: ?>
            <h2>Reset Password</h2>
            <div class="message error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <p class="bottom-text"><a href="forgot_password.php">Request a New Reset Link</a></p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>