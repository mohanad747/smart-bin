<?php
// Log out: clear the session and go back to the login page
require __DIR__ . '/lib/auth.php';

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
