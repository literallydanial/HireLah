<?php
session_start();

// Unset all session variables
$_SESSION = array();

// If it's desired to kill the session, also delete the session cookie.
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session completely
session_destroy();

// Start fresh session to pass a logout success toast to index.php
session_start();
$_SESSION['toast'] = "You have been logged out successfully.";

header("Location: index.php");
exit;
?>
