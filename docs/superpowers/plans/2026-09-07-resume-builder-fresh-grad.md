# AI Resume Builder Fresh-Graduate Option Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a "fresh graduate / little work experience" checkbox to the AI Resume Builder that reorders/relabels Form mode, changes Chat mode's question order (education first, experience optional and skippable), and tailors both the real-AI prompt and the no-API-key fallback summary so generated resumes read appropriately for someone without a job history.

**Architecture:** Single toggle (`is_fresh_grad` checkbox) submitted as a normal form field, read server-side into `$input['is_fresh_grad']`, and consumed in three places: client-side JS (Chat mode step machine + Form mode panel reorder), and two PHP functions in `ai.php` (`generate_resume_document` via a new `build_resume_prompt` helper, and `generate_fallback_resume_document`). No new database columns — the flag rides inside the existing `raw_input` JSON blob.

**Tech Stack:** PHP 8 (procedural), vanilla JS, MySQL/MariaDB via PDO. No automated test framework exists in this codebase — verification throughout this plan uses `php -l`, small throwaway PHP scripts (pattern: require the file, call the function directly, assert on output, delete when done), and manual browser walkthroughs, matching how the rest of this codebase is verified.

## Global Constraints

- No new form fields beyond the one checkbox — question wording/flow only (per spec: "Question wording only" scope decision).
- No changes to the `resume_builds` table schema.
- No changes to `resume_ai_assist.php`.
- Every touched `.php` file must pass `php -l` before being considered done.
- Scratch/throwaway verification scripts go in the scratchpad directory (`C:\Users\keyte\AppData\Local\Temp\claude\C--xampp-htdocs-hiresense\f50e23f0-c717-43e1-a0a7-a93ca5cd41e6\scratchpad`), never committed.

---

### Task 1: Fresh-grad checkbox + server-side flag capture

**Files:**
- Modify: `resume_builder.php:36-68` (POST handler, `$input` construction)
- Modify: `resume_builder.php:379-383` (Basics panel HTML)

**Interfaces:**
- Produces: `$input['is_fresh_grad']` (bool) — consumed by Task 4 (`build_resume_prompt`) and Task 5 (`generate_fallback_resume_document`).
- Produces: checkbox `#freshGradCheck` in the DOM — consumed by Task 2 (Form mode reorder) and Task 3 (Chat mode flow).

- [ ] **Step 1: Add the checkbox to the Basics panel**

In `resume_builder.php`, find (around line 379-383):

```php
                    <div class="field-row">
                        <div><label class="f">Target Job Title</label><input type="text" name="target_title" id="targetTitleInput" placeholder="e.g. Digital Marketing Executive" required></div>
                        <div><label class="f">Location</label><input type="text" name="location" placeholder="e.g. Kuala Lumpur"></div>
                    </div>
                </div>
```

Replace with:

```php
                    <div class="field-row">
                        <div><label class="f">Target Job Title</label><input type="text" name="target_title" id="targetTitleInput" placeholder="e.g. Digital Marketing Executive" required></div>
                        <div><label class="f">Location</label><input type="text" name="location" placeholder="e.g. Kuala Lumpur"></div>
                    </div>
                    <label class="f" style="display:flex; align-items:center; gap:6px; margin-top:8px; cursor:pointer;">
                        <input type="checkbox" name="is_fresh_grad" id="freshGradCheck" style="width:auto;">
                        I'm a fresh graduate / have little work experience
                    </label>
                </div>
```

- [ ] **Step 2: Capture the flag server-side**

In `resume_builder.php`, find (around line 41):

```php
    $location = trim($_POST['location'] ?? '');
```

Replace with:

```php
    $location = trim($_POST['location'] ?? '');
    $is_fresh_grad = isset($_POST['is_fresh_grad']);
```

Then find (around line 62-68):

```php
    $input = [
        'target_title' => $target_title,
        'experience' => $experience,
        'education' => $education,
        'skills' => trim($_POST['skills'] ?? ''),
        'extra_notes' => trim($_POST['extra_notes'] ?? '')
    ];
```

Replace with:

```php
    $input = [
        'target_title' => $target_title,
        'experience' => $experience,
        'education' => $education,
        'skills' => trim($_POST['skills'] ?? ''),
        'extra_notes' => trim($_POST['extra_notes'] ?? ''),
        'is_fresh_grad' => $is_fresh_grad
    ];
```

- [ ] **Step 3: Verify with `php -l`**

