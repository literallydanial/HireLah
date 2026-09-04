<?php
// switch_company.php?company_id=N&return=some_page.php
// Changes which company an HR who belongs to more than one is currently
// acting as. Used by the company switcher dropdown shown on employer pages.
require_once 'auth.php';
require_role('employer');
require_once 'company_helpers.php';

$company_id = $_GET['company_id'] ?? null;
$return = $_GET['return'] ?? 'employer_dashboard.php';

// Only ever allow relative, same-app redirects — never an absolute/external
// URL a link could smuggle in via the return param.
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[^\s]*)?$/', $return)) {
    $return = 'employer_dashboard.php';
}

if ($company_id && switch_active_company($pdo, $_SESSION['user_id'], (int)$company_id)) {
    $_SESSION['toast'] = "Switched company workspace.";
} else {
    $_SESSION['error'] = "You don't belong to that company.";
}

header("Location: " . $return);
exit;
