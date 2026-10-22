<?php
// Visiting /customer/ lands on the customer home page.
// (This file used to redirect to ../admin/resort.php, which sent customers to the admin area.)
header("Location: home.php");
exit();