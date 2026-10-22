<?php
require_once "config.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $full_name = trim($_POST["full_name"]);
    $email = trim($_POST["email"]);
    $username = trim($_POST["username"]);
    $password = $_POST["password"];
    $confirm_password = $_POST["confirm_password"];

    if (
        empty($full_name) ||
        empty($email) ||
        empty($username) ||
        empty($password) ||
        empty($confirm_password)
    ) {

        $error = "Please complete all fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 100) {

        $error = "Please enter a valid email address.";

    } elseif (mb_strlen($full_name) > 100 || mb_strlen($username) > 50) {

        $error = "Full name (max 100) or username (max 50) is too long.";

    } elseif (strlen($password) < 8) {

        $error = "Password must be at least 8 characters.";

    } elseif ($password !== $confirm_password) {

        $error = "Passwords do not match.";

    } else {

        $check_email = $conn->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        $check_email->bind_param("s", $email);
        $check_email->execute();
        $email_result = $check_email->get_result();

        if ($email_result->num_rows > 0) {

            $error = "Email already exists.";

        } else {

            $check_username = $conn->prepare(
                "SELECT id FROM users WHERE username = ?"
            );

            $check_username->bind_param("s", $username);
            $check_username->execute();
            $username_result = $check_username->get_result();

            if ($username_result->num_rows > 0) {

                $error = "Username already exists.";

            } else {

                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

               $role = "customer";

$insert = $conn->prepare(
    "INSERT INTO users 
    (full_name, email, username, password, role) 
    VALUES (?, ?, ?, ?, ?)"
);

$insert->bind_param(
    "sssss",
    $full_name,
    $email,
    $username,
    $hashed_password,
    $role
);

                if ($insert->execute()) {

                    header(
                        "Location: login.php?registered=1"
                    );

                    exit();

                } else {

                    $error = "Registration failed. Please try again.";

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

    <meta 
        name="viewport" 
        content="width=device-width, initial-scale=1.0"
    >

    <title>Register | Pergola Resort</title>

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

            <p>Create your account and start booking.</p>

        </div>

        <h2>Create Account</h2>

        <?php if (!empty($error)): ?>

            <div class="message error-message">
                <?php echo htmlspecialchars($error); ?>
            </div>

        <?php endif; ?>

        <form method="POST">

            <div class="form-group">

                <label>Full Name</label>

                <input
                    type="text"
                    name="full_name"
                    placeholder="Enter your full name"
                    value="<?php 
                        echo isset($full_name) 
                        ? htmlspecialchars($full_name) 
                        : ""; 
                    ?>"
                >

            </div>

            <div class="form-group">

                <label>Email</label>

                <input
                    type="email"
                    name="email"
                    placeholder="Enter your email"
                    value="<?php 
                        echo isset($email) 
                        ? htmlspecialchars($email) 
                        : ""; 
                    ?>"
                >

            </div>

        <div class="form-group">
    <label for="username">Username</label>
    <input
        type="text"
        id="username"
        name="username"
        placeholder="Enter Username"
        required
    >
</div>

            <div class="form-group">

                <label>Password</label>

                <input
                    type="password"
                    name="password"
                    placeholder="Create a password"
                >

            </div>

            <div class="form-group">

                <label>Confirm Password</label>

                <input
                    type="password"
                    name="confirm_password"
                    placeholder="Confirm your password"
                >

            </div>

            <button type="submit" class="btn">
                Create Account
            </button>

        </form>

        <p class="bottom-text">

            Already have an account?

            <a href="login.php">
                Login here
            </a>

        </p>

    </div>

</div>

</body>

</html>