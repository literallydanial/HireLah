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

    // Auto-verify jobs.salary_rate_type availability (self-healing migration on deploy) --
    // lets a posting's salary be tagged as a daily, weekly, or monthly rate instead of
    // always being assumed monthly, so part-time/gig postings can show the right unit.
    try {
        $pdo->query("SELECT salary_rate_type FROM jobs LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE jobs ADD COLUMN salary_rate_type VARCHAR(20) NOT NULL DEFAULT 'monthly' AFTER salary_max");
        } catch (\Throwable $migErr) {
            error_log("Jobs salary_rate_type migration notice: " . $migErr->getMessage());
        }
    }

    // Auto-verify management role schema & seed account (self-healing migration on deploy)
    try {
        $roleCol = $pdo->query("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role'")->fetch();
        if ($roleCol && strtolower($roleCol['DATA_TYPE']) === 'enum') {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'candidate'");
        }
        $chkMgmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $chkMgmt->execute(['management@thekeria.com']);
        if (!$chkMgmt->fetchColumn()) {
            $hash = password_hash('password123', PASSWORD_DEFAULT);
            $insMgmt = $pdo->prepare("INSERT INTO users (name, email, password_hash, role, is_verified, created_at) VALUES (?, ?, ?, 'management', 1, NOW())");
            $insMgmt->execute(['Management Team', 'management@thekeria.com', $hash]);
        }
    } catch (\Throwable $mgmtErr) {
        error_log("Management role auto-migration notice: " . $mgmtErr->getMessage());
    }


    // Auto-verify jobs.deleted_at availability (self-healing migration on deploy) --
    // jobs.php, index.php and the dashboards filter on it for soft-deleted postings,
    // and a database that never ran database/run_migration_job_soft_delete.php made
    // every one of those pages fail with HTTP 500.
    try {
        $pdo->query("SELECT deleted_at FROM jobs LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("ALTER TABLE jobs ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL");
        } catch (\Throwable $migErr) {
            error_log("jobs.deleted_at migration notice: " . $migErr->getMessage());
        }
    }

    // Auto-verify company profile columns (self-healing migration on deploy) --
    // profile.php's "Save Company Info" writes these to companies and users; a database
    // that never ran database/run_migration_company_profile_fields.php made that save
    // fail with HTTP 500. Columns only: no demo/seed values are written.
    foreach (['companies', 'users'] as $cpTable) {
        foreach ([
            'business_type'  => "VARCHAR(100) NULL DEFAULT 'Private Limited (Sdn. Bhd.)'",
            'industry'       => "VARCHAR(100) NULL DEFAULT 'Information Technology'",
            'contact_number' => "VARCHAR(50) NULL DEFAULT '+60 3-1234 5678'",
            'company_size'   => "VARCHAR(50) NULL DEFAULT '11-50 employees'",
        ] as $cpCol => $cpDef) {
            try {
                $pdo->query("SELECT `$cpCol` FROM `$cpTable` LIMIT 1");
            } catch (\Throwable $e) {
                try {
                    $pdo->exec("ALTER TABLE `$cpTable` ADD COLUMN `$cpCol` $cpDef");
                } catch (\Throwable $migErr) {
                    error_log("$cpTable.$cpCol migration notice: " . $migErr->getMessage());
                }
            }
        }
    }

    // Auto-verify employer verification columns (self-healing migration on deploy).
    // Existing companies default to 'approved' so no active employer is locked out;
    // new self-registered employers are inserted as 'pending' by verify_otp.php.
    try {
        $pdo->query("SELECT verification_status FROM companies LIMIT 1");
    } catch (\Throwable $e) {
        foreach ([
            "ALTER TABLE companies ADD COLUMN verification_status VARCHAR(20) NOT NULL DEFAULT 'approved'",
            "ALTER TABLE companies ADD COLUMN verification_flags VARCHAR(255) NULL",
            "ALTER TABLE companies ADD COLUMN submitted_at DATETIME NULL",
            "ALTER TABLE companies ADD COLUMN claimed_by INT NULL",
            "ALTER TABLE companies ADD COLUMN claimed_at DATETIME NULL",
            "ALTER TABLE companies ADD COLUMN reviewed_by INT NULL",
            "ALTER TABLE companies ADD COLUMN reviewed_at DATETIME NULL",
            "ALTER TABLE companies ADD COLUMN review_note TEXT NULL",
        ] as $ddl) {
            try { $pdo->exec($ddl); } catch (\Throwable $migErr) { error_log("Employer verification migration notice: " . $migErr->getMessage()); }
        }
    }
    try {
        $pdo->query("SELECT otp_attempts FROM users LIMIT 1");
    } catch (\Throwable $e) {
        try { $pdo->exec("ALTER TABLE users ADD COLUMN otp_attempts INT NOT NULL DEFAULT 0"); } catch (\Throwable $migErr) { error_log("otp_attempts migration notice: " . $migErr->getMessage()); }
    }
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS signup_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_address VARCHAR(45) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX (ip_address, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) {
        error_log("signup_attempts migration notice: " . $e->getMessage());
    }

    // Auto-verify gemini_api_logs schema availability (self-healing migration on deploy)
    try {
        require_once __DIR__ . '/gemini_logs_helper.php';
        ensure_gemini_api_logs_table_exists($pdo);
    } catch (\Throwable $e) {
        error_log("gemini_api_logs auto-migration notice: " . $e->getMessage());
    }

    // Auto-verify admin_logs schema availability (self-healing migration on deploy)
    try {
        require_once __DIR__ . '/admin_logs_helper.php';
        ensure_admin_logs_table_exists($pdo);
    } catch (\Throwable $e) {
        error_log("admin_logs auto-migration notice: " . $e->getMessage());
    }

    // Auto-verify resume_builds schema availability (self-healing migration on deploy)
    try {
        $pdo->query("SELECT 1 FROM resume_builds LIMIT 1");
    } catch (\Throwable $e) {
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS `resume_builds` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `user_id` int(11) NOT NULL,
                `target_title` varchar(255) DEFAULT NULL,
                `raw_input` longtext DEFAULT NULL,
                `generated_content` longtext DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                KEY `fk_resumebuild_user` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (\Throwable $migErr) {
            error_log("resume_builds auto-migration notice: " . $migErr->getMessage());
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
