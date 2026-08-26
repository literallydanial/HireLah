<?php
// employer_helpers.php
// Resolves an employer's candidate-facing display name and contact email,
// each falling back to the personal account field when the employer hasn't
// set a Company Settings override.
function get_employer_contact($pdo, $employer_id) {
    $stmt = $pdo->prepare("SELECT COALESCE(NULLIF(company_name, ''), name) as display_name, COALESCE(NULLIF(contact_email, ''), email) as contact_email FROM users WHERE id = ?");
    $stmt->execute([$employer_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return [
        'name' => $row['display_name'] ?? 'Hiring Manager',
        'email' => $row['contact_email'] ?? '',
    ];
}
