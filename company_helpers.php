<?php
// company_helpers.php — multi-HR-per-company support.
//
// Model: an employer `users` row can belong to many `companies` (via the
// `company_members` join table, one row per membership with role 'admin' or
// 'hr'), and a company can have many members. Jobs/candidates/questionnaires
// are scoped by `company_id` so every HR under a company shares the same
// workspace. The session tracks which company is "active" right now
// ($_SESSION['active_company_id']) since one HR account can belong to more
// than one company.

// Returns every company the given user belongs to, with their role in each,
// ordered by when they joined (oldest/first membership first).
function get_user_companies($pdo, $user_id) {
    $stmt = $pdo->prepare("
        SELECT c.id, c.name, c.logo, c.website, c.contact_email, cm.role, cm.joined_at
        FROM company_members cm
        JOIN companies c ON c.id = cm.company_id
        WHERE cm.user_id = ?
        ORDER BY cm.joined_at ASC, cm.id ASC
    ");
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// This user's role ('admin' | 'hr') in a specific company, or null if they
// are not a member of it at all.
function get_company_role($pdo, $user_id, $company_id) {
    $stmt = $pdo->prepare("SELECT role FROM company_members WHERE user_id = ? AND company_id = ?");
    $stmt->execute([$user_id, $company_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['role'] ?? null;
}

// Resolves (and caches in session) which company this employer is currently
// acting as. Falls back to their first/oldest membership if none is set yet,
// and re-resolves if the previously active company is no longer one they
// belong to (e.g. they were removed from it).
function get_active_company_id($pdo) {
    if (empty($_SESSION['user_id'])) return null;
    $user_id = $_SESSION['user_id'];

    if (!empty($_SESSION['active_company_id'])) {
        $role = get_company_role($pdo, $user_id, $_SESSION['active_company_id']);
        if ($role) return $_SESSION['active_company_id'];
        // no longer a member of that company — fall through and re-resolve
        unset($_SESSION['active_company_id']);
    }

    $companies = get_user_companies($pdo, $user_id);
    if (empty($companies)) return null;

    $_SESSION['active_company_id'] = $companies[0]['id'];
    return $_SESSION['active_company_id'];
}

// Switches the session's active company, but only if the user actually
// belongs to it (never trust a raw company_id from a form/query string
// without this check).
function switch_active_company($pdo, $user_id, $company_id) {
    $role = get_company_role($pdo, $user_id, $company_id);
    if (!$role) return false;
    $_SESSION['active_company_id'] = $company_id;
    return true;
}

// Ends the request with a redirect unless the current user is an 'admin' of
// their active company. Call this at the top of any Team-management action.
function require_company_admin($pdo) {
    $company_id = get_active_company_id($pdo);
    $role = $company_id ? get_company_role($pdo, $_SESSION['user_id'], $company_id) : null;
    if ($role !== 'admin') {
        $_SESSION['error'] = "Only a company admin can do that.";
        header("Location: profile.php");
        exit;
    }
    return $company_id;
}

// Creates a brand-new company for a first-time employer signup (no invite
// link involved) and makes them its sole admin. Mirrors the account's
// existing company_name/website/etc fields so nothing looks different to
// them right after registering.
function create_company_for_new_employer($pdo, $user) {
    $company_name = trim($user['company_name'] ?? '') !== '' ? $user['company_name'] : ($user['name'] . "'s Company");
    $token = bin2hex(random_bytes(20));

    $ins = $pdo->prepare("INSERT INTO companies (name, website, address, logo, contact_email, invite_token, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ins->execute([
        $company_name,
        $user['company_website'] ?? null,
        $user['company_address'] ?? null,
        $user['company_logo'] ?? null,
        $user['contact_email'] ?? null,
        $token,
        $user['id'],
    ]);
    $company_id = $pdo->lastInsertId();

    $mem = $pdo->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (?, ?, 'admin')");
    $mem->execute([$company_id, $user['id']]);

    return $company_id;
}

// Looks up a company by its invite token. Returns the company row, or null
// if the token doesn't match anything (revoked/rotated links stop working
// immediately since rotating simply overwrites the stored token).
function get_company_by_invite_token($pdo, $token) {
    if (empty($token)) return null;
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE invite_token = ?");
    $stmt->execute([$token]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Adds $user_id to $company_id as 'hr' (no-op if already a member) and makes
// it their active company. This is the "instant join" step — the invite link
// itself is treated as the permission, no separate admin approval step.
function join_company($pdo, $user_id, $company_id) {
    $existing = get_company_role($pdo, $user_id, $company_id);
    if (!$existing) {
        $ins = $pdo->prepare("INSERT INTO company_members (company_id, user_id, role) VALUES (?, ?, 'hr')");
        $ins->execute([$company_id, $user_id]);
    }
    $_SESSION['active_company_id'] = $company_id;
    return true;
}

// Rotates (or creates) a company's invite link/QR secret. Rotating
// immediately invalidates any previously shared link or QR code.
function regenerate_invite_token($pdo, $company_id) {
    $token = bin2hex(random_bytes(20));
    $stmt = $pdo->prepare("UPDATE companies SET invite_token = ? WHERE id = ?");
    $stmt->execute([$token, $company_id]);
    return $token;
}

// All members of a company (for the Team list in Settings), most senior
// (admin, then earliest joined) first.
function get_company_members($pdo, $company_id) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.email, u.profile_picture, cm.role, cm.joined_at
        FROM company_members cm
        JOIN users u ON u.id = cm.user_id
        WHERE cm.company_id = ?
        ORDER BY (cm.role = 'admin') DESC, cm.joined_at ASC
    ");
    $stmt->execute([$company_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Works out what deleting this employer's account would do to each company
// they belong to. Used both to preview the impact before they confirm
// deletion, and to drive process_employer_departure() afterwards. Per
// company, exactly one of three things happens:
//  - 'delete_company': they're the only member left, so the whole company
//    (and its jobs) is deleted along with them.
//  - 'promote_successor': they're the sole admin but teammates remain, so
//    the earliest-joined teammate is auto-promoted to admin and inherits
//    any jobs this admin personally posted.
//  - 'leave_team': they're a regular HR member (or another admin already
//    exists), so they simply leave; any jobs they personally posted are
//    handed to the company's admin.
function get_employer_deletion_impact($pdo, $user_id) {
    $companies = get_user_companies($pdo, $user_id);
    $impact = [];

    foreach ($companies as $c) {
        $members = get_company_members($pdo, $c['id']);
        $others = array_values(array_filter($members, fn($m) => (int)$m['id'] !== (int)$user_id));

        if (empty($others)) {
            $impact[] = ['id' => $c['id'], 'name' => $c['name'], 'member_count' => count($members), 'action' => 'delete_company'];
            continue;
        }

        $other_admin = null;
        $earliest_hr = null;
        foreach ($others as $m) {
            if ($m['role'] === 'admin' && !$other_admin) $other_admin = $m;
            if ($m['role'] !== 'admin' && !$earliest_hr) $earliest_hr = $m;
        }

        if ($c['role'] === 'admin' && !$other_admin) {
            // Sole admin, teammates remain — earliest-joined teammate takes over.
            $impact[] = ['id' => $c['id'], 'name' => $c['name'], 'member_count' => count($members), 'action' => 'promote_successor', 'successor_id' => $earliest_hr['id'], 'successor_name' => $earliest_hr['name']];
        } else {
            $admin_id = $other_admin['id'] ?? $earliest_hr['id'];
            $impact[] = ['id' => $c['id'], 'name' => $c['name'], 'member_count' => count($members), 'action' => 'leave_team', 'admin_id' => $admin_id];
        }
    }

    return $impact;
}

// Performs the company-side cleanup described by $impact (from
// get_employer_deletion_impact()) for every company this employer belongs
// to. Must run BEFORE the `users` row itself is deleted — company_members
// removes this user's own memberships automatically via ON DELETE CASCADE
// once that happens.
function process_employer_departure($pdo, $user_id, $impact) {
    foreach ($impact as $row) {
        if ($row['action'] === 'delete_company') {
            $logo_stmt = $pdo->prepare("SELECT logo FROM companies WHERE id = ?");
            $logo_stmt->execute([$row['id']]);
            $logo = $logo_stmt->fetchColumn();
            if (!empty($logo) && file_exists($logo)) @unlink($logo);

            $pdo->prepare("DELETE FROM jobs WHERE company_id = ?")->execute([$row['id']]);
            $pdo->prepare("DELETE FROM companies WHERE id = ?")->execute([$row['id']]);
        } elseif ($row['action'] === 'promote_successor') {
            $pdo->prepare("UPDATE company_members SET role = 'admin' WHERE company_id = ? AND user_id = ?")->execute([$row['id'], $row['successor_id']]);
            $pdo->prepare("UPDATE jobs SET employer_id = ? WHERE company_id = ? AND employer_id = ?")->execute([$row['successor_id'], $row['id'], $user_id]);
        } else { // leave_team
            $pdo->prepare("UPDATE jobs SET employer_id = ? WHERE company_id = ? AND employer_id = ?")->execute([$row['admin_id'], $row['id'], $user_id]);
        }
    }

    // Legacy jobs that never migrated into a company workspace — personal
    // to this employer, nobody else can manage them, safe to remove.
    $pdo->prepare("DELETE FROM jobs WHERE company_id IS NULL AND employer_id = ?")->execute([$user_id]);
}
