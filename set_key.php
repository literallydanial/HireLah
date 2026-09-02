<?php
require_once 'auth.php';
require_role('admin');
require_once 'ai.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $api_key = trim($_POST['api_key'] ?? '');
    $model = trim($_POST['ai_model'] ?? 'gemini-2.5-flash');

    if ($api_key) {
        save_api_config($api_key, $model);
        $_SESSION['toast'] = "Gemini API key saved persistently!";
        header("Location: admin_dashboard.php");
        exit;
    } else {
        $error = "Please enter a valid API key.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Set Google Gemini API Key - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh;">
    <div class="panel" style="max-width:500px; width:100%;">
        <div style="margin-bottom:20px; display:flex; align-items:center; gap:12px;">
            <a href="javascript:history.back()" class="btn-secondary" style="padding:6px 12px; font-size:12px;">&larr; Back</a>
        </div>
        <div style="font-size:22px; font-weight:800; margin-bottom:6px;">🔑 Set Google Gemini API Key</div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:20px;">Enter your Google Gemini API key to enable automated AI candidate screening.</div>

        <?php if(isset($error)): ?>
            <div style="background:rgba(255, 77, 106, 0.1); border:1px solid rgba(255, 77, 106, 0.35); border-radius:8px; padding:10px; margin-bottom:16px; color:var(--red); font-size:13px;">
                ⚠️ <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="margin-bottom:16px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Google Gemini API Key</label>
                <input type="password" name="api_key" placeholder="AIzaSy..." value="<?= htmlspecialchars($_SESSION['api_key'] ?? '') ?>" required>
            </div>
            
            <div style="margin-bottom:20px;">
                <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Select Gemini Model</label>
                <select name="ai_model">
                    <option value="gemini-3.7-flash" <?= ($_SESSION['ai_model'] ?? 'gemini-3.7-flash') === 'gemini-3.7-flash' ? 'selected' : '' ?>>Gemini 3.7 Flash (Recommended)</option>
                    <option value="gemini-3.6-flash" <?= ($_SESSION['ai_model'] ?? '') === 'gemini-3.6-flash' ? 'selected' : '' ?>>Gemini 3.6 Flash (Fast & Reliable)</option>
                    <option value="gemini-flash-latest" <?= ($_SESSION['ai_model'] ?? '') === 'gemini-flash-latest' ? 'selected' : '' ?>>Gemini Flash Latest</option>
                    <option value="gemini-3.1-pro-preview" <?= ($_SESSION['ai_model'] ?? '') === 'gemini-3.1-pro-preview' ? 'selected' : '' ?>>Gemini 3.1 Pro Preview (Most Capable)</option>
                </select>
            </div>

            <button type="submit" class="btn-primary">Save API Key &rarr;</button>
        </form>
    </div>
<script src="theme.js"></script></body>
</html>
