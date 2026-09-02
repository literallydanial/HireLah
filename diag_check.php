<?php
header('Content-Type: text/plain');
require 'db.php';
try {
    $tables = ['company_media', 'resume_reviews'];
    foreach ($tables as $t) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$t'");
        $exists = $stmt->fetch() ? 'YES' : 'NO';
        echo "$t exists: $exists\n";
    }
    if (is_dir('uploads/company_media')) {
        echo "uploads/company_media dir exists: YES\n";
        echo "writable: " . (is_writable('uploads/company_media') ? 'YES' : 'NO') . "\n";
    } else {
        echo "uploads/company_media dir exists: NO\n";
        echo "uploads dir exists: " . (is_dir('uploads') ? 'YES' : 'NO') . "\n";
        echo "uploads writable: " . (is_dir('uploads') && is_writable('uploads') ? 'YES' : 'NO') . "\n";
    }
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
