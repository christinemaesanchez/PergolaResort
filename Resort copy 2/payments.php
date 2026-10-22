<?php
// This file was an outdated copy of admin/payments.php (missing the PayPal handling).
// The maintained page is admin/payments.php, so send visitors there.
header("Location: admin/payments.php" . (!empty($_SERVER["QUERY_STRING"]) ? "?" . $_SERVER["QUERY_STRING"] : ""));
exit();
