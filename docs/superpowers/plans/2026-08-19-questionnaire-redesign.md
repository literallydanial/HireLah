# Questionnaire Builder Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the employer questionnaire builder (`questionnaire.php`) into a modal matching the supplied mockup — Title, Related Job Title, Status, Description, and per-question Type/Required/Options — and make that Type meaningful end-to-end so candidates answer each question with the right input on `answer_questionnaire.php`.

**Architecture:** Two new nullable-ish columns on `questionnaires` (`description`, `status`) and a format change to `questions_json` from an array of plain strings to an array of `{text, type, required, options}` objects. A shared `questionnaire_helpers.php` provides `normalize_question($q)` so every file that reads a question item (old string or new object) gets a consistent shape back — this is what keeps existing templates and the untouched "quick send custom questions" ad-hoc flow working without a data migration. The builder becomes a real modal (reusing the exact overlay pattern already used by `candidate.php`'s "Send Questions" modal) instead of an always-visible side panel.

**Tech Stack:** PHP (procedural, no framework), PDO/MySQL, plain HTML/CSS/vanilla JS matching existing `questionnaire.php`/`candidate.php` conventions, no build step, no automated test suite — verification is `php -l` plus manual in-browser testing (same approach used for the Company Settings feature earlier this session).

## Global Constraints

- Question type slugs (fixed set, in mockup dropdown order): `short_text`, `long_text`, `multiple_choice`, `checkbox`, `rating_scale`, `yes_no`, `number`.
- `options` only meaningful for `multiple_choice`/`checkbox`; always present as an array (possibly empty) after normalization.
- Rating Scale is fixed 1–5, no configurable range. Number has no min/max. Yes/No is two radio buttons.
- No data migration script — a plain string question item is always treated as `{text: <string>, type: 'long_text', required: true, options: []}` wherever a question is read.
- Status values: `Active` (default) / `Draft`. Draft questionnaires are excluded only from the "Select Saved Questionnaire Template" picker in `candidate.php`; they stay visible/editable in the employer's own Templates Library on `questionnaire.php`.
- The "quick send custom questions" ad-hoc flow in `candidate.php`'s Send Questions modal (Tab 2) is explicitly **out of scope** — it keeps writing plain-string `questions_json` and must keep working unchanged.

---

### Task 1: Database migration for questionnaire columns

**Files:**
- Create: `schema_update9.sql`

**Interfaces:**
- Produces: `questionnaires.description` (TEXT, NULL), `questionnaires.status` (VARCHAR(20), DEFAULT 'Active') — every later task that reads/writes these columns depends on this migration having been applied to the local DB.

- [ ] **Step 1: Write the migration file**

```sql
-- schema_update9.sql
-- Add description and status columns to questionnaires table for the redesigned builder
ALTER TABLE questionnaires ADD COLUMN description TEXT DEFAULT NULL;
ALTER TABLE questionnaires ADD COLUMN status VARCHAR(20) DEFAULT 'Active';
```

- [ ] **Step 2: Apply it to the local XAMPP MySQL database**

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense < schema_update9.sql
```

- [ ] **Step 3: Verify the columns exist**

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense -e "DESCRIBE questionnaires;"
```

Expected: output includes `description` (TEXT, NULL) and `status` (varchar(20)).

- [ ] **Step 4: Commit**

No git repo in this project — skip, per the pattern from the Company Settings plan earlier this session.

---

### Task 2: Shared question-normalization helper

**Files:**
- Create: `questionnaire_helpers.php`

**Interfaces:**
- Produces: `normalize_question($q): array` returning `['text' => string, 'type' => string, 'required' => bool, 'options' => array]`. Every later task that reads a question item from `questions_json` (decoded to a PHP value, either a string or an assoc array) depends on this function.

- [ ] **Step 1: Write the helper**

```php
<?php
// questionnaire_helpers.php
// Shared helper for reading one item out of a decoded questions_json array.
// An item may be a legacy plain string (pre-typed-questions format) or the
// newer {text, type, required, options} object — this always returns the
// newer shape so callers never need to branch on which format they got.
function normalize_question($q) {
    if (is_string($q)) {
        return [
            'text' => $q,
            'type' => 'long_text',
            'required' => true,
            'options' => [],
        ];
    }
    return [
        'text' => $q['text'] ?? '',
        'type' => $q['type'] ?? 'long_text',
        'required' => array_key_exists('required', $q) ? (bool)$q['required'] : true,
        'options' => $q['options'] ?? [],
    ];
}
```

- [ ] **Step 2: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/questionnaire_helpers.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

No git repo — skip.

---

### Task 3: Builder modal shell + Basic Info fields (Status, Description)

**Files:**
- Modify: `questionnaire.php`

**Interfaces:**
- Consumes: `normalize_question()` from Task 2 (require it at the top of `questionnaire.php`).
- Produces: a `#questionnaireModal` overlay containing the form, `openQuestionnaireModal()`/`closeQuestionnaireModal()`/`resetForm()`/`addQuestion(text)`/`removeQuestion(btn)`/`reindexQuestions()`/`updateQuestionsCount()`/`escapeAttr(str)` JS functions, and a `#questionsContainer` div that Task 4 will extend (Task 4 changes `addQuestion`'s signature and the row markup it builds, and changes how the form is submitted — this task still submits via plain `name="questions[]"` inputs). `description` and `status` are persisted by the save handler in this task; question Type/Required/Options are **not** yet — that's Task 4.

- [ ] **Step 1: Require the helper**

In `questionnaire.php`, change:

```php
<?php
require_once 'auth.php';
require_role('employer');
require_once 'notifications_helper.php';
```

to:

```php
<?php
require_once 'auth.php';
require_role('employer');
require_once 'notifications_helper.php';
require_once 'questionnaire_helpers.php';
```

- [ ] **Step 2: Persist `description` and `status` in the save handler**

Change:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_questionnaire') {
    $q_id = $_POST['questionnaire_id'] ?? null;
    $title = trim($_POST['title'] ?? 'Screening Questionnaire');
    $job_id = !empty($_POST['job_id']) ? (int)$_POST['job_id'] : null;
    $raw_questions = $_POST['questions'] ?? [];
    
    $clean_questions = [];
    foreach ($raw_questions as $q) {
        $q_text = trim($q);
        if ($q_text !== '') {
            $clean_questions[] = $q_text;
        }
    }

    if (empty($clean_questions)) {
        $error = "Please add at least one question to the questionnaire.";
    } else {
        $json = json_encode($clean_questions);
        if ($q_id) {
            $update_stmt = $pdo->prepare("UPDATE questionnaires SET title = ?, job_id = ?, questions_json = ? WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
            $update_stmt->execute([$title, $job_id, $json, $q_id, $employer_id]);
            $_SESSION['toast'] = "Questionnaire updated successfully!";
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO questionnaires (job_id, employer_id, title, questions_json) VALUES (?, ?, ?, ?)");
            $insert_stmt->execute([$job_id, $employer_id, $title, $json]);
            $_SESSION['toast'] = "New Questionnaire template saved successfully!";
        }
        header("Location: questionnaire.php");
        exit;
    }
}
```

to:

```php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_questionnaire') {
    $q_id = $_POST['questionnaire_id'] ?? null;
    $title = trim($_POST['title'] ?? 'Screening Questionnaire');
    $job_id = !empty($_POST['job_id']) ? (int)$_POST['job_id'] : null;
    $status = ($_POST['status'] ?? 'Active') === 'Draft' ? 'Draft' : 'Active';
    $description = trim($_POST['description'] ?? '');
    $raw_questions = $_POST['questions'] ?? [];
    
    $clean_questions = [];
    foreach ($raw_questions as $q) {
        $q_text = trim($q);
        if ($q_text !== '') {
            $clean_questions[] = $q_text;
        }
    }

    if (empty($clean_questions)) {
        $error = "Please add at least one question to the questionnaire.";
    } else {
        $json = json_encode($clean_questions);
        if ($q_id) {
            $update_stmt = $pdo->prepare("UPDATE questionnaires SET title = ?, job_id = ?, status = ?, description = ?, questions_json = ? WHERE id = ? AND (employer_id = ? OR employer_id IS NULL)");
            $update_stmt->execute([$title, $job_id, $status, $description, $json, $q_id, $employer_id]);
            $_SESSION['toast'] = "Questionnaire updated successfully!";
        } else {
            $insert_stmt = $pdo->prepare("INSERT INTO questionnaires (job_id, employer_id, title, status, description, questions_json) VALUES (?, ?, ?, ?, ?, ?)");
            $insert_stmt->execute([$job_id, $employer_id, $title, $status, $description, $json]);
            $_SESSION['toast'] = "New Questionnaire template saved successfully!";
        }
        header("Location: questionnaire.php");
        exit;
    }
}
```

- [ ] **Step 3: Replace the two-column layout with a single Templates column + modal**

Change (the entire `.grid-2` block, from the opening `<div class="grid-2" ...>` through its matching closing `</div>` right before `</main>`):

```php
        <div class="grid-2" style="grid-template-columns: 1fr 1fr; gap:28px;">
            
            <!-- Saved Questionnaire Templates List -->
            <div class="templates-wrapper-col">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                    <div style="font-size:16px; font-weight:800; color:var(--txt);">📁 Saved Templates Library (<?= count($questionnaires) ?>)</div>
                    <div class="swipe-hint" style="font-size:11px; color:var(--acc); font-weight:600;">Swipe cards 👈 👉</div>
                </div>

                <?php if(empty($questionnaires)): ?>
                    <div class="panel" style="text-align:center; padding:40px 20px; border-style:dashed;">
                        <div style="font-size:36px; margin-bottom:10px;">📋</div>
                        <div style="font-size:16px; font-weight:700; color:var(--txt); margin-bottom:4px;">No Questionnaires Saved Yet</div>
                        <p style="font-size:12px; color:var(--mut); margin-bottom:0;">Fill out the form on the right to save your first question template.</p>
                    </div>
                <?php else: ?>
                    <div class="templates-slider-container">
                        <?php foreach($questionnaires as $q): ?>
                            <?php 
                                $q_list = json_decode($q['questions_json'], true) ?: []; 
                            ?>
                            <div class="template-card">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                    <div>
                                        <div style="font-size:17px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($q['title']) ?></div>
                                        <div style="font-size:11px; color:var(--mut); margin-top:2px;">
                                            <?= !empty($q['job_title']) ? '💼 ' . htmlspecialchars($q['job_title']) : '🌐 Reusable Template (All Jobs)' ?>
                                        </div>
                                    </div>
                                    <span class="chip" style="background:rgba(59, 130, 246, 0.1); color:var(--acc); border-color:rgba(59, 130, 246, 0.3);">
                                        <?= count($q_list) ?> Questions
                                    </span>
                                </div>

                                <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:8px; padding:10px 14px; margin-top:12px; margin-bottom:14px; max-height:110px; overflow-y:auto;">
                                    <?php foreach($q_list as $idx => $q_item): ?>
                                        <div style="font-size:12px; color:var(--txt); margin-bottom:4px;">
                                            <strong style="color:var(--acc);">Q<?= $idx + 1 ?>:</strong> <?= htmlspecialchars($q_item) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div style="display:flex; gap:8px; justify-content:flex-end; align-items:center;">
                                    <a href="questionnaire.php?edit=<?= $q['id'] ?>#editorForm" class="btn-secondary" style="padding:5px 12px; font-size:11px; text-decoration:none;">✏️ Edit Template</a>
                                    
                                    <form method="POST" onsubmit="return confirm('Are you sure you want to delete this questionnaire template?');" style="margin:0;">
                                        <input type="hidden" name="action" value="delete_questionnaire">
                                        <input type="hidden" name="questionnaire_id" value="<?= $q['id'] ?>">
                                        <button type="submit" class="btn-secondary" style="padding:5px 12px; font-size:11px; color:var(--red); border-color:rgba(255,77,106,0.3);">🗑️ Delete</button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Create / Edit Questionnaire Form -->
            <div class="panel" id="editorForm" style="height:fit-content;">
                <div class="panel-title" id="formTitle">
                    <?= $editing_q ? '✏️ Edit Questionnaire Template' : '➕ Create & Save Questionnaire' ?>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="save_questionnaire">
                    <input type="hidden" name="questionnaire_id" id="questionnaire_id" value="<?= $editing_q['id'] ?? '' ?>">

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Questionnaire Title</label>
                        <input type="text" name="title" id="qTitle" value="<?= htmlspecialchars($editing_q['title'] ?? 'Pre-Interview Screening Questions') ?>" placeholder="e.g. Technical & Salary Screening" required>
                    </div>

                    <div style="margin-bottom:18px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Associated Job Posting (Optional)</label>
                        <select name="job_id" id="qJobId">
                            <option value="">🌐 General Template (Reusable for any Job)</option>
                            <?php foreach($my_jobs as $jb): ?>
                                <option value="<?= $jb['id'] ?>" <?= (($editing_q['job_id'] ?? $job_id_param) == $jb['id']) ? 'selected' : '' ?>>
                                    💼 <?= htmlspecialchars($jb['job_title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="margin-bottom:18px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <label style="font-size:13px; color:var(--txt); font-weight:700;">Questions List</label>
                            <button type="button" onclick="addQuestion()" class="btn-secondary" style="padding:4px 12px; font-size:11px;">+ Add Question</button>
                        </div>

                        <div id="questionsContainer">
                            <?php 
                                $edit_questions = $editing_q ? (json_decode($editing_q['questions_json'], true) ?: []) : [
                                    "What is your expected salary and notice period?",
                                    "Why are you interested in joining our team?",
                                    "What relevant technical experience do you bring to this role?"
                                ];
                            ?>
                            <?php foreach($edit_questions as $idx => $qText): ?>
                                <div class="q-item">
                                    <span style="font-size:12px; font-weight:700; color:var(--acc);" class="q-num">Q<?= $idx + 1 ?>.</span>
                                    <input type="text" name="questions[]" value="<?= htmlspecialchars($qText) ?>" placeholder="Enter question text..." required style="margin:0; flex:1; font-size:13px;">
                                    <button type="button" onclick="removeQuestion(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="display:flex; gap:10px; margin-top:24px;">
                        <button type="submit" class="btn-primary" style="flex:1; padding:11px; border-radius:10px;">💾 Save Questionnaire Template</button>
                    </div>
                </form>
            </div>

        </div>
```

to:

```php
        <div class="templates-wrapper-col" style="width:100%;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
                <div style="font-size:16px; font-weight:800; color:var(--txt);">📁 Saved Templates Library (<?= count($questionnaires) ?>)</div>
                <div class="swipe-hint" style="font-size:11px; color:var(--acc); font-weight:600;">Swipe cards 👈 👉</div>
            </div>

            <?php if(empty($questionnaires)): ?>
                <div class="panel" style="text-align:center; padding:40px 20px; border-style:dashed;">
                    <div style="font-size:36px; margin-bottom:10px;">📋</div>
                    <div style="font-size:16px; font-weight:700; color:var(--txt); margin-bottom:4px;">No Questionnaires Saved Yet</div>
                    <p style="font-size:12px; color:var(--mut); margin-bottom:0;">Click "+ Create New Questionnaire" above to save your first question template.</p>
                </div>
            <?php else: ?>
                <div class="templates-slider-container">
                    <?php foreach($questionnaires as $q): ?>
                        <?php 
                            $q_list = json_decode($q['questions_json'], true) ?: []; 
                        ?>
                        <div class="template-card">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <div>
                                    <div style="font-size:17px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($q['title']) ?></div>
                                    <div style="font-size:11px; color:var(--mut); margin-top:2px;">
                                        <?= !empty($q['job_title']) ? '💼 ' . htmlspecialchars($q['job_title']) : '🌐 Reusable Template (All Jobs)' ?>
                                    </div>
                                </div>
                                <span class="chip" style="background:rgba(59, 130, 246, 0.1); color:var(--acc); border-color:rgba(59, 130, 246, 0.3);">
                                    <?= count($q_list) ?> Questions
                                </span>
                            </div>

                            <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:8px; padding:10px 14px; margin-top:12px; margin-bottom:14px; max-height:110px; overflow-y:auto;">
                                <?php foreach($q_list as $idx => $q_item): ?>
                                    <div style="font-size:12px; color:var(--txt); margin-bottom:4px;">
                                        <strong style="color:var(--acc);">Q<?= $idx + 1 ?>:</strong> <?= htmlspecialchars($q_item) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="display:flex; gap:8px; justify-content:flex-end; align-items:center;">
                                <a href="questionnaire.php?edit=<?= $q['id'] ?>" class="btn-secondary" style="padding:5px 12px; font-size:11px; text-decoration:none;">✏️ Edit Template</a>
                                
                                <form method="POST" onsubmit="return confirm('Are you sure you want to delete this questionnaire template?');" style="margin:0;">
                                    <input type="hidden" name="action" value="delete_questionnaire">
                                    <input type="hidden" name="questionnaire_id" value="<?= $q['id'] ?>">
                                    <button type="submit" class="btn-secondary" style="padding:5px 12px; font-size:11px; color:var(--red); border-color:rgba(255,77,106,0.3);">🗑️ Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Create / Edit Questionnaire Modal -->
        <div id="questionnaireModal" style="display:<?= $editing_q ? 'flex' : 'none' ?>; position:fixed; inset:0; background:rgba(0,0,0,0.75); backdrop-filter:blur(6px); z-index:9999; align-items:center; justify-content:center; padding:20px;">
            <div style="background:var(--card); border:1px solid var(--bdr); border-radius:18px; max-width:650px; width:100%; padding:28px; box-shadow:var(--shadow-lg); max-height:90vh; overflow-y:auto;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                    <div class="panel-title" id="formTitle" style="margin:0;">
                        <?= $editing_q ? '✏️ Edit Questionnaire Template' : '📋 New Questionnaire' ?>
                    </div>
                    <button type="button" onclick="closeQuestionnaireModal()" style="background:var(--dim); border:1px solid var(--bdr); color:var(--txt); font-size:16px; font-weight:800; width:32px; height:32px; border-radius:50%; cursor:pointer;">✕</button>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="save_questionnaire">
                    <input type="hidden" name="questionnaire_id" id="questionnaire_id" value="<?= $editing_q['id'] ?? '' ?>">

                    <div style="font-size:11px; font-weight:800; color:var(--acc); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:12px;">Basic Info</div>

                    <div style="margin-bottom:16px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Title *</label>
                        <input type="text" name="title" id="qTitle" value="<?= htmlspecialchars($editing_q['title'] ?? 'Pre-Interview Screening Questions') ?>" placeholder="e.g. Senior Engineer Technical Assessment" required>
                    </div>

                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                        <div>
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Related Job Title</label>
                            <select name="job_id" id="qJobId">
                                <option value="">🌐 General Template (Reusable for any Job)</option>
                                <?php foreach($my_jobs as $jb): ?>
                                    <option value="<?= $jb['id'] ?>" <?= (($editing_q['job_id'] ?? $job_id_param) == $jb['id']) ? 'selected' : '' ?>>
                                        💼 <?= htmlspecialchars($jb['job_title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Status</label>
                            <select name="status" id="qStatus">
                                <option value="Active" <?= (($editing_q['status'] ?? 'Active') === 'Active') ? 'selected' : '' ?>>Active</option>
                                <option value="Draft" <?= (($editing_q['status'] ?? 'Active') === 'Draft') ? 'selected' : '' ?>>Draft</option>
                            </select>
                        </div>
                    </div>

                    <div style="margin-bottom:20px;">
                        <label style="display:block; font-size:12px; color:var(--mut); margin-bottom:6px; font-weight:600;">Description / Instructions</label>
                        <textarea name="description" id="qDescription" rows="2" placeholder="Optional intro shown to candidates at the top of the form..."><?= htmlspecialchars($editing_q['description'] ?? '') ?></textarea>
                    </div>

                    <div style="border-top:1px dashed var(--bdr); padding-top:16px; margin-bottom:18px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                            <label style="font-size:13px; color:var(--txt); font-weight:700;">Questions (<span id="questionsCount">0</span>)</label>
                            <button type="button" onclick="addQuestion()" class="btn-secondary" style="padding:4px 12px; font-size:11px;">+ Add Question</button>
                        </div>

                        <div id="questionsContainer"></div>
                    </div>

                    <div style="display:flex; gap:10px; margin-top:24px;">
                        <button type="button" onclick="closeQuestionnaireModal()" class="btn-secondary" style="flex:1; padding:11px;">Cancel</button>
                        <button type="submit" class="btn-primary" style="flex:1; padding:11px; border-radius:10px;">💾 Save Questionnaire Template</button>
                    </div>
                </form>
            </div>
        </div>

        <?php
            $edit_questions_for_js = [];
            if ($editing_q) {
                $decoded_edit_questions = json_decode($editing_q['questions_json'], true) ?: [];
                foreach ($decoded_edit_questions as $q_raw) {
                    $edit_questions_for_js[] = normalize_question($q_raw)['text'];
                }
            } else {
                $edit_questions_for_js = [
                    "What is your expected salary and notice period?",
                    "Why are you interested in joining our team?",
                    "What relevant technical experience do you bring to this role?"
                ];
            }
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const initialQuestionTexts = <?= json_encode($edit_questions_for_js) ?>;
                initialQuestionTexts.forEach(function(t) { addQuestion(t); });
                <?php if ($editing_q): ?>
                    document.getElementById('questionnaireModal').style.display = 'flex';
                <?php endif; ?>
            });
        </script>
```

- [ ] **Step 4: Replace the page-title "+ Create New Questionnaire" link**

Change:

```php
            <a href="#editorForm" onclick="resetForm()" class="btn-primary page-title-btn" style="padding:10px 20px; font-size:14px; text-decoration:none; width:auto; border-radius:10px;">+ Create New Questionnaire</a>
```

to:

```php
            <button type="button" onclick="openQuestionnaireModal()" class="btn-primary page-title-btn" style="padding:10px 20px; font-size:14px; width:auto; border-radius:10px;">+ Create New Questionnaire</button>
```

- [ ] **Step 5: Replace the JS: `addQuestion`/`removeQuestion`/`reindexQuestions`/`resetForm`, and add `escapeAttr`/`updateQuestionsCount`/`openQuestionnaireModal`/`closeQuestionnaireModal`**

Change:

```js
        function addQuestion(text = '') {
            const container = document.getElementById('questionsContainer');
            const count = container.children.length + 1;
            const div = document.createElement('div');
            div.className = 'q-item';
            div.innerHTML = `
                <span style="font-size:12px; font-weight:700; color:var(--acc);" class="q-num">Q${count}.</span>
                <input type="text" name="questions[]" value="${text}" placeholder="Enter question text..." required style="margin:0; flex:1; font-size:13px;">
                <button type="button" onclick="removeQuestion(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
            `;
            container.appendChild(div);
        }

        function removeQuestion(btn) {
            btn.parentElement.remove();
            reindexQuestions();
        }

        function reindexQuestions() {
            const container = document.getElementById('questionsContainer');
            const nums = container.getElementsByClassName('q-num');
            for (let i = 0; i < nums.length; i++) {
                nums[i].innerText = 'Q' + (i + 1) + '.';
            }
        }

        function resetForm() {
            document.getElementById('questionnaire_id').value = '';
            document.getElementById('qTitle').value = 'Pre-Interview Screening Questions';
            document.getElementById('qJobId').value = '';
            document.getElementById('formTitle').innerText = '➕ Create & Save Questionnaire';
        }
```

to:

```js
        function escapeAttr(str) {
            return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function addQuestion(text = '') {
            const container = document.getElementById('questionsContainer');
            const count = container.children.length + 1;
            const div = document.createElement('div');
            div.className = 'q-item';
            div.innerHTML = `
                <span style="font-size:12px; font-weight:700; color:var(--acc);" class="q-num">Q${count}.</span>
                <input type="text" name="questions[]" value="${escapeAttr(text)}" placeholder="Enter question text..." required style="margin:0; flex:1; font-size:13px;">
                <button type="button" onclick="removeQuestion(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
            `;
            container.appendChild(div);
            updateQuestionsCount();
        }

        function removeQuestion(btn) {
            btn.parentElement.remove();
            reindexQuestions();
            updateQuestionsCount();
        }

        function reindexQuestions() {
            const container = document.getElementById('questionsContainer');
            const nums = container.getElementsByClassName('q-num');
            for (let i = 0; i < nums.length; i++) {
                nums[i].innerText = 'Q' + (i + 1) + '.';
            }
        }

        function updateQuestionsCount() {
            const countEl = document.getElementById('questionsCount');
            if (countEl) countEl.innerText = document.getElementById('questionsContainer').children.length;
        }

        function openQuestionnaireModal() {
            resetForm();
            document.getElementById('questionnaireModal').style.display = 'flex';
        }

        function closeQuestionnaireModal() {
            document.getElementById('questionnaireModal').style.display = 'none';
        }

        function resetForm() {
            document.getElementById('questionnaire_id').value = '';
            document.getElementById('qTitle').value = 'Pre-Interview Screening Questions';
            document.getElementById('qJobId').value = '';
            document.getElementById('qStatus').value = 'Active';
            document.getElementById('qDescription').value = '';
            document.getElementById('formTitle').innerText = '📋 New Questionnaire';
            document.getElementById('questionsContainer').innerHTML = '';
            addQuestion('What is your expected salary and notice period?');
            addQuestion('Why are you interested in joining our team?');
            addQuestion('What relevant technical experience do you bring to this role?');
        }
```

- [ ] **Step 6: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/questionnaire.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 7: Manual verification**

1. Log in as an employer, open `questionnaire.php`.
2. Click "+ Create New Questionnaire" — confirm a modal pops up (dark overlay, centered card, ✕ close) with Title/Related Job Title/Status/Description fields and the 3 default questions listed as plain text rows (no Type/Required yet — that's normal, Task 4 adds it).
3. Click ✕ and Cancel — both should close the modal.
4. Fill in Description, pick Status = Draft, save — confirm it appears in the Templates Library.
5. Click "✏️ Edit Template" on that saved template — confirm the modal opens pre-filled with the saved Title/Job/Status/Description/questions.

- [ ] **Step 8: Commit**

No git repo — skip.

---

### Task 4: Question Type / Required / Options in the builder

**Files:**
- Modify: `questionnaire.php`

**Interfaces:**
- Consumes: `#questionsContainer`, `addQuestion`, `removeQuestion`, `reindexQuestions`, `updateQuestionsCount`, `escapeAttr` from Task 3 (all get replaced/extended in this task).
- Produces: each `.q-item` row now has a `.q-type` select, `.q-required` checkbox, and (conditionally) a `.q-options-wrap` with `.q-options-list` of `.q-option-item` rows. A new hidden `#questionsDataInput` field carries the full question set as JSON, built by `validateAndSerializeQuestionnaireForm()` on submit. The save handler decodes that JSON into the `{text, type, required, options}` object array described in Global Constraints — this is what Task 5/6/7/8 read via `normalize_question()`.

- [ ] **Step 1: Replace the save handler's question-reading logic**

Change:

```php
    $status = ($_POST['status'] ?? 'Active') === 'Draft' ? 'Draft' : 'Active';
    $description = trim($_POST['description'] ?? '');
    $raw_questions = $_POST['questions'] ?? [];
    
    $clean_questions = [];
    foreach ($raw_questions as $q) {
        $q_text = trim($q);
        if ($q_text !== '') {
            $clean_questions[] = $q_text;
        }
    }

    if (empty($clean_questions)) {
```

to:

```php
    $status = ($_POST['status'] ?? 'Active') === 'Draft' ? 'Draft' : 'Active';
    $description = trim($_POST['description'] ?? '');

    $valid_types = ['short_text', 'long_text', 'multiple_choice', 'checkbox', 'rating_scale', 'yes_no', 'number'];
    $raw_questions = json_decode($_POST['questions_data'] ?? '[]', true) ?: [];

    $clean_questions = [];
    foreach ($raw_questions as $q) {
        $q_text = trim($q['text'] ?? '');
        if ($q_text === '') continue;

        $q_type = in_array($q['type'] ?? '', $valid_types) ? $q['type'] : 'short_text';
        $q_required = !empty($q['required']);

        $question = [
            'text' => $q_text,
            'type' => $q_type,
            'required' => $q_required,
        ];

        if (in_array($q_type, ['multiple_choice', 'checkbox'])) {
            $clean_options = [];
            foreach (($q['options'] ?? []) as $opt) {
                $opt_text = trim($opt);
                if ($opt_text !== '') $clean_options[] = $opt_text;
            }
            $question['options'] = $clean_options;
        }

        $clean_questions[] = $question;
    }

    if (empty($clean_questions)) {
```

- [ ] **Step 2: Add the hidden `questions_data` input and replace the form's `onsubmit`**

Change:

```php
                <form method="POST">
                    <input type="hidden" name="action" value="save_questionnaire">
                    <input type="hidden" name="questionnaire_id" id="questionnaire_id" value="<?= $editing_q['id'] ?? '' ?>">

                    <div style="font-size:11px; font-weight:800; color:var(--acc); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:12px;">Basic Info</div>
```

to:

```php
                <form method="POST" onsubmit="return validateAndSerializeQuestionnaireForm();">
                    <input type="hidden" name="action" value="save_questionnaire">
                    <input type="hidden" name="questionnaire_id" id="questionnaire_id" value="<?= $editing_q['id'] ?? '' ?>">

                    <div style="font-size:11px; font-weight:800; color:var(--acc); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:12px;">Basic Info</div>
```

- [ ] **Step 3: Add the hidden field inside the Questions section**

Change:

```php
                        <div id="questionsContainer"></div>
                    </div>

                    <div style="display:flex; gap:10px; margin-top:24px;">
                        <button type="button" onclick="closeQuestionnaireModal()" class="btn-secondary" style="flex:1; padding:11px;">Cancel</button>
```

to:

```php
                        <input type="hidden" name="questions_data" id="questionsDataInput" value="">
                        <div id="questionsContainer"></div>
                    </div>

                    <div style="display:flex; gap:10px; margin-top:24px;">
                        <button type="button" onclick="closeQuestionnaireModal()" class="btn-secondary" style="flex:1; padding:11px;">Cancel</button>
```

- [ ] **Step 4: Replace the bootstrap script to carry type/required/options**

Change:

```php
        <?php
            $edit_questions_for_js = [];
            if ($editing_q) {
                $decoded_edit_questions = json_decode($editing_q['questions_json'], true) ?: [];
                foreach ($decoded_edit_questions as $q_raw) {
                    $edit_questions_for_js[] = normalize_question($q_raw)['text'];
                }
            } else {
                $edit_questions_for_js = [
                    "What is your expected salary and notice period?",
                    "Why are you interested in joining our team?",
                    "What relevant technical experience do you bring to this role?"
                ];
            }
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const initialQuestionTexts = <?= json_encode($edit_questions_for_js) ?>;
                initialQuestionTexts.forEach(function(t) { addQuestion(t); });
                <?php if ($editing_q): ?>
                    document.getElementById('questionnaireModal').style.display = 'flex';
                <?php endif; ?>
            });
        </script>
```

to:

```php
        <?php
            $edit_questions_for_js = [];
            if ($editing_q) {
                $decoded_edit_questions = json_decode($editing_q['questions_json'], true) ?: [];
                foreach ($decoded_edit_questions as $q_raw) {
                    $edit_questions_for_js[] = normalize_question($q_raw);
                }
            } else {
                $edit_questions_for_js = [
                    ['text' => "What is your expected salary and notice period?", 'type' => 'short_text', 'required' => true, 'options' => []],
                    ['text' => "Why are you interested in joining our team?", 'type' => 'long_text', 'required' => true, 'options' => []],
                    ['text' => "What relevant technical experience do you bring to this role?", 'type' => 'long_text', 'required' => true, 'options' => []],
                ];
            }
        ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const initialQuestions = <?= json_encode($edit_questions_for_js) ?>;
                initialQuestions.forEach(function(q) { addQuestion(q.text, q.type, q.required, q.options); });
                <?php if ($editing_q): ?>
                    document.getElementById('questionnaireModal').style.display = 'flex';
                <?php endif; ?>
            });
        </script>
```

- [ ] **Step 5: Replace the JS with the full type/required/options system**

Change:

```js
        function addQuestion(text = '') {
            const container = document.getElementById('questionsContainer');
            const count = container.children.length + 1;
            const div = document.createElement('div');
            div.className = 'q-item';
            div.innerHTML = `
                <span style="font-size:12px; font-weight:700; color:var(--acc);" class="q-num">Q${count}.</span>
                <input type="text" name="questions[]" value="${escapeAttr(text)}" placeholder="Enter question text..." required style="margin:0; flex:1; font-size:13px;">
                <button type="button" onclick="removeQuestion(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
            `;
            container.appendChild(div);
            updateQuestionsCount();
        }

        function removeQuestion(btn) {
            btn.parentElement.remove();
            reindexQuestions();
            updateQuestionsCount();
        }
```

to:

```js
        const QUESTION_TYPES = [
            ['short_text', '📝 Short Text'],
            ['long_text', '📄 Long Text'],
            ['multiple_choice', '⚪ Multiple Choice'],
            ['checkbox', '☑️ Checkbox'],
            ['rating_scale', '⭐ Rating Scale'],
            ['yes_no', '👍 Yes / No'],
            ['number', '🔢 Number'],
        ];

        function questionTypeOptionsHtml(selected) {
            return QUESTION_TYPES.map(function(pair) {
                return '<option value="' + pair[0] + '"' + (pair[0] === selected ? ' selected' : '') + '>' + pair[1] + '</option>';
            }).join('');
        }

        function needsOptions(type) {
            return type === 'multiple_choice' || type === 'checkbox';
        }

        function addQuestion(text = '', type = 'short_text', required = true, options = []) {
            const container = document.getElementById('questionsContainer');
            const count = container.children.length + 1;
            const div = document.createElement('div');
            div.className = 'q-item';
            div.style.cssText = 'flex-direction:column; align-items:stretch;';
            div.innerHTML = `
                <div style="display:flex; gap:10px; align-items:center; width:100%;">
                    <span style="font-size:12px; font-weight:700; color:var(--acc); flex-shrink:0;" class="q-num">Q${count}.</span>
                    <input type="text" class="q-text" value="${escapeAttr(text)}" placeholder="Enter question text..." style="margin:0; flex:1; font-size:13px;">
                    <select class="q-type" onchange="onQuestionTypeChange(this)" style="margin:0; width:150px; font-size:12px;">
                        ${questionTypeOptionsHtml(type)}
                    </select>
                    <button type="button" onclick="removeQuestion(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:6px 10px; border-radius:6px; cursor:pointer; font-weight:700;">✕</button>
                </div>
                <div style="display:flex; align-items:center; gap:6px; margin-top:8px; margin-left:26px;">
                    <input type="checkbox" class="q-required" ${required ? 'checked' : ''} style="width:auto; margin:0;">
                    <label style="font-size:11px; color:var(--mut);">Required</label>
                </div>
                <div class="q-options-wrap" style="display:${needsOptions(type) ? 'block' : 'none'}; margin-top:10px; margin-left:26px; background:var(--surf); border:1px solid var(--bdr); border-radius:8px; padding:10px;">
                    <div style="font-size:11px; font-weight:700; color:var(--mut); margin-bottom:8px;">Options</div>
                    <div class="q-options-list"></div>
                    <button type="button" onclick="addOption(this)" class="btn-secondary" style="padding:3px 10px; font-size:11px;">+ Add Option</button>
                </div>
            `;
            container.appendChild(div);
            const optAddBtn = div.querySelector('.q-options-wrap button');
            const opts = options.length > 0 ? options : (needsOptions(type) ? ['', ''] : []);
            opts.forEach(function(opt) { addOption(optAddBtn, opt); });
            updateQuestionsCount();
        }

        function onQuestionTypeChange(select) {
            const qItem = select.closest('.q-item');
            const optionsWrap = qItem.querySelector('.q-options-wrap');
            const show = needsOptions(select.value);
            optionsWrap.style.display = show ? 'block' : 'none';
            if (show && optionsWrap.querySelector('.q-options-list').children.length === 0) {
                const addBtn = optionsWrap.querySelector('button');
                addOption(addBtn, '');
                addOption(addBtn, '');
            }
        }

        function addOption(btn, value = '') {
            const wrap = btn.closest('.q-options-wrap');
            const list = wrap.querySelector('.q-options-list');
            const div = document.createElement('div');
            div.className = 'q-option-item';
            div.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:6px;';
            div.innerHTML = `
                <input type="text" class="q-option-input" value="${escapeAttr(value)}" placeholder="Option text" style="margin:0; flex:1; font-size:12px; padding:6px 10px;">
                <button type="button" onclick="removeOption(this)" style="background:rgba(255,77,106,0.15); border:none; color:var(--red); padding:4px 8px; border-radius:6px; cursor:pointer; font-size:11px;">✕</button>
            `;
            list.appendChild(div);
        }

        function removeOption(btn) {
            btn.closest('.q-option-item').remove();
        }

        function removeQuestion(btn) {
            btn.closest('.q-item').remove();
            reindexQuestions();
            updateQuestionsCount();
        }

        function validateAndSerializeQuestionnaireForm() {
            const container = document.getElementById('questionsContainer');
            const items = Array.from(container.getElementsByClassName('q-item'));
            const data = items.map(function(item) {
                const text = item.querySelector('.q-text').value.trim();
                const type = item.querySelector('.q-type').value;
                const required = item.querySelector('.q-required').checked;
                const optionsWrap = item.querySelector('.q-options-wrap');
                let options = [];
                if (optionsWrap.style.display !== 'none') {
                    options = Array.from(optionsWrap.querySelectorAll('.q-option-input'))
                        .map(function(o) { return o.value.trim(); })
                        .filter(function(o) { return o !== ''; });
                }
                return { text: text, type: type, required: required, options: options };
            }).filter(function(q) { return q.text !== ''; });

            if (data.length === 0) {
                alert('Please add at least one question with text before saving.');
                return false;
            }
            document.getElementById('questionsDataInput').value = JSON.stringify(data);
            return true;
        }
```

- [ ] **Step 6: Keep `resetForm()`'s default questions in sync with the new types**

`resetForm()` (added in Task 3) still calls `addQuestion(text)` with only the text argument, so its 3 example questions would default to `short_text` via `addQuestion`'s new default parameter — but Task 4 Step 4's page-load bootstrap script gives the same 3 example questions types `short_text`/`long_text`/`long_text`. Keep both paths consistent:

Change:

```js
        function resetForm() {
            document.getElementById('questionnaire_id').value = '';
            document.getElementById('qTitle').value = 'Pre-Interview Screening Questions';
            document.getElementById('qJobId').value = '';
            document.getElementById('qStatus').value = 'Active';
            document.getElementById('qDescription').value = '';
            document.getElementById('formTitle').innerText = '📋 New Questionnaire';
            document.getElementById('questionsContainer').innerHTML = '';
            addQuestion('What is your expected salary and notice period?');
            addQuestion('Why are you interested in joining our team?');
            addQuestion('What relevant technical experience do you bring to this role?');
        }
```

to:

```js
        function resetForm() {
            document.getElementById('questionnaire_id').value = '';
            document.getElementById('qTitle').value = 'Pre-Interview Screening Questions';
            document.getElementById('qJobId').value = '';
            document.getElementById('qStatus').value = 'Active';
            document.getElementById('qDescription').value = '';
            document.getElementById('formTitle').innerText = '📋 New Questionnaire';
            document.getElementById('questionsContainer').innerHTML = '';
            addQuestion('What is your expected salary and notice period?', 'short_text', true, []);
            addQuestion('Why are you interested in joining our team?', 'long_text', true, []);
            addQuestion('What relevant technical experience do you bring to this role?', 'long_text', true, []);
        }
```

- [ ] **Step 7: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/questionnaire.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 8: Manual verification**

1. Open "+ Create New Questionnaire" — confirm each of the 3 default questions now shows a Type dropdown (defaulting appropriately) and a checked Required checkbox.
2. Change one question's Type to "Multiple Choice" — confirm an Options box appears with 2 empty option inputs and a "+ Add Option" button; add a 3rd option, remove one via ✕.
3. Change another question's Type to "Checkbox", fill in 2 options.
4. Uncheck Required on one question.
5. Save — confirm no PHP errors and the questionnaire appears in the Templates Library.
6. Check the raw stored data:

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense -e "SELECT questions_json FROM questionnaires ORDER BY id DESC LIMIT 1;"
```

Expected: a JSON array of objects with `text`/`type`/`required`/`options` keys matching what was entered.

7. Click "✏️ Edit Template" on it — confirm the Type/Required/Options all come back pre-filled correctly, including the Multiple Choice/Checkbox options.

- [ ] **Step 9: Commit**

No git repo — skip.

---

### Task 5: Templates Library preview text + status chip

**Files:**
- Modify: `questionnaire.php`

**Interfaces:**
- Consumes: `normalize_question()` from Task 2 (already required in this file since Task 3).
- Produces: nothing consumed by later tasks — leaf UI task.

- [ ] **Step 1: Read question text via `normalize_question()` and add a Draft chip**

Change:

```php
                        <div class="template-card">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <div>
                                    <div style="font-size:17px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($q['title']) ?></div>
                                    <div style="font-size:11px; color:var(--mut); margin-top:2px;">
                                        <?= !empty($q['job_title']) ? '💼 ' . htmlspecialchars($q['job_title']) : '🌐 Reusable Template (All Jobs)' ?>
                                    </div>
                                </div>
                                <span class="chip" style="background:rgba(59, 130, 246, 0.1); color:var(--acc); border-color:rgba(59, 130, 246, 0.3);">
                                    <?= count($q_list) ?> Questions
                                </span>
                            </div>

                            <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:8px; padding:10px 14px; margin-top:12px; margin-bottom:14px; max-height:110px; overflow-y:auto;">
                                <?php foreach($q_list as $idx => $q_item): ?>
                                    <div style="font-size:12px; color:var(--txt); margin-bottom:4px;">
                                        <strong style="color:var(--acc);">Q<?= $idx + 1 ?>:</strong> <?= htmlspecialchars($q_item) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
```

to:

```php
                        <div class="template-card">
                            <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:8px;">
                                <div>
                                    <div style="font-size:17px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($q['title']) ?></div>
                                    <div style="font-size:11px; color:var(--mut); margin-top:2px;">
                                        <?= !empty($q['job_title']) ? '💼 ' . htmlspecialchars($q['job_title']) : '🌐 Reusable Template (All Jobs)' ?>
                                    </div>
                                </div>
                                <div style="display:flex; flex-direction:column; align-items:flex-end; gap:6px;">
                                    <span class="chip" style="background:rgba(59, 130, 246, 0.1); color:var(--acc); border-color:rgba(59, 130, 246, 0.3);">
                                        <?= count($q_list) ?> Questions
                                    </span>
                                    <?php if (($q['status'] ?? 'Active') === 'Draft'): ?>
                                        <span class="chip" style="background:rgba(255,140,66,0.15); color:var(--org); border-color:transparent; font-size:10px;">📝 Draft</span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:8px; padding:10px 14px; margin-top:12px; margin-bottom:14px; max-height:110px; overflow-y:auto;">
                                <?php foreach($q_list as $idx => $q_item): ?>
                                    <?php $q_norm = normalize_question($q_item); ?>
                                    <div style="font-size:12px; color:var(--txt); margin-bottom:4px;">
                                        <strong style="color:var(--acc);">Q<?= $idx + 1 ?>:</strong> <?= htmlspecialchars($q_norm['text']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
```

- [ ] **Step 2: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/questionnaire.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

Reload the Templates Library — confirm the Draft-status template (from Task 3's verification) shows a "📝 Draft" chip and every card's question preview text still renders correctly (no `Array` or garbled output) for both the new object-format template and any pre-existing plain-string template.

- [ ] **Step 4: Commit**

No git repo — skip.

---

### Task 6: candidate.php read-path updates + Draft filtering

**Files:**
- Modify: `candidate.php`

**Interfaces:**
- Consumes: `normalize_question()` from Task 2 (needs a new `require_once`).
- Produces: nothing consumed by later tasks — leaf task, but bundled together since all three edits are small, independent, and in the same file.

- [ ] **Step 1: Require the helper**

Change:

```php
<?php
session_start();
require 'db.php';
```

to:

```php
<?php
session_start();
require 'db.php';
require_once 'questionnaire_helpers.php';
```

- [ ] **Step 2: Exclude Draft questionnaires from the Send Questions template picker**

Change:

```php
    $sq_stmt = $pdo->prepare("SELECT * FROM questionnaires WHERE employer_id = ? OR job_id = ? ORDER BY created_at DESC");
```

to:

```php
    $sq_stmt = $pdo->prepare("SELECT * FROM questionnaires WHERE (employer_id = ? OR job_id = ?) AND (status IS NULL OR status != 'Draft') ORDER BY created_at DESC");
```

- [ ] **Step 3: Fix `updateTemplatePreview()` to read object-format questions**

Change:

```js
            let html = '';
            questions.forEach((q, idx) => {
                html += `<div style="margin-bottom:6px;"><strong style="color:var(--acc);">Q${idx+1}:</strong> ${escapeHtml(q)}</div>`;
            });
```

to:

```js
            let html = '';
            questions.forEach((q, idx) => {
                const qText = (q && typeof q === 'object') ? (q.text || '') : q;
                html += `<div style="margin-bottom:6px;"><strong style="color:var(--acc);">Q${idx+1}:</strong> ${escapeHtml(qText)}</div>`;
            });
```

- [ ] **Step 4: Fix the "Screening Questionnaires" submitted-answers view**

Change:

```php
                                    <div style="margin-top:10px; border-top:1px solid var(--bdr); padding-top:10px;">
                                        <div style="font-size:11px; font-weight:700; color:var(--acc); margin-bottom:8px;">CANDIDATE ANSWERS:</div>
                                        <?php foreach($questions_list as $q_idx => $q_text): ?>
                                            <div style="margin-bottom:8px; font-size:12px;">
                                                <strong style="color:var(--txt);">Q<?= $q_idx + 1 ?>: <?= htmlspecialchars($q_text) ?></strong>
                                                <div style="background:var(--surf); padding:8px 12px; border-radius:6px; margin-top:4px; color:var(--mut);">
                                                    <?= nl2br(htmlspecialchars($answers[$q_idx] ?? '-')) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
```

to:

```php
                                    <div style="margin-top:10px; border-top:1px solid var(--bdr); padding-top:10px;">
                                        <div style="font-size:11px; font-weight:700; color:var(--acc); margin-bottom:8px;">CANDIDATE ANSWERS:</div>
                                        <?php foreach($questions_list as $q_idx => $q_item): ?>
                                            <?php
                                                $q_norm = normalize_question($q_item);
                                                $ans_val = $answers[$q_idx] ?? '-';
                                                $ans_display = is_array($ans_val) ? (empty($ans_val) ? '-' : implode(', ', $ans_val)) : ($ans_val === '' ? '-' : $ans_val);
                                            ?>
                                            <div style="margin-bottom:8px; font-size:12px;">
                                                <strong style="color:var(--txt);">Q<?= $q_idx + 1 ?>: <?= htmlspecialchars($q_norm['text']) ?></strong>
                                                <div style="background:var(--surf); padding:8px 12px; border-radius:6px; margin-top:4px; color:var(--mut);">
                                                    <?= nl2br(htmlspecialchars($ans_display)) ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
```

- [ ] **Step 5: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/candidate.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 6: Manual verification**

1. On a candidate's profile page, open "Send Questions to Candidate" → "📁 Choose Saved Template" — confirm the Draft questionnaire from Task 3 does **not** appear in the dropdown, but the Active one does, and its preview list renders question text correctly (not `[object Object]`).
2. This step's other two checks (submitted-answers view, checkbox-array rendering) are exercised together with Task 8 in Task 9's end-to-end verification, since they need an actual submitted response to check against.

- [ ] **Step 7: Commit**

No git repo — skip.

---

### Task 7: mailer.php question-text extraction fix

**Files:**
- Modify: `mailer.php`

**Interfaces:**
- Consumes: `normalize_question()` from Task 2 (needs a new `require_once`).
- Produces: nothing consumed by later tasks — leaf task.

- [ ] **Step 1: Require the helper**

Change:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
```

to:

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/questionnaire_helpers.php';

use PHPMailer\PHPMailer\PHPMailer;
```

- [ ] **Step 2: Read question text via `normalize_question()`**

Change:

```php
        $q_html = '';
        if (!empty($questions_list)) {
            foreach ($questions_list as $idx => $q_text) {
                $q_num = $idx + 1;
                $q_html .= "<li style='margin-bottom:8px;'><strong>Q{$q_num}:</strong> " . htmlspecialchars($q_text) . "</li>";
            }
        }
```

to:

```php
        $q_html = '';
        if (!empty($questions_list)) {
            foreach ($questions_list as $idx => $q_item) {
                $q_num = $idx + 1;
                $q_text = normalize_question($q_item)['text'];
                $q_html .= "<li style='margin-bottom:8px;'><strong>Q{$q_num}:</strong> " . htmlspecialchars($q_text) . "</li>";
            }
        }
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/mailer.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

Exercised together with Task 9's end-to-end pass (sending a typed questionnaire triggers this code path; check the toast/logged email content shows real question text, not a PHP array-to-string warning).

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 8: Type-aware rendering in answer_questionnaire.php

**Files:**
- Modify: `answer_questionnaire.php`

**Interfaces:**
- Consumes: `normalize_question()` from Task 2.
- Produces: nothing consumed by later tasks — leaf task, the last code change before end-to-end verification.

- [ ] **Step 1: Require the helper, normalize questions, and make answer-collection type-aware**

Change:

```php
<?php
session_start();
require_once 'db.php';

$token = $_GET['token'] ?? $_GET['request_id'] ?? $_GET['id'] ?? null;
if (!$token) {
    echo "Invalid or expired questionnaire link.";
    exit;
}

// Fetch request details
$stmt = $pdo->prepare("SELECT qr.*, q.title, q.questions_json, c.name as candidate_name, j.job_title FROM questionnaire_requests qr JOIN questionnaires q ON qr.questionnaire_id = q.id JOIN candidates c ON qr.candidate_id = c.id LEFT JOIN jobs j ON c.job_id = j.id WHERE qr.id = ?");
$stmt->execute([$token]);
$request = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    echo "Questionnaire not found.";
    exit;
}

$questions = json_decode($request['questions_json'], true) ?: [];
$submitted = $request['status'] === 'Submitted';
$existing_answers = json_decode($request['answers_json'] ?? '[]', true) ?: [];

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$submitted) {
    $raw_answers = $_POST['answers'] ?? [];
    $clean_answers = [];
    foreach ($raw_answers as $idx => $ans) {
        $clean_answers[$idx] = trim($ans);
    }

    $json_answers = json_encode($clean_answers);
    $update_stmt = $pdo->prepare("UPDATE questionnaire_requests SET status = 'Submitted', answers_json = ?, submitted_at = CURRENT_TIMESTAMP WHERE id = ?");
    $update_stmt->execute([$json_answers, $token]);

    $_SESSION['toast'] = "Thank you! Your responses have been submitted to the employer.";
    header("Location: answer_questionnaire.php?token=$token");
    exit;
}
?>
```

to:

```php
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
```

- [ ] **Step 2: Show the questionnaire description under the title**

Change:

```php
        <div style="font-size:22px; font-weight:800; color:var(--txt); margin-bottom:6px;"><?= htmlspecialchars($request['title']) ?></div>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">
            Applicant: <strong><?= htmlspecialchars($request['candidate_name']) ?></strong>
        </div>
```

to:

```php
        <div style="font-size:22px; font-weight:800; color:var(--txt); margin-bottom:6px;"><?= htmlspecialchars($request['title']) ?></div>
        <?php if (!empty($request['description'])): ?>
            <div style="font-size:13px; color:var(--txt); background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:12px 16px; margin-bottom:14px; line-height:1.5;">
                <?= nl2br(htmlspecialchars($request['description'])) ?>
            </div>
        <?php endif; ?>
        <div style="font-size:13px; color:var(--mut); margin-bottom:24px;">
            Applicant: <strong><?= htmlspecialchars($request['candidate_name']) ?></strong>
        </div>
```

- [ ] **Step 3: Type-aware read-only "submitted answers" view**

Change:

```php
            <?php foreach($questions as $idx => $q): ?>
                <div class="q-box">
                    <div style="font-size:13px; font-weight:700; color:var(--acc); margin-bottom:8px;">Q<?= $idx + 1 ?>. <?= htmlspecialchars($q) ?></div>
                    <div style="font-size:13px; color:var(--txt); background:var(--card); padding:10px 14px; border-radius:8px; border:1px solid var(--bdr);">
                        <?= nl2br(htmlspecialchars($existing_answers[$idx] ?? '-')) ?>
                    </div>
                </div>
            <?php endforeach; ?>
```

to:

```php
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
```

- [ ] **Step 4: Type-aware answer form**

Change:

```php
            <form method="POST">
                <?php foreach($questions as $idx => $q): ?>
                    <div class="q-box">
                        <label style="display:block; font-size:13px; font-weight:700; color:var(--txt); margin-bottom:10px;">
                            Q<?= $idx + 1 ?>. <?= htmlspecialchars($q) ?> <span style="color:var(--red)">*</span>
                        </label>
                        <textarea name="answers[<?= $idx ?>]" rows="3" placeholder="Type your answer here..." required style="margin:0;"></textarea>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn-primary" style="padding:14px; font-size:14px; width:100%; margin-top:10px;">Submit Answers to Employer &rarr;</button>
            </form>
```

to:

```php
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
```

- [ ] **Step 5: Add the checkbox-group required check**

Change:

```php
    <script src="theme.js"></script>
</body>
</html>
```

to:

```php
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
```

- [ ] **Step 6: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/answer_questionnaire.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 7: Manual verification**

Exercised together with Task 9's full end-to-end pass below.

- [ ] **Step 8: Commit**

No git repo — skip.

---

### Task 9: End-to-end verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: the complete feature from Tasks 1–8.

- [ ] **Step 1: Build a full-coverage questionnaire**

As an employer, create a new questionnaire via the modal with one question of each type (`short_text`, `long_text`, `multiple_choice` with 3 options, `checkbox` with 3 options, `rating_scale`, `yes_no`, `number`), mixing Required (checked) and not required (unchecked) across them, Status = Active, with a Description filled in. Save it.

- [ ] **Step 2: Save a second questionnaire with Status = Draft**

Same as Step 1 but pick Status = Draft on save.

- [ ] **Step 3: Confirm Templates Library and Send picker both behave correctly**

1. Templates Library shows both templates; the Draft one has a "📝 Draft" chip.
2. On a candidate's profile page, "Send Questions to Candidate" → the saved-template dropdown lists only the Active one.

- [ ] **Step 4: Send the Active questionnaire to a test candidate and answer it**

1. Send it from the candidate's profile page — confirm the toast doesn't show a PHP warning/error (checks Task 7's mailer.php fix).
2. Open `answer_questionnaire.php?token=<the request id>` as the candidate — confirm the Description shows under the title, and each question renders the correct input type (text box, textarea, radio group, checkbox group, 5-star radio row, Yes/No radios, number box).
3. Try submitting with a required checkbox question left unchecked — confirm the alert blocks submission; check the box and resubmit.
4. Try submitting with a required text/radio question blank — confirm the browser's native validation blocks it (HTML `required`); leave a non-required question blank and confirm it still submits fine.

- [ ] **Step 5: Confirm both sides see the submitted answers correctly**

1. As the candidate, reload the link — confirm the read-only view shows all answers correctly, including the checkbox question showing a comma-separated list of the selected options.
2. As the employer, open that candidate's profile → "📋 Screening Questionnaires" tab — confirm the same answers render correctly there too (Task 6 Step 4's fix).

- [ ] **Step 6: Confirm the untouched legacy/ad-hoc path still works**

1. On a candidate's profile page, "Send Questions to Candidate" → "✍️ Make New Questions" tab → type 1–2 plain custom questions → send.
2. Open that link as the candidate — confirm each renders as a free-text `<textarea>` and is required (this is `normalize_question()`'s string fallback in action), and submits/displays correctly on both sides.

- [ ] **Step 7: Clean up test data**

Delete the two test questionnaires, the test questionnaire_requests rows, and any test candidate/employer accounts created solely for this verification (ask the user first, matching the approach used for the Company Settings feature earlier this session).

- [ ] **Step 8: Report results**

Summarize what was verified and any issues found/fixed to the user.
