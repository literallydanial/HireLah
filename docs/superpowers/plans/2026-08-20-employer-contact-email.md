# Employer Candidate-Contact Email Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an employer set an optional Contact Email that governs all candidate-related mail: it becomes the Reply-To on candidate-facing emails (interview proposal, questionnaire dispatch) and the destination for the "candidate confirmed interview" notification, falling back to the employer's account login email when unset.

**Architecture:** One new `users.contact_email` column; a small shared helper `employer_helpers.php` (`get_employer_contact($pdo, $employer_id)`) that resolves display name + contact email with the same `COALESCE(NULLIF(...), fallback)` pattern already used for `company_name`; two `mailer.php` functions gain an `addReplyTo(...)` call; two SQL queries that feed the "interview confirmed" notification switch their email source.

**Tech Stack:** PHP (procedural), PDO/MySQL, PHPMailer (already in use via `mailer.php`), no automated test suite — verification is `php -l` plus manual curl/browser testing, same approach used for the two prior features this session.

## Global Constraints

- Contact Email is optional; an empty value is always valid and means "use my account login email" everywhere it's consulted.
- `get_employer_contact()`'s `email` result is **never blank** — `users.email` is a required column, so the `COALESCE` always resolves to a real address even before Contact Email is ever set.
- Only `send_interview_proposal_email()` and `send_questionnaire_email()` in `mailer.php` gain a Reply-To. `send_otp_email()` and `send_password_reset_email()` are account-security emails, not candidate correspondence — out of scope, do not touch them.
- Saving an invalid (non-empty) Contact Email must be rejected with a session error and must not overwrite the stored value.

---

### Task 1: Database migration for the contact email column

**Files:**
- Create: `schema_update10.sql`

**Interfaces:**
- Produces: `users.contact_email` (VARCHAR(255), NULL) — every later task depends on this column existing.

- [ ] **Step 1: Write the migration file**

```sql
-- schema_update10.sql
-- Add optional candidate-contact email column to users table
ALTER TABLE users ADD COLUMN contact_email VARCHAR(255) DEFAULT NULL;
```

- [ ] **Step 2: Apply it to the local XAMPP MySQL database**

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense < schema_update10.sql
```

- [ ] **Step 3: Verify the column exists**

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense -e "DESCRIBE users;"
```

Expected: output includes a `contact_email` row (varchar(255), NULL).

- [ ] **Step 4: Commit**

No git repo in this project — skip, per the pattern from the two prior plans this session.

---

### Task 2: Shared employer-contact helper

**Files:**
- Create: `employer_helpers.php`

**Interfaces:**
- Produces: `get_employer_contact($pdo, $employer_id): array` returning `['name' => string, 'email' => string]`. `email` is always a non-empty string (see Global Constraints). Every later task that needs an employer's display name/contact address depends on this function.

- [ ] **Step 1: Write the helper**

```php
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
```

- [ ] **Step 2: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/employer_helpers.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

No git repo — skip.

---

### Task 3: Contact Email field in Company Settings

**Files:**
- Modify: `profile.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (this task only reads/writes `users.contact_email` directly by column name, added in Task 1).
- Produces: `users.contact_email` gets populated by employers via the UI — Tasks 5/6 read it (Task 5 via `get_employer_contact()` from Task 2, Task 6 via a direct SQL `COALESCE`).

- [ ] **Step 1: Validate and save `contact_email` in the `update_company_info` handler**

Change:

```php
    elseif ($action === 'update_company_info') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can update company settings.";
            header("Location: profile.php");
            exit;
        }
        $company_name = trim($_POST['company_name'] ?? '');
        $company_website = trim($_POST['company_website'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');

        $stmt = $pdo->prepare("UPDATE users SET company_name = ?, company_website = ?, company_address = ? WHERE id = ?");
        if ($stmt->execute([$company_name, $company_website, $company_address, $user_id])) {
            $_SESSION['toast'] = "Company settings saved.";
        } else {
            $_SESSION['error'] = "Failed to save company settings.";
        }
        header("Location: profile.php");
        exit;
    }
```

to:

```php
    elseif ($action === 'update_company_info') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can update company settings.";
            header("Location: profile.php");
            exit;
        }
        $company_name = trim($_POST['company_name'] ?? '');
        $company_website = trim($_POST['company_website'] ?? '');
        $company_address = trim($_POST['company_address'] ?? '');
        $contact_email = trim($_POST['contact_email'] ?? '');

        if ($contact_email !== '' && !filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['error'] = "'" . htmlspecialchars($contact_email) . "' is not a valid email address.";
            header("Location: profile.php");
            exit;
        }

        $stmt = $pdo->prepare("UPDATE users SET company_name = ?, company_website = ?, company_address = ?, contact_email = ? WHERE id = ?");
        if ($stmt->execute([$company_name, $company_website, $company_address, $contact_email, $user_id])) {
            $_SESSION['toast'] = "Company settings saved.";
        } else {
            $_SESSION['error'] = "Failed to save company settings.";
        }
        header("Location: profile.php");
        exit;
    }
