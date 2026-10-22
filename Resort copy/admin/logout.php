<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_GET["confirm"]) && $_GET["confirm"] === "yes") {
    $_SESSION = array();

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }

    session_destroy();
    header("Location: ../login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out | Pergola Resort Admin</title>
</head>
<body>
    <script>
        if (confirm("Are you sure you want to log out?")) {
            window.location.href = "logout.php?confirm=yes";
        } else {
            window.location.href = "dashboard.php";
        }
    </script>
</body>
</html>