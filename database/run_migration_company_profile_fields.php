<?php
// ONE-TIME MIGRATION — safe to run more than once (idempotent).
// Adds Company Profile fields:
//   - business_type (e.g. Private Limited (Sdn. Bhd.))
//   - industry (e.g. Information Technology)
//   - contact_number (e.g. +60 3-1234 5678)
//   - company_size (e.g. 11-50 employees)
// to `companies` and `users` tables.

require_once __DIR__ . '/../db.php';
header('Content-Type: text/plain');

function col_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    echo "=== Running Company Profile Fields Migration ===\n\n";

    $fields = [
        'business_type' => "VARCHAR(100) NULL DEFAULT 'Private Limited (Sdn. Bhd.)'",
        'industry' => "VARCHAR(100) NULL DEFAULT 'Information Technology'",
        'contact_number' => "VARCHAR(50) NULL DEFAULT '+60 3-1234 5678'",
        'company_size' => "VARCHAR(50) NULL DEFAULT '11-50 employees'"
    ];

    foreach ($fields as $col => $typeDef) {
        if (!col_exists($pdo, 'companies', $col)) {
            $pdo->exec("ALTER TABLE `companies` ADD COLUMN `$col` $typeDef");
            echo "OK: Added $col to companies table.\n";
        } else {
            echo "SKIP: companies.$col already exists.\n";
        }

        if (!col_exists($pdo, 'users', $col)) {
            $pdo->exec("ALTER TABLE `users` ADD COLUMN `$col` $typeDef");
            echo "OK: Added $col to users table.\n";
        } else {
            echo "SKIP: users.$col already exists.\n";
        }
    }

    // Populate realistic default SSM / company details for existing seed company 1 (Gajah Kapitalan) if empty
    $pdo->exec("UPDATE companies SET 
        ssm_number = COALESCE(NULLIF(ssm_number, ''), '202301012345 (1234567-X)'),
        business_type = COALESCE(NULLIF(business_type, ''), 'Private Limited (Sdn. Bhd.)'),
        industry = COALESCE(NULLIF(industry, ''), 'Information Technology'),
        contact_number = COALESCE(NULLIF(contact_number, ''), '+60 3-1234 5678'),
        company_size = COALESCE(NULLIF(company_size, ''), '11-50 employees'),
        address = COALESCE(NULLIF(address, ''), 'Cyberjaya, Selangor, Malaysia'),
        contact_email = COALESCE(NULLIF(contact_email, ''), 'hr@gajahkapitalan.com')
        WHERE id = 1");

    echo "\n=== Migration Finished Successfully ===\n";
} catch (Exception $e) {
    http_response_code(500);
    echo "ERROR: " . $e->getMessage() . "\n";
}
