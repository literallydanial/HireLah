<?php
// company_switcher.php — dropdown for an HR who belongs to more than one
// company to switch which one they're currently acting as. Include this
// from any employer-facing page's header, right after the main <nav>.
// Expects $pdo (from db.php) and an active session to already exist.
if (!function_exists('get_user_companies')) {
    require_once __DIR__ . '/company_helpers.php';
}
if (($_SESSION['user_role'] ?? '') === 'employer') {
    $__switcher_companies = get_user_companies($pdo, $_SESSION['user_id']);
    if (count($__switcher_companies) > 1) {
        $__switcher_active_id = get_active_company_id($pdo);
        $__switcher_return = basename($_SERVER['SCRIPT_NAME']);
?>
        <div style="position:relative; margin-left:12px;">
            <select onchange="if(this.value) window.location.href='switch_company.php?company_id='+this.value+'&return=<?= urlencode($__switcher_return) ?>';"
                    style="font-size:12.5px; font-weight:700; padding:8px 10px; border-radius:8px; border:1px solid var(--bdr); background:var(--surf); color:var(--txt); cursor:pointer; max-width:180px;"
                    title="Switch company workspace">
                <?php foreach ($__switcher_companies as $__c): ?>
                    <option value="<?= (int)$__c['id'] ?>" <?= (int)$__c['id'] === (int)$__switcher_active_id ? 'selected' : '' ?>>
                        🏢 <?= htmlspecialchars($__c['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
<?php
    }
}
