<?php
// auth.php
session_start();
require_once 'db.php';

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        $_SESSION['error'] = "You must be logged in to view this page.";
        header("Location: login.php");
        exit;
    }
}

function require_role($role) {
    require_login();
    if ($_SESSION['user_role'] !== $role) {
        $_SESSION['error'] = "Access denied. You do not have the required role.";
        header("Location: index.php");
        exit;
    }
}
?>
