<?php
// join_company.php?invite=TOKEN
// Landing point for a company's invite link/QR code.
//   - Logged out visitor            -> sends them to register.php, carrying
//                                      the invite so it applies after they
//                                      verify (or after they log in, if they
//                                      already have an account).
//   - Logged in as an employer      -> instant join as HR, no approval step.
//   - Logged in as anything else    -> friendly explanation, can't join as HR
//                                      on a candidate/admin account.
session_start();
require_once 'db.php';
require_once 'company_helpers.php';

$token = $_GET['invite'] ?? null;
$company = $token ? get_company_by_invite_token($pdo, $token) : null;

if (!$company) {
    $_SESSION['toast'] = "This invite link is invalid or has been revoked. Ask your team admin for a fresh one.";
    header("Location: login.php");
    exit;
}

if (empty($_SESSION['user_id'])) {
    $_SESSION['pending_invite_token'] = $token;
    header("Location: register.php?invite=" . urlencode($token));
    exit;
}

if (($_SESSION['user_role'] ?? '') !== 'employer') {
    $_SESSION['toast'] = "This invite is for joining " . $company['name'] . " as an HR teammate, which needs an employer account. You're logged in as a " . htmlspecialchars($_SESSION['user_role'] ?? 'user') . ".";
    header("Location: index.php");
    exit;
}

join_company($pdo, $_SESSION['user_id'], $company['id']);
$_SESSION['toast'] = "You've joined " . $company['name'] . " as an HR teammate!";
header("Location: employer_dashboard.php");
exit;
