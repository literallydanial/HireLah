<?php
// =========================================================================
// HIRELAH DATABASE CONFIGURATION
// =========================================================================
// When publishing to InfinityFree, fill in these 4 values from your
// InfinityFree MySQL Control Panel (DO NOT USE getenv or localhost):
// -------------------------------------------------------------------------
define('DB_HOST', 'localhost');          // e.g., 'sql123.infinityfree.com'
define('DB_NAME', 'hirelah');            // e.g., 'if0_12345678_hirelah'
define('DB_USER', 'root');               // e.g., 'if0_12345678'
define('DB_PASS', '');                   // e.g., 'YourInfinityFreevPanelPassword'
// =========================================================================

// Production Error Handling Settings (Prevents leaking DB credentials or sensitive paths)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);

$charset = 'utf8mb4';
$dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (\PDOException $e) {
    error_log("HireLah DB Connection Error: " . $e->getMessage());
    die("<div style='font-family:sans-serif; max-width:600px; margin:50px auto; padding:24px; border:1px solid #F87171; background:#FEF2F2; color:#991B1B; border-radius:12px; text-align:center;'>" .
        "<h3 style='margin-top:0;'>⚠️ Service Temporarily Unavailable</h3>" .
        "<p>HireLah is currently unable to connect to the database server.</p>" .
        "<p style='font-size:13px; color:#6B7280;'>If you are the administrator, please inspect <code>db.php</code> and verify your InfinityFree MySQL credentials.</p>" .
        "</div>");
}
?>
