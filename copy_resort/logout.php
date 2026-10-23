<?php
// Customer logout: GET shows a confirmation, POST + CSRF token really ends the session.
require_once "config.php";
require_once "payment_lib.php";

pay_logout_page("login.php", "customer/dashboard.php", "logout.php");
