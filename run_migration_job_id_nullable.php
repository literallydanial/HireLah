<?php
// ONE-TIME MIGRATION — safe to run more than once, delete this file afterward.
// Fixes: "SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'job_id'
// cannot be null" when saving a questionnaire template with no job assigned.
require 'db.php';
header('Content-Type: text/plain');

try {
    $pdo->exec("ALTER TABLE `questionnaires` MODIFY `job_id` INT(11) NULL");
    echo "SUCCESS: `questionnaires`.`job_id` is now nullable.\n";
    echo "You can now save a questionnaire template without assigning a job.\n";
    echo "\nPlease delete this file (run_migration_job_id_nullable.php) now that it has run.\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
