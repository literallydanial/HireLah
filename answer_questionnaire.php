<?php
session_start();
require_once 'db.php';
require_once 'questionnaire_helpers.php';

$token = $_GET['token'] ?? $_GET['request_id'] ?? $_GET['id'] ?? null;
if (!$token) {
    echo "Invalid or expired questionnaire link.";
    exit;
}

// Fetch request details
$stmt = $pdo->prepare("SELECT qr.*, q.title, q.description, q.questions_json, c.name as candidate_name, j.job_title FROM questionnaire_requests qr JOIN questionnaires q ON qr.questionnaire_id = q.id JOIN candidates c ON qr.candidate_id = c.id LEFT JOIN jobs j ON c.job_id = j.id WHERE qr.id = ?");
$stmt->execute([$token]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    echo "Questionnaire not found.";
    exit;
}

$questions = array_map('normalize_question', json_decode($request['questions_json'], true) ?: []);
$submitted = $request['status'] === 'Submitted';
$existing_answers = json_decode($request['answers_json'] ?? '[]', true) ?: [];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$submitted) {
    $raw_answers = $_POST['answers'] ?? [];
    $clean_answers = [];
    foreach ($questions as $idx => $q) {
        $val = $raw_answers[$idx] ?? ($q['type'] === 'checkbox' ? [] : '');
        if ($q['type'] === 'checkbox') {
            $clean_answers[$idx] = is_array($val) ? array_values(array_map('trim', $val)) : [];
        } else {
            $clean_answers[$idx] = is_array($val) ? '' : trim($val);
        }
    }

    $json_answers = json_encode($clean_answers);
    $update_stmt = $pdo->prepare("UPDATE questionnaire_requests SET status = 'Submitted', answers_json = ?, submitted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $update_stmt->execute([$json_answers, $token]);

    $_SESSION['toast'] = "Thank you! Your responses have been submitted to the employer.";
    header("Location: answer_questionnaire.php?token=$token");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($request['title']) ?> - HireLah</title>
    <link rel="stylesheet" href="style.css?v=<?php echo @filemtime(__DIR__.'/style.css'); ?>">
    <style>
        .q-box {
            background: var(--surf);
            border: 1px solid var(--bdr);
            border-radius: 12px;
            padding: 18px;
            margin-bottom: 18px;
        }
    </style>
</head>
<body style="display:flex; align-items:center; justify-content:center; min-height:100vh; padding:40px 20px;">
    <div class="bg-watermark-logo"><img src="logo/logo.png?v=<?php echo @filemtime(__DIR__.'/logo/logo.png'); ?>" alt="HireLah Watermark Logo"></div>
    <div class="panel" style="max-width:650px; width:100%; position:relative; z-index:2;">
        <div style="margin-bottom:20px; display:flex; align-items:center; justify-content:space-between;">
            <div style="font-size:12px; font-weight:700; color:var(--acc);">HireLah Candidate Portal</div>
            <span class="chip" style="background:var(--dim); color:var(--txt); font-size:11px;"><?= htmlspecialchars($request['job_title'] ?? 'Job Role') ?></span>
        </div>

        <div style="font-size:22px; font-weight:800; color:var(--txt); margin-bottom:6px;"><?= htmlspecialchars($request['title']) ?></div>
        <?php if (!empty($request['description'])): ?>
            <div style="font-size:13px; color:var(--txt); background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:12px 16px; margin-bottom:14px; line-height:1.5;">
                <?= nl2br(htmlspecialchars($request['description'])) ?>
            </div>
        <?php endif; ?>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">
            Applicant: <strong><?= htmlspecialchars($request['candidate_name']) ?></strong>
        </div>

        <?php if(isset($_SESSION['toast'])): ?>
            <div class="toast-notification">
                <span class="toast-icon-badge">🌿</span>
                <span><?= htmlspecialchars($_SESSION['toast']) ?></span>
                <button type="button" class="toast-close-btn" onclick="this.parentElement.remove()">✕</button>
                <?php unset($_SESSION['toast']); ?>
            </div>
        <?php endif; ?>

        <?php if($submitted): ?>
            <div style="background:var(--toast-bg); border:1px solid var(--toast-bdr); border-radius:12px; padding:20px; margin-bottom:24px;">
                <div style="font-size:16px; font-weight:800; color:var(--toast-txt); margin-bottom:6px;">🌿 Questionnaire Completed</div>
                <div style="font-size:13px; color:var(--toast-txt); opacity:0.9;">Your answers were submitted on <?= date('M d, Y H:i', strtotime($request['submitted_at'])) ?>.</div>
            </div>

            <div style="font-size:15px; font-weight:700; color:var(--txt); margin-bottom:16px;">Your Submitted Answers:</div>

            <?php foreach($questions as $idx => $q): ?>
                <?php
                    $ans_val = $existing_answers[$idx] ?? ($q['type'] === 'checkbox' ? [] : '-');
                    $ans_display = is_array($ans_val) ? (empty($ans_val) ? '-' : implode(', ', $ans_val)) : ($ans_val === '' ? '-' : $ans_val);
                ?>
                <div class="q-box">
                    <div style="font-size:13px; font-weight:700; color:var(--acc); margin-bottom:8px;">Q<?= $idx + 1 ?>. <?= htmlspecialchars($q['text']) ?></div>
                    <div style="font-size:13px; color:var(--txt); background:var(--card); padding:10px 14px; border-radius:8px; border:1px solid var(--bdr);">
                        <?= nl2br(htmlspecialchars($ans_display)) ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <a href="candidate_dashboard.php" class="btn-primary" style="display:inline-block; text-align:center; padding:12px 24px; text-decoration:none; margin-top:10px;">Return to Home</a>
        <?php else: ?>
            <form method="POST" onsubmit="return validateQuestionnaireAnswers();">
                <?php foreach($questions as $idx => $q): ?>
                    <div class="q-box">
                        <label style="display:block; font-size:13px; font-weight:700; color:var(--txt); margin-bottom:10px;">
                            Q<?= $idx + 1 ?>. <?= htmlspecialchars($q['text']) ?> <?php if($q['required']): ?><span style="color:var(--red)">*</span><?php endif; ?>
                        </label>

                        <?php if ($q['type'] === 'short_text'): ?>
                            <input type="text" name="answers[<?= $idx ?>]" placeholder="Type your answer here..." <?= $q['required'] ? 'required' : '' ?> style="margin:0;">

                        <?php elseif ($q['type'] === 'number'): ?>
                            <input type="number" name="answers[<?= $idx ?>]" placeholder="Enter a number..." <?= $q['required'] ? 'required' : '' ?> style="margin:0;">

                        <?php elseif ($q['type'] === 'multiple_choice'): ?>
                            <div>
                                <?php foreach($q['options'] as $opt): ?>
                                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:13px; color:var(--txt); font-weight:400;">
                                        <input type="radio" name="answers[<?= $idx ?>]" value="<?= htmlspecialchars($opt) ?>" <?= $q['required'] ? 'required' : '' ?> style="width:auto; margin:0;">
                                        <?= htmlspecialchars($opt) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($q['type'] === 'checkbox'): ?>
                            <div class="q-checkbox-group" data-required="<?= $q['required'] ? '1' : '0' ?>">
                                <?php foreach($q['options'] as $opt): ?>
                                    <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px; font-size:13px; color:var(--txt); font-weight:400;">
                                        <input type="checkbox" name="answers[<?= $idx ?>][]" value="<?= htmlspecialchars($opt) ?>" style="width:auto; margin:0;">
                                        <?= htmlspecialchars($opt) ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>

                        <?php elseif ($q['type'] === 'rating_scale'): ?>
                            <div style="display:flex; gap:16px;">
                                <?php for($star = 1; $star <= 5; $star++): ?>
                                    <label style="display:flex; flex-direction:column; align-items:center; gap:4px; font-size:12px; color:var(--txt); font-weight:400;">
                                        <input type="radio" name="answers[<?= $idx ?>]" value="<?= $star ?>" <?= $q['required'] ? 'required' : '' ?> style="width:auto; margin:0;">
                                        <?= str_repeat('⭐', $star) ?>
                                    </label>
                                <?php endfor; ?>
                            </div>

                        <?php elseif ($q['type'] === 'yes_no'): ?>
                            <div style="display:flex; gap:20px;">
                                <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--txt); font-weight:400;">
                                    <input type="radio" name="answers[<?= $idx ?>]" value="Yes" <?= $q['required'] ? 'required' : '' ?> style="width:auto; margin:0;"> Yes
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:var(--txt); font-weight:400;">
                                    <input type="radio" name="answers[<?= $idx ?>]" value="No" <?= $q['required'] ? 'required' : '' ?> style="width:auto; margin:0;"> No
                                </label>
                            </div>

                        <?php else: ?>
                            <textarea name="answers[<?= $idx ?>]" rows="3" placeholder="Type your answer here..." <?= $q['required'] ? 'required' : '' ?> style="margin:0;"></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn-primary" style="padding:14px; font-size:14px; width:100%; margin-top:10px;">Submit Answers to Employer &rarr;</button>
            </form>
        <?php endif; ?>
    </div>
    <script>
        function validateQuestionnaireAnswers() {
            const groups = document.querySelectorAll('.q-checkbox-group[data-required="1"]');
            for (const group of groups) {
                const checked = group.querySelectorAll('input[type="checkbox"]:checked');
                if (checked.length === 0) {
                    alert('Please select at least one option for all required questions.');
                    return false;
                }
            }
            return true;
        }
    </script>
    <script src="theme.js"></script>
</body>
</html>
