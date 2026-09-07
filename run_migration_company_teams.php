<?php
// ONE-TIME MIGRATION — safe to run more than once (idempotent), delete this
// file afterward.
//
// Adds multi-HR-per-company support:
//   - `companies` table (one row per company, holds a rotating invite_token)
//   - `company_members` table (many-to-many: a user can belong to several
//      companies, each with a role of 'admin' or 'hr')
//   - `company_id` column on `jobs` and `questionnaires` so all HR under the
//      same company share the same postings/candidates/templates
//
// Backfills every existing employer account into its own new company as the
// sole 'admin', preserving current behaviour exactly (nothing changes for
// existing accounts until they invite a teammate).
require 'db.php';
header('Content-Type: text/plain');

function column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        website VARCHAR(255) NULL,
        address TEXT NULL,
        logo VARCHAR(255) NULL,
        contact_email VARCHAR(255) NULL,
        invite_token VARCHAR(64) NULL UNIQUE,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: companies table ready.\n";

    $pdo->exec("CREATE TABLE IF NOT EXISTS company_members (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        user_id INT NOT NULL,
        role VARCHAR(20) NOT NULL DEFAULT 'hr',
        joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_company_user (company_id, user_id),
        KEY idx_user (user_id),
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "OK: company_members table ready.\n";

    if (!column_exists($pdo, 'jobs', 'company_id')) {
        $pdo->exec("ALTER TABLE jobs ADD COLUMN company_id INT NULL AFTER employer_id");
        echo "OK: jobs.company_id added.\n";
    } else {
        echo "SKIP: jobs.company_id already exists.\n";
    }

    if (!column_exists($pdo, 'questionnaires', 'company_id')) {
        $pdo->exec("ALTER TABLE questionnaires ADD COLUMN company_id INT NULL AFTER employer_id");
        echo "OK: questionnaires.company_id added.\n";
    } else {
        echo "SKIP: questionnaires.company_id already exists.\n";
    }

    // Backfill: every employer not yet in company_members gets their own
    // brand-new company (named after their existing company_name, or their
    // personal name as a fallback) and becomes its sole admin.
    $employers = $pdo->query("
        SELECT u.id, u.name, u.company_name, u.company_website, u.company_address, u.company_logo, u.contact_email
        FROM users u
        LEFT JOIN company_members cm ON cm.user_id = u.id
        WHERE u.role = 'employer' AND cm.id IS NULL
    ")->fetchAll(PDO::FETCH_ASSOC);

    $created = 0;
    foreach ($employers as $u) {
        $company_name = trim($u['company_name'] ?? '') !== '' ? $u['company_name'] : ($u['name'] . "'s Company");
        $token = bin2hex(random_bytes(20));

        $ins = $pdo->prepare("INSERT INTO companies (name, website, address, logo, contact_email, invite_token, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$company_name, $u['company_website'], $u['company_address'], $u['company_logo'], $u['contact_email'], $token, $u['id']]);
        $company_id = $pdo->lastInsertId();

        $mem = $pdo->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (?, ?, 'admin')");
        $mem->execute([$company_id, $u['id']]);

        $created++;
    }
    echo "OK: backfilled $created existing employer(s) into their own company as admin.\n";

    // Backfill jobs.company_id / questionnaires.company_id from the poster's
    // (now-admin) company, wherever it's still NULL.
    $j = $pdo->exec("
        UPDATE jobs j
        JOIN company_members cm ON cm.user_id = j.employer_id AND cm.role = 'admin'
        SET j.company_id = cm.company_id
        WHERE j.company_id IS NULL
    ");
    echo "OK: backfilled company_id on $j job row(s).\n";

    $q = $pdo->exec("
        UPDATE questionnaires q
        JOIN company_members cm ON cm.user_id = q.employer_id AND cm.role = 'admin'
        SET q.company_id = cm.company_id
        WHERE q.company_id IS NULL
    ");
    echo "OK: backfilled company_id on $q questionnaire row(s).\n";

    echo "\nMIGRATION COMPLETE. Please delete this file (run_migration_company_teams.php) now that it has run.\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
