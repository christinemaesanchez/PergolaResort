<?php
require_once "config.php";

$error = "";
$success = "";

if (isset($_GET["registered"])) {
    $success = "Registration successful! You can now log in.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $login = trim($_POST["login"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($login) || empty($password)) {

        $error = "Please enter your login information.";

    } else {

        $stmt = $conn->prepare(
            "SELECT id, full_name, username, password, role, status 
             FROM users 
             WHERE username = ? OR email = ?"
        );

        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 1) {

            $user = $result->fetch_assoc();

            // Verify password
            if (($user["status"] ?? "active") !== "active" && password_verify($password, $user["password"])) {

                // Only reveal that the account is inactive after the correct password was entered
                $error = "This account is inactive. Please contact the resort.";

            } elseif (password_verify($password, $user["password"])) {

                // Regenerate session ID for security
                session_regenerate_id(true);

                // Store user information in session
                $_SESSION["user_id"] = $user["id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["username"] = $user["username"];
                $_SESSION["role"] = $user["role"];

                // Redirect based on user role
                if ($user["role"] === "admin") {

                    header("Location: admin/dashboard.php");
                    exit();

                } elseif ($user["role"] === "customer") {

                    header("Location: customer/dashboard.php");
                    exit();

                } else {

                    // Unknown role
                    $error = "Your account has an invalid role.";

                }

            } else {

                $error = "Invalid username or password.";

            }

        } else {

            $error = "Invalid username or password.";

        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login | Pergola Resort</title>

    <link
        rel="stylesheet"
        href="css/style.css"
    >

</head>

<body>

<div class="auth-wrapper">

    <div class="auth-container">

        <div class="brand">

            <h1>Pergola Resort</h1>

            <p>Book your relaxing getaway.</p>

        </div>

        <h2>Welcome Back</h2>

        <?php if (!empty($error)): ?>

            <div class="message error-message">

                <?php
                echo htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>

            </div>

        <?php endif; ?>

        <?php if (!empty($success)): ?>

            <div class="message success-message">

                <?php
                echo htmlspecialchars(
                    $success,
                    ENT_QUOTES,
                    'UTF-8'
                );
                ?>

            </div>

        <?php endif; ?>

        <form method="POST" action="login.php">

            <div class="form-group">

                <label for="login">
                    Username or Email
                </label>

                <input
                    type="text"
                    id="login"
                    name="login"
                    placeholder="Enter Username or Email"
                    required
                >

            </div>

            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <input
                    type="password"
                    id="password"
                    name="password"
                    placeholder="Enter Password"
                    required
                >

            </div>

            <div class="forgot-link">

                <a href="forgot_password.php">
                    Forgot Password?
                </a>

            </div>

            <button type="submit" class="btn">
                Login
            </button>

        </form>

        <p class="bottom-text">

            Don't have an account?

            <a href="register.php">
                Register here
            </a>

        </p>

    </div>

</div>

</body>

</html>