Run: `php -l resume_builder.php` (use the full XAMPP path if `php` isn't on PATH, e.g. `/c/xampp/php/php.exe -l resume_builder.php`)
Expected: `No syntax errors detected in resume_builder.php`

- [ ] **Step 4: Verify the flag is captured, with a throwaway script**

Create `<scratchpad>/verify_task1_flag.php`:

```php
<?php
// Throwaway verification — simulates the POST handler's flag-capture line only.
$_POST['is_fresh_grad'] = 'on'; // checkbox present = checked
$is_fresh_grad = isset($_POST['is_fresh_grad']);
echo $is_fresh_grad ? "PASS: checked -> true\n" : "FAIL: expected true\n";

unset($_POST['is_fresh_grad']);
$is_fresh_grad = isset($_POST['is_fresh_grad']);
echo !$is_fresh_grad ? "PASS: unchecked -> false\n" : "FAIL: expected false\n";
```

Run: `php <scratchpad>/verify_task1_flag.php`
Expected: both lines print `PASS: ...`. Delete the script afterward.

- [ ] **Step 5: Commit**

```bash
git add resume_builder.php
git commit -m "Add fresh-graduate checkbox and capture is_fresh_grad flag"
```

---

### Task 2: Form mode panel reorder + relabel

**Files:**
- Modify: `resume_builder.php:392-402` (Work Experience / Education panel wrappers — add IDs)
- Modify: `resume_builder.php` (JS section, near `switchMode`/`addExp`/`addEdu`, around line 474-487) — add the change listener

**Interfaces:**
- Consumes: `#freshGradCheck` from Task 1.
- Produces: none consumed by later tasks (this is a leaf, browser-only behavior).

- [ ] **Step 1: Add IDs to the Work Experience and Education panel wrappers**

Find (around line 392-402):

```php
                    <div class="panel" style="margin-bottom:20px;">
                        <div class="panel-title">💼 Work Experience</div>
                        <div id="expContainer"></div>
                        <button type="button" class="add-btn" onclick="addExp()">+ Add Work Experience</button>
                    </div>

                    <div class="panel" style="margin-bottom:20px;">
                        <div class="panel-title">🎓 Education</div>
                        <div id="eduContainer"></div>
                        <button type="button" class="add-btn" onclick="addEdu()">+ Add Education</button>
                    </div>
```

Replace with:

```php
                    <div class="panel" id="expPanel" style="margin-bottom:20px;">
                        <div class="panel-title" id="expPanelTitle">💼 Work Experience</div>
                        <div id="expContainer"></div>
                        <button type="button" class="add-btn" onclick="addExp()">+ Add Work Experience</button>
                    </div>

                    <div class="panel" id="eduPanel" style="margin-bottom:20px;">
                        <div class="panel-title">🎓 Education</div>
                        <div id="eduContainer"></div>
                        <button type="button" class="add-btn" onclick="addEdu()">+ Add Education</button>
                    </div>
```

- [ ] **Step 2: Add the reorder/relabel JS**

Find (around line 483-486):

```php
// Start with one of each
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('expContainer')) { addExp(); addEdu(); }
});
```

Replace with:

```php
// Start with one of each
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('expContainer')) { addExp(); addEdu(); }
});

// Fresh-grad toggle: reorder Education before Work Experience, relabel the
// Work Experience panel as internships/projects, since a fresh grad's
// "experience" is usually one of those rather than a job.
document.addEventListener('DOMContentLoaded', function() {
    const freshGradCheck = document.getElementById('freshGradCheck');
    if (!freshGradCheck) return;
    freshGradCheck.addEventListener('change', function() {
        const formPanelEl = document.getElementById('formPanel');
        const expPanel = document.getElementById('expPanel');
        const eduPanel = document.getElementById('eduPanel');
        const expTitle = document.getElementById('expPanelTitle');
        if (this.checked) {
            expTitle.textContent = '💼 Internships / Part-Time Jobs / Projects (optional)';
            formPanelEl.insertBefore(eduPanel, expPanel);
        } else {
            expTitle.textContent = '💼 Work Experience';
            formPanelEl.insertBefore(expPanel, eduPanel);
        }
    });
});
```

- [ ] **Step 3: Verify with `php -l`**

Run: `php -l resume_builder.php`
Expected: `No syntax errors detected in resume_builder.php`

- [ ] **Step 4: Manual browser verification**

Using your logged-in candidate session: open `resume_builder.php`, stay in Form mode (default tab). Check the "I'm a fresh graduate" checkbox and confirm:
- The "Work Experience" panel title changes to "Internships / Part-Time Jobs / Projects (optional)".
- The Education panel now appears above that panel.
Uncheck it and confirm both revert.

- [ ] **Step 5: Commit**

```bash
git add resume_builder.php
git commit -m "Reorder and relabel Form mode panels when fresh-grad checkbox is on"
```

---

### Task 3: Chat mode question flow branch

**Files:**
- Modify: `resume_builder.php:505-627` (chat mode JS: `chatStart`, `chatAdvance`)

**Interfaces:**
- Consumes: `#freshGradCheck` from Task 1.
- Produces: none consumed by later tasks.

- [ ] **Step 1: Add the `chatIsFreshGrad` flag and branch `chatStart()`**

Find (around line 506-507):

```js
let chatStarted = false;
let chatStep = null;
```

Replace with:

```js
let chatStarted = false;
let chatStep = null;
let chatIsFreshGrad = false;
```

Find (around line 554-557):

```js
function chatStart() {
    chatAddMsg("Let's build your resume together! First, tell me about your most recent job — what company did you work at?", 'ai');
    chatStep = 'exp_company';
}
```

Replace with:

```js
function chatStart() {
    chatIsFreshGrad = document.getElementById('freshGradCheck').checked;
    if (chatIsFreshGrad) {
        chatAddMsg("Let's build your resume together! Since you're a fresh graduate, let's start with your education — what's your degree or qualification?", 'ai');
        chatStep = 'edu_degree';
    } else {
        chatAddMsg("Let's build your resume together! First, tell me about your most recent job — what company did you work at?", 'ai');
        chatStep = 'exp_company';
    }
}
```

- [ ] **Step 2: Branch `edu_more` to the new skippable experience question for fresh grads**

Find (around line 611-619):

```js
        case 'edu_more':
            if (answer.toLowerCase().startsWith('yes')) {
                chatAddMsg('Sure — what degree or qualification?', 'ai');
                chatStep = 'edu_degree';
            } else {
                chatAddMsg('Last thing — list your key skills, separated by commas (e.g. "Canva, Excel, Mandarin").', 'ai');
                chatStep = 'skills';
            }
            break;
```

Replace with:

```js
        case 'edu_more':
            if (answer.toLowerCase().startsWith('yes')) {
                chatAddMsg('Sure — what degree or qualification?', 'ai');
                chatStep = 'edu_degree';
            } else if (chatIsFreshGrad) {
                chatAddMsg("Do you have any internships, part-time jobs, or class/personal projects you'd like to include? Type them in, or type 'skip'.", 'ai');
                chatStep = 'exp_intro_skip';
            } else {
                chatAddMsg('Last thing — list your key skills, separated by commas (e.g. "Canva, Excel, Mandarin").', 'ai');
                chatStep = 'skills';
            }
            break;
```

- [ ] **Step 3: Add the new `exp_intro_skip` case**

Find (around line 583, the end of the `exp_more` case's closing `break;` before `case 'edu_degree':`):

```js
        case 'exp_more':
            if (answer.toLowerCase().startsWith('yes')) {
                chatAddMsg('Great — what company was that at?', 'ai');
                chatStep = 'exp_company';
            } else {
                chatAddMsg("Now let's cover your education. What's your degree or qualification?", 'ai');
                chatStep = 'edu_degree';
            }
            break;
        case 'edu_degree':
```

Replace with:

```js
        case 'exp_more':
            if (answer.toLowerCase().startsWith('yes')) {
                chatAddMsg('Great — what company was that at?', 'ai');
                chatStep = 'exp_company';
            } else if (chatIsFreshGrad) {
                chatAddMsg('Last thing — list your key skills, separated by commas (e.g. "Canva, Excel, Mandarin").', 'ai');
                chatStep = 'skills';
            } else {
                chatAddMsg("Now let's cover your education. What's your degree or qualification?", 'ai');
                chatStep = 'edu_degree';
            }
            break;
        case 'exp_intro_skip':
            if (['skip', 'no', 'none', ''].includes(answer.trim().toLowerCase())) {
                chatAddMsg('Last thing — list your key skills, separated by commas (e.g. "Canva, Excel, Mandarin").', 'ai');
                chatStep = 'skills';
            } else {
                chatCurrentExp = { company: answer };
                chatAddMsg('And what was your role there?', 'ai');
                chatStep = 'exp_role';
            }
            break;
        case 'edu_degree':
```

(Note: the `exp_more` branch changed here is why fresh-grad flow, after adding an internship/project entry and answering "no" to "add another job", goes straight to skills instead of looping back to education — education already happened first in this flow.)

- [ ] **Step 4: Verify with `php -l`**

Run: `php -l resume_builder.php`
Expected: `No syntax errors detected in resume_builder.php` (this file is PHP with embedded `<script>`; `php -l` only checks the PHP tags, but confirms no stray `?>`/`<?php` breakage from the edit — follow with Step 5 for real JS verification)

- [ ] **Step 5: Manual browser verification — fresh grad path**

Using your logged-in candidate session: open `resume_builder.php`, check "I'm a fresh graduate", switch to "💬 Chat with AI". Confirm:
- First message asks for degree/qualification (not "most recent job").
- After finishing one education entry and answering "No, that's it", it asks about internships/part-time jobs/projects, mentioning you can type 'skip'.
- Typing "skip" jumps straight to the skills question.
- Re-run the chat, this time typing a real answer (e.g. "Marketing Intern at ABC Sdn Bhd") instead of skip — confirm it asks "And what was your role there?" next, then duration, then notes, then "Want to add another job?", and answering "No, that's it" goes to the skills question (not back to education).

- [ ] **Step 6: Manual browser verification — non-fresh-grad path unchanged**

Uncheck "I'm a fresh graduate", switch to Chat mode again (reload the page first so `chatStarted` resets). Confirm the flow is identical to before this change: opens with "tell me about your most recent job," and after experience+education the flow proceeds straight to skills with no internship-skip prompt.

- [ ] **Step 7: Commit**

```bash
git add resume_builder.php
git commit -m "Branch Chat mode question flow for fresh graduates"
```

---

### Task 4: Extract prompt builder + add fresh-grad prompt guidance

**Files:**
- Modify: `ai.php:597-647` (`generate_resume_document`)

**Interfaces:**
- Consumes: `$input['is_fresh_grad']` (bool) from Task 1.
- Produces: `build_resume_prompt($input)` (new function, returns string) — used only within this task, but kept as a named function (rather than inlined) specifically so it can be verified without a network call.

- [ ] **Step 1: Extract prompt-building into `build_resume_prompt()` and add the fresh-grad paragraph**

Find (around line 597-627, the start of `generate_resume_document` through the end of the prompt heredoc):

```php
function generate_resume_document($api_key, $input) {
    if (!empty($api_key)) {
        try {
            $input_json = json_encode($input, JSON_PRETTY_PRINT);
            $prompt = <<<PROMPT
You are an expert resume writer helping a candidate build a professional resume from their own rough notes. Turn the raw input below into polished, professional resume content — proper grammar, active verbs, concise impact-focused bullet points. Do NOT invent facts, companies, numbers, or achievements that are not implied by the candidate's notes — only rephrase and structure what they gave you.

CANDIDATE'S TARGET ROLE AND RAW NOTES (JSON):
{$input_json}

OUTPUT REQUIREMENTS:
Output strictly a JSON object, no text outside the JSON:
{
    "summary": "2-3 sentence professional summary tailored to their target role, based only on the info given.",
    "experience": [
        {
            "company": "as given",
            "role": "as given",
            "duration": "as given",
            "bullets": ["Polished, professional bullet point rewritten from their rough notes", "..."]
        }
    ],
    "education": [
        {"degree": "as given", "school": "as given", "year": "as given"}
    ],
    "skills": ["cleaned up skill", "..."]
}

Keep the same number of experience/education entries as given in the input, in the same order. Each experience entry should have 2-4 bullet points.
PROMPT;

            $response = call_gemini_api($api_key, $prompt);
```

Replace with:

```php
function build_resume_prompt($input) {
    $input_json = json_encode($input, JSON_PRETTY_PRINT);
    $fresh_grad_note = '';
    if (!empty($input['is_fresh_grad'])) {
        $fresh_grad_note = "\n\nThis candidate is a fresh graduate with little or no full-time work history. Write the summary around their education, academic projects, and coursework. Treat any listed internships/part-time work as supporting evidence of transferable skills, not a career history. Do not imply years of professional experience the candidate doesn't have. Emphasize potential, foundational skills, and eagerness to start their career.";
    }
    return <<<PROMPT
You are an expert resume writer helping a candidate build a professional resume from their own rough notes. Turn the raw input below into polished, professional resume content — proper grammar, active verbs, concise impact-focused bullet points. Do NOT invent facts, companies, numbers, or achievements that are not implied by the candidate's notes — only rephrase and structure what they gave you.

CANDIDATE'S TARGET ROLE AND RAW NOTES (JSON):
{$input_json}

OUTPUT REQUIREMENTS:
Output strictly a JSON object, no text outside the JSON:
{
    "summary": "2-3 sentence professional summary tailored to their target role, based only on the info given.",
    "experience": [
        {
            "company": "as given",
            "role": "as given",
            "duration": "as given",
            "bullets": ["Polished, professional bullet point rewritten from their rough notes", "..."]
        }
    ],
    "education": [
        {"degree": "as given", "school": "as given", "year": "as given"}
    ],
    "skills": ["cleaned up skill", "..."]
}

Keep the same number of experience/education entries as given in the input, in the same order. Each experience entry should have 2-4 bullet points.{$fresh_grad_note}
PROMPT;
}

function generate_resume_document($api_key, $input) {
    if (!empty($api_key)) {
        try {
            $prompt = build_resume_prompt($input);

            $response = call_gemini_api($api_key, $prompt);
```

- [ ] **Step 2: Verify with `php -l`**

Run: `php -l ai.php`
Expected: `No syntax errors detected in ai.php`

- [ ] **Step 3: Verify the prompt content with a throwaway script (no network call)**

Create `<scratchpad>/verify_task4_prompt.php`:

```php
<?php
require 'C:/xampp/htdocs/hiresense/ai.php';

$base_input = ['target_title' => 'Junior Analyst', 'experience' => [], 'education' => [], 'skills' => '', 'extra_notes' => ''];

$grad_prompt = build_resume_prompt(array_merge($base_input, ['is_fresh_grad' => true]));
$normal_prompt = build_resume_prompt(array_merge($base_input, ['is_fresh_grad' => false]));

echo (strpos($grad_prompt, 'fresh graduate with little or no full-time work history') !== false)
    ? "PASS: fresh-grad prompt contains guidance paragraph\n"
    : "FAIL: guidance paragraph missing from fresh-grad prompt\n";

echo (strpos($normal_prompt, 'fresh graduate with little or no full-time work history') === false)
    ? "PASS: normal prompt does not contain guidance paragraph\n"
    : "FAIL: guidance paragraph leaked into normal prompt\n";

echo (strpos($grad_prompt, 'Junior Analyst') !== false)
    ? "PASS: prompt still includes candidate input JSON\n"
    : "FAIL: candidate input JSON missing from prompt\n";
```

Run: `php <scratchpad>/verify_task4_prompt.php`
Expected: three `PASS: ...` lines. Delete the script afterward.

- [ ] **Step 4: Commit**

```bash
git add ai.php
git commit -m "Add fresh-graduate guidance to the AI resume prompt"
```

---

### Task 5: Fresh-grad-aware fallback summary

**Files:**
- Modify: `ai.php:548-595` (`generate_fallback_resume_document`)

**Interfaces:**
- Consumes: `$input['is_fresh_grad']` (bool) from Task 1, `$experience`/`$education`/`$target_title` locals already built earlier in the same function.
- Produces: none consumed by later tasks (leaf function, used directly by `generate_resume_document`'s catch-all path and when no API key is configured).

- [ ] **Step 1: Replace the hardcoded summary line**

Find (around line 589-594, inside `generate_fallback_resume_document`):

```php
    return [
        'summary' => "Motivated " . $target_title . " with hands-on experience across " . (count($experience) > 0 ? "roles including " . ($experience[0]['role'] ?: 'recent positions') : "prior positions") . ". Automated draft (AI not configured) — consider refining this summary further.",
        'experience' => $experience,
        'education' => $education,
        'skills' => $skills
    ];
```

Replace with:

```php
    if (!empty($input['is_fresh_grad']) || count($experience) === 0) {
        $edu_phrase = '';
        if (!empty($education)) {
            $edu_phrase = ", with a foundation in " . ($education[0]['degree'] ?: 'their field of study');
            if (!empty($education[0]['school'])) {
                $edu_phrase .= ' from ' . $education[0]['school'];
            }
        }
        $summary = "Motivated recent graduate pursuing a role as " . $target_title . $edu_phrase . ". Automated draft (AI not configured) — consider refining this summary further.";
    } else {
        $summary = "Motivated " . $target_title . " with hands-on experience across roles including " . ($experience[0]['role'] ?: 'recent positions') . ". Automated draft (AI not configured) — consider refining this summary further.";
    }

    return [
        'summary' => $summary,
        'experience' => $experience,
        'education' => $education,
        'skills' => $skills
    ];
```

- [ ] **Step 2: Verify with `php -l`**

Run: `php -l ai.php`
Expected: `No syntax errors detected in ai.php`

- [ ] **Step 3: Verify the fallback summary with a throwaway script**

Create `<scratchpad>/verify_task5_fallback.php`:

```php
<?php
require 'C:/xampp/htdocs/hiresense/ai.php';

// Fresh grad, no experience, has education
$r1 = generate_fallback_resume_document([
    'target_title' => 'Junior Analyst',
    'experience' => [],
    'education' => [['degree' => 'BSc Computer Science', 'school' => 'UM', 'year' => '2026']],
    'skills' => 'Excel, Python',
    'is_fresh_grad' => true
]);
echo (strpos($r1['summary'], 'recent graduate') !== false && strpos($r1['summary'], 'BSc Computer Science') !== false)
    ? "PASS: fresh-grad summary mentions graduate + degree\n"
    : "FAIL: got: {$r1['summary']}\n";

// Not fresh grad, has real experience — unchanged behavior
$r2 = generate_fallback_resume_document([
    'target_title' => 'Marketing Executive',
    'experience' => [['company' => 'BrightWave', 'role' => 'Marketing Assistant', 'duration' => '2023-2025', 'notes' => 'ran campaigns']],
    'education' => [],
    'skills' => 'Canva',
    'is_fresh_grad' => false
]);
echo (strpos($r2['summary'], 'hands-on experience') !== false && strpos($r2['summary'], 'Marketing Assistant') !== false)
    ? "PASS: experienced-candidate summary unchanged\n"
    : "FAIL: got: {$r2['summary']}\n";

// No experience, flag not set at all — should still get the graduate phrasing,
// not the old awkward "prior positions" text
$r3 = generate_fallback_resume_document([
    'target_title' => 'Sales Associate',
    'experience' => [],
    'education' => [],
    'skills' => ''
]);
echo (strpos($r3['summary'], 'prior positions') === false)
    ? "PASS: awkward 'prior positions' phrasing no longer appears\n"
    : "FAIL: got: {$r3['summary']}\n";
```

Run: `php <scratchpad>/verify_task5_fallback.php`
Expected: three `PASS: ...` lines. Delete the script afterward.

- [ ] **Step 4: Commit**

```bash
git add ai.php
git commit -m "Tailor fallback resume summary for fresh graduates and zero-experience input"
```

---

### Task 6: End-to-end verification

**Files:** none (verification only)

**Interfaces:**
- Consumes: everything from Tasks 1-5.
- Produces: nothing (final gate before calling the feature done).

- [ ] **Step 1: Full-file lint pass**

Run: `php -l resume_builder.php && php -l ai.php`
Expected: both print `No syntax errors detected`.

- [ ] **Step 2: End-to-end browser walkthrough — Form mode, fresh grad**

Using your logged-in candidate session: open `resume_builder.php`. Check "I'm a fresh graduate". Fill in Target Job Title, leave the (now relabeled, reordered) internships panel empty or with one entry, fill in one education entry and some skills. Submit. Confirm:
- A resume is generated (no crash, no blank-form bounce — this also re-confirms the `resume_builds` table fix from earlier this session still holds).
- The summary text reads appropriately for a graduate (mentions education/potential, not fabricated years of experience).

- [ ] **Step 3: End-to-end browser walkthrough — Chat mode, fresh grad, skip experience**

Reload, check "I'm a fresh graduate", switch to Chat mode, answer the education question(s), type "skip" at the internships/projects prompt, then provide skills. Confirm generation succeeds and the resume has an education section, an empty/absent experience section, and a sensible summary.

- [ ] **Step 4: End-to-end browser walkthrough — non-fresh-grad regression check**

Reload, leave the checkbox unchecked, use Chat mode as before (job first). Confirm the flow and generated resume look exactly as they did before this feature (no fresh-grad wording leaking in).

- [ ] **Step 5: Clean up scratch verification scripts**

Confirm `<scratchpad>/verify_task1_flag.php`, `verify_task4_prompt.php`, and `verify_task5_fallback.php` were deleted after each task's verification step (per Global Constraints — scratch scripts are never committed). If any remain, delete them now.

- [ ] **Step 6: Final commit (if anything uncommitted remains)**

```bash
git status
```

If clean, nothing to do. Otherwise stage and commit any stragglers with a descriptive message.
