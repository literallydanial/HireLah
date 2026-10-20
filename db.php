<?php
// =========================================================================
// KERIA DATABASE CONFIGURATION
// =========================================================================
// Fill in these 4 values for your production MySQL server:
// -------------------------------------------------------------------------
define('DB_HOST', 'localhost');          // e.g., your Ubuntu server's MySQL host
define('DB_NAME', 'hirelah');
define('DB_USER', 'root');
define('DB_PASS', '');                   // your MySQL password
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

    // Auto-verify university schema availability (self-healing migration on deploy)
    try {
        $pdo->query("SELECT 1 FROM universities LIMIT 1");
    } catch (\Throwable $e) {
        $sql_file = __DIR__ . '/database/university.sql';
        if (file_exists($sql_file)) {
            try {
                $sql = file_get_contents($sql_file);
                $pdo->exec($sql);
            } catch (\Throwable $migErr) {
                error_log("University auto-migration notice: " . $migErr->getMessage());
            }
        }
    }

    // Auto-verify saved_jobs schema availability (self-healing migration on deploy)
    try {
        $pdo->query("SELECT 1 FROM saved_jobs LIMIT 1");
    } catch (\Throwable $e) {
        $sql_file = __DIR__ . '/database/saved_jobs.sql';
        if (file_exists($sql_file)) {
            try {
                $sql = file_get_contents($sql_file);
                $pdo->exec($sql);
            } catch (\Throwable $migErr) {
                error_log("Saved jobs auto-migration notice: " . $migErr->getMessage());
            }
        }
    }

    // Auto-verify resume_reviews supports guest (ownerless) submissions --
    // user_id was NOT NULL with a foreign key to users, which forced every
    // AI Resume Checker upload to belong to a logged-in account or be
    // dropped entirely (no row ever created) for guests. Making it
    // nullable lets a guest's review be recorded too, with user_id simply
    // empty until an account claims it later.
    try {
        $col = $pdo->query("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resume_reviews' AND COLUMN_NAME = 'user_id'")->fetch();
        if ($col && $col['IS_NULLABLE'] === 'NO') {
            $pdo->exec("ALTER TABLE resume_reviews MODIFY COLUMN user_id INT(11) NULL");
        }
    } catch (\Throwable $e) {
        error_log("resume_reviews user_id nullable migration notice: " . $e->getMessage());
    }
    // Auto-verify resume_reviews.file_path availability -- stores exactly
    // where each upload was saved, instead of admin pages re-finding it
    // later by matching filenames, which can attach the wrong file to the
    // wrong name when two uploads share a filename.
    try {
        $pdo->query("SELECT file_path FROM resume_reviews LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE resume_reviews ADD COLUMN file_path VARCHAR(500) NULL AFTER filename");
        } catch (\Throwable $migErr) {
            error_log("resume_reviews file_path migration notice: " . $migErr->getMessage());
        }
    }

    // Auto-verify ssm_number columns availability (fail-safe on any schema state)
    try {
        $pdo->query("SELECT ssm_number FROM users LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN ssm_number VARCHAR(100) NULL");
        } catch (\Throwable $migErr) {
            error_log("Users ssm_number migration notice: " . $migErr->getMessage());
        }
    }
    try {
        $pdo->query("SELECT ssm_number FROM universities LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE universities ADD COLUMN ssm_number VARCHAR(100) NULL");
        } catch (\Throwable $migErr) {
            error_log("Universities ssm_number migration notice: " . $migErr->getMessage());
        }
    }
    try {
        $pdo->query("SELECT ssm_number FROM companies LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE companies ADD COLUMN ssm_number VARCHAR(100) NULL");
        } catch (\Throwable $migErr) {
            error_log("Companies ssm_number migration notice: " . $migErr->getMessage());
        }
    }

} catch (\PDOException $e) {
    error_log("Keria DB Connection Error: " . $e->getMessage());
    die("<div style='font-family:sans-serif; max-width:600px; margin:50px auto; padding:24px; border:1px solid #F87171; background:#FEF2F2; color:#991B1B; border-radius:12px; text-align:center;'>" .
        "<h3 style='margin-top:0;'>⚠️ Service Temporarily Unavailable</h3>" .
        "<p>Keria is currently unable to connect to the database server.</p>" .
        "<p style='font-size:13px; color:#6B7280;'>If you are the administrator, please inspect <code>db.php</code> and verify your MySQL credentials.</p>" .
        "</div>");
}
?>