```

- [ ] **Step 2: Add the field to the Company Settings form**

Change:

```php
                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Address</label>
                            <textarea name="company_address" rows="3" placeholder="Level 5, Jalan Example, 50000 Kuala Lumpur"><?= htmlspecialchars($user['company_address'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn-primary" style="width:auto; padding:10px 24px;">Save Changes &rarr;</button>
```

to:

```php
                        <div style="margin-bottom:16px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Candidate Contact Email (Optional)</label>
                            <input type="email" name="contact_email" placeholder="hr@acme.com &mdash; leave blank to use your account email" value="<?= htmlspecialchars($user['contact_email'] ?? '') ?>">
                        </div>

                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Address</label>
                            <textarea name="company_address" rows="3" placeholder="Level 5, Jalan Example, 50000 Kuala Lumpur"><?= htmlspecialchars($user['company_address'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn-primary" style="width:auto; padding:10px 24px;">Save Changes &rarr;</button>
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/profile.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

1. Log in as an employer, open Settings → Company Settings.
2. Enter `not-an-email`, save — confirm a session error appears ("... is not a valid email address.") and the field's stored value is unchanged (reload the page to confirm).
3. Enter a valid address (e.g. `hr@acme-test.com`), save — confirm the toast and that the field shows the saved value after reload.
4. Clear the field back to blank, save — confirm it saves successfully (blank is valid).

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 4: Reply-To support in mailer.php

**Files:**
- Modify: `mailer.php`

**Interfaces:**
- Consumes: nothing from earlier tasks directly (this task only changes function signatures/bodies).
- Produces: `send_interview_proposal_email(..., $reply_to_email = '')` and `send_questionnaire_email(..., $employer_name = '', $reply_to_email = '')` — new trailing optional parameters. Task 5's call-site updates depend on these exact new parameter positions and names.

- [ ] **Step 1: Add Reply-To to `send_questionnaire_email`**

Change:

```php
function send_questionnaire_email($candidate_name, $candidate_email, $job_title, $q_title, $request_token, $questions_list = []) {
```

to:

```php
function send_questionnaire_email($candidate_name, $candidate_email, $job_title, $q_title, $request_token, $questions_list = [], $employer_name = '', $reply_to_email = '') {
```

Then change:

```php
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        // Dynamic Base URL Resolution (Adapts to root htdocs/ or subfolder on InfinityFree)
```

to:

```php
        $mail->setFrom($from_email, $from_name);
        if (!empty($reply_to_email)) {
            $mail->addReplyTo($reply_to_email, $employer_name ?: 'Hiring Team');
        }
        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        // Dynamic Base URL Resolution (Adapts to root htdocs/ or subfolder on InfinityFree)
```

- [ ] **Step 2: Add Reply-To to `send_interview_proposal_email`**

Change:

```php
function send_interview_proposal_email($candidate_name, $candidate_email, $job_title, $employer_name, $interview_datetime, $interview_notes, $interview_token) {
```

to:

```php
function send_interview_proposal_email($candidate_name, $candidate_email, $job_title, $employer_name, $interview_datetime, $interview_notes, $interview_token, $reply_to_email = '') {
```

Then change:

```php
        $mail->setFrom($from_email, $from_name);
        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        // Dynamic Base URL Resolution
```

to:

```php
        $mail->setFrom($from_email, $from_name);
        if (!empty($reply_to_email)) {
            $mail->addReplyTo($reply_to_email, $employer_name ?: 'Hiring Team');
        }
        $mail->addAddress($candidate_email, $candidate_name ?: 'Candidate');

        // Dynamic Base URL Resolution
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/mailer.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

Exercised together with Task 7's end-to-end pass (both new parameters default to empty/blank, so existing callers — none of which pass them yet until Task 5 — keep working identically; this step alone can't be observed from the UI).

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 5: Wire the helper into candidate.php's send actions

**Files:**
- Modify: `candidate.php`

**Interfaces:**
- Consumes: `get_employer_contact($pdo, $employer_id)` from Task 2; the new `$reply_to_email` (and `$employer_name` for the questionnaire function) parameters from Task 4.
- Produces: nothing consumed by later tasks — leaf task.

- [ ] **Step 1: Require the new helper**

Change:

```php
<?php
session_start();
require 'db.php';
require_once 'questionnaire_helpers.php';
```

to:

```php
<?php
session_start();
require 'db.php';
require_once 'questionnaire_helpers.php';
require_once 'employer_helpers.php';
```

- [ ] **Step 2: Replace the ad-hoc company-name lookup in `schedule_interview` with the shared helper**

Change:

```php
            require_once 'mailer.php';
            $emp_lookup = $pdo->prepare("SELECT COALESCE(NULLIF(company_name, ''), name) as employer_display_name FROM users WHERE id = ?");
            $emp_lookup->execute([$_SESSION['user_id']]);
            $emp_name = $emp_lookup->fetchColumn() ?: ($_SESSION['user_name'] ?? 'Hiring Manager');
            $mail_res = send_interview_proposal_email($cand_data['name'], $cand_data['email'], $cand_data['job_title'], $emp_name, $datetime, $notes, $token);
```

to:

```php
            require_once 'mailer.php';
            $employer_contact = get_employer_contact($pdo, $_SESSION['user_id']);
            $mail_res = send_interview_proposal_email($cand_data['name'], $cand_data['email'], $cand_data['job_title'], $employer_contact['name'], $datetime, $notes, $token, $employer_contact['email']);
```

- [ ] **Step 3: Pass the employer's contact info into the questionnaire dispatch**

Change:

```php
            if ($cand_info && !empty($cand_info['email']) && $q_info) {
                $q_list = json_decode($q_info['questions_json'], true) ?: [];
                require_once __DIR__ . '/mailer.php';
                $email_res = send_questionnaire_email(
                    $cand_info['name'],
                    $cand_info['email'],
                    $cand_info['job_title'],
                    $q_info['title'],
                    $token,
                    $q_list
                );
```

to:

```php
            if ($cand_info && !empty($cand_info['email']) && $q_info) {
                $q_list = json_decode($q_info['questions_json'], true) ?: [];
                require_once __DIR__ . '/mailer.php';
                $employer_contact = get_employer_contact($pdo, $_SESSION['user_id']);
                $email_res = send_questionnaire_email(
                    $cand_info['name'],
                    $cand_info['email'],
                    $cand_info['job_title'],
                    $q_info['title'],
                    $token,
                    $q_list,
                    $employer_contact['name'],
                    $employer_contact['email']
                );
```

- [ ] **Step 4: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/candidate.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 5: Manual verification**

Exercised together with Task 7's end-to-end pass (needs actual sent emails to inspect headers).

- [ ] **Step 6: Commit**

No git repo — skip.

---

### Task 6: Route the interview-confirmed notification through Contact Email

**Files:**
- Modify: `candidate_dashboard.php`
- Modify: `confirm_interview.php`

**Interfaces:**
- Consumes: `users.contact_email` from Task 1.
- Produces: nothing consumed by later tasks — leaf task, bundled since both are the same one-line SQL change.

- [ ] **Step 1: `candidate_dashboard.php`**

Change:

```php
            $c_info = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, u.email as employer_email FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.id = ?");
```

to:

```php
            $c_info = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, COALESCE(NULLIF(u.contact_email, ''), u.email) as employer_email FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.id = ?");
```

- [ ] **Step 2: `confirm_interview.php`**

Change:

```php
    $stmt = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, u.email as employer_email
                           FROM candidates c
                           LEFT JOIN jobs j ON c.job_id = j.id
                           LEFT JOIN users u ON j.employer_id = u.id
                           WHERE c.interview_token = ?");
```

to:

```php
    $stmt = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, COALESCE(NULLIF(u.contact_email, ''), u.email) as employer_email
                           FROM candidates c
                           LEFT JOIN jobs j ON c.job_id = j.id
                           LEFT JOIN users u ON j.employer_id = u.id
                           WHERE c.interview_token = ?");
```

- [ ] **Step 3: Syntax-check both files**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/candidate_dashboard.php" && "/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/confirm_interview.php"
```

Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manual verification**

Exercised together with Task 7's end-to-end pass.

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 7: End-to-end verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: the complete feature from Tasks 1–6.

- [ ] **Step 1: Baseline — fallback behavior with Contact Email unset**

Using a fresh test employer (Contact Email left blank) and a fresh test candidate with an application:

1. Propose an interview. Check `mailer.php`'s actual PHPMailer state or the resulting email (via the existing `test_smtp`/logging path, or by inspecting what `send_interview_proposal_email` received) — confirm a Reply-To header is present and equals the employer's account login email.
2. As the candidate, confirm the interview. Confirm the resulting `send_interview_confirmed_email` call's recipient is the employer's account login email (query `questionnaire_requests`/check toast, or add a temporary `error_log` if headers aren't otherwise inspectable, then remove it).

- [ ] **Step 2: Set a Contact Email and repeat**

1. As the employer, set Contact Email to a distinct test address (e.g. `hr+test@example.com`) via Company Settings.
2. Propose a second interview to the same or a new test candidate application — confirm the Reply-To is now the Contact Email, and the display name matches `company_name` (or account name if unset).
3. Send a questionnaire to a candidate — confirm its Reply-To is also the Contact Email.
4. Confirm that interview — confirm the "interview confirmed" notification's recipient is now the Contact Email, not the login email.

- [ ] **Step 3: Invalid input rejected**

Attempt to save `not-an-email` as Contact Email — confirm it's rejected (Task 3 Step 4 already covers this in isolation; just re-confirm here as part of the full flow with real data present).

- [ ] **Step 4: Clear it and confirm fallback returns**

Clear Contact Email back to blank, save, propose another interview — confirm Reply-To falls back to the login email again (proving the fallback isn't "sticky" to whatever was set once).

- [ ] **Step 5: Clean up test data**

Delete any test employer/candidate accounts, jobs, applications, and questionnaire_requests created solely for this verification (ask the user first, matching the approach used for the two prior features this session).

- [ ] **Step 6: Report results**

Summarize what was verified and any issues found/fixed to the user.
