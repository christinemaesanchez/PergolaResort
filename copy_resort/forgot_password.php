<?php
// Set timezone explicitly so date calculations match PHP and MySQL
date_default_timezone_set('Asia/Manila');

require_once "config.php";
require_once "vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");

    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {

        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        $message = "If an account exists with that email, a password reset link has been sent.";

        if ($result->num_rows === 1) {

            $rawToken = bin2hex(random_bytes(32));
            $hashedToken = hash("sha256", $rawToken);
            $expires = date("Y-m-d H:i:s", strtotime("+30 minutes"));

            // Remove existing reset records for this user email
            $deleteOld = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
            $deleteOld->bind_param("s", $email);
            $deleteOld->execute();

            // Insert hashed token into database
            $insert = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
            $insert->bind_param("sss", $email, $hashedToken, $expires);

            if ($insert->execute()) {

                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                $host = $_SERVER['HTTP_HOST'];
                $baseDir = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                
                // Build absolute local reset link without double-encoding
                $resetLink = "{$protocol}://{$host}{$baseDir}/reset_password.php?token=" . $rawToken;

                $mail = new PHPMailer(true);

                try {
                    $mail->isSMTP();
                    $mail->Host       = defined('SMTP_HOST') ? SMTP_HOST : 'smtp.gmail.com';
                    $mail->SMTPAuth   = true;
                    $mail->Username   = defined('SMTP_USER') ? SMTP_USER : 'pergola.resort.system@gmail.com';
                    $mail->Password   = defined('SMTP_PASS') ? SMTP_PASS : '';
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $mail->Port       = defined('SMTP_PORT') ? SMTP_PORT : 587;

                    $mail->setFrom($mail->Username, defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Pergola Resort');
                    $mail->addAddress($email);

                    $mail->isHTML(true);
                    $mail->CharSet = "UTF-8";
                    $mail->Subject = "Pergola Resort - Password Reset Request";

                    $mail->Body = "
                    <!DOCTYPE html>
                    <html>
                    <head><meta charset='UTF-8'></head>
                    <body style='margin: 0; padding: 0; background-color: #f4f6f1; font-family: Arial, sans-serif;'>
                        <div style='max-width: 600px; margin: 40px auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 3px 12px rgba(0,0,0,0.08);'>
                            <div style='background-color: #456b3d; padding: 25px; text-align: center;'>
                                <h1 style='margin: 0; color: #ffffff; font-size: 28px;'>Pergola Resort</h1>
                                <p style='margin: 8px 0 0; color: #e8f0e5; font-size: 14px;'>Password Reset Request</p>
                            </div>
                            <div style='padding: 35px; color: #333333;'>
                                <h2 style='color: #456b3d; margin-top: 0;'>Reset Your Password</h2>
                                <p>Hello,</p>
                                <p>We received a request to reset your Pergola Resort password.</p>
                                <p>Click the button below to verify your email and set a new password:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='" . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . "' style='display: inline-block; background-color: #456b3d; color: #ffffff; padding: 14px 28px; border-radius: 7px; text-decoration: none; font-weight: bold;'>Reset Password</a>
                                </div>
                                <p>This link will expire in 30 minutes.</p>
                                <p>If you did not request this, you can ignore this email.</p>
                            </div>
                        </div>
                    </body>
                    </html>";

                    $mail->send();

                } catch (Exception $e) {
                    $deleteToken = $conn->prepare("DELETE FROM password_resets WHERE token = ?");
                    $deleteToken->bind_param("s", $hashedToken);
                    $deleteToken->execute();

                    $error = "Unable to send verification email. Please try again later.";
                    $message = "";
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
    <title>Forgot Password | Pergola Resort</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<div class="auth-wrapper">
    <div class="auth-container">
        <div class="brand">
            <h1>Pergola Resort</h1>
            <p>Reset your account password.</p>
        </div>

        <h2>Forgot Password?</h2>
        <p class="description">Enter your email address below to receive a password reset link.</p>

        <?php if (!empty($error)): ?>
            <div class="message error-message"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="message success-message"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="POST" action="forgot_password.php">
            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="Enter your email" required>
            </div>
            <button type="submit" class="btn">Request Password Reset</button>
        </form>

        <p class="bottom-text">Remember your password? <a href="login.php">Back to Login</a></p>
    </div>
</div>
</body>
</html>