# Company Settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give employers a "Company Settings" panel on their existing Settings page (`profile.php`) to record a Company Name, Website, Address, and Logo, and make that company name (with fallback to the account name) show up everywhere candidates currently see the employer's personal name.

**Architecture:** Four new nullable columns on the existing `users` table (no new table — one employer account maps to one company in this app). A new panel + two new POST actions in `profile.php`, following the exact patterns already used there for `upload_picture`/`upload_resume`. Eight existing `SELECT ... u.name as employer_name` queries (plus one session-variable lookup in `candidate.php`) get their `employer_name` source swapped to prefer `company_name`.

**Tech Stack:** PHP (procedural, no framework), PDO/MySQL, plain HTML/CSS (existing `style.css` + inline styles, matching `profile.php` conventions), no JS build step, no automated test suite in this project — verification is manual `php -l` syntax checks, direct SQL checks, and in-browser testing.

## Global Constraints

- Company Logo upload: accept `png`, `jpg`, `jpeg`, `svg`, `webp`, `gif`; reject anything else; max size 2MB (2 * 1024 * 1024 bytes) — copied from the spec's UI section.
- New POST actions (`update_company_info`, `upload_company_logo`) must reject non-employer roles server-side, matching the defense-in-depth pattern already added for the admin-only settings actions in this same file.
- `employer_name` in every listed query/lookup becomes `COALESCE(NULLIF(u.company_name, ''), u.name)` — company name wins when non-empty, personal account name is the fallback. Never drop the fallback (many existing employer accounts will have no company name set).
- Follow the existing migration numbering convention (`schema_update.sql` … `schema_update7.sql` → this is `schema_update8.sql`).

---

### Task 1: Database migration for company columns

**Files:**
- Create: `schema_update8.sql`

**Interfaces:**
- Produces: `users.company_name` (VARCHAR 255, NULL), `users.company_website` (VARCHAR 255, NULL), `users.company_address` (TEXT, NULL), `users.company_logo` (VARCHAR 255, NULL) — every later task that reads/writes these columns depends on this migration having been applied to the local DB.

- [ ] **Step 1: Write the migration file**

```sql
-- schema_update8.sql
-- Add company profile columns to users table (employer-only, but column applies table-wide like profile_picture)
ALTER TABLE users ADD COLUMN company_name VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN company_website VARCHAR(255) DEFAULT NULL;
ALTER TABLE users ADD COLUMN company_address TEXT DEFAULT NULL;
ALTER TABLE users ADD COLUMN company_logo VARCHAR(255) DEFAULT NULL;
```

- [ ] **Step 2: Apply it to the local XAMPP MySQL database**

Run (adjust path if your XAMPP install differs from the default):

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense < schema_update8.sql
```

- [ ] **Step 3: Verify the columns exist**

Run:

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense -e "DESCRIBE users;"
```

Expected: output includes rows for `company_name`, `company_website`, `company_address`, `company_logo`, all `YES` under `Null` and `NULL` under `Default`.

- [ ] **Step 4: Commit**

No git repo in this project — skip. Just leave `schema_update8.sql` in the project root alongside the other `schema_update*.sql` files.

---

### Task 2: Backend handlers — `update_company_info` and `upload_company_logo`

**Files:**
- Modify: `profile.php` (POST handler block, right after the existing `elseif ($action === 'upload_picture')` block which currently ends around line 157, and before `elseif ($action === 'upload_resume')`)

**Interfaces:**
- Consumes: `$user` (assoc array from `SELECT * FROM users WHERE id = ?`, already loaded at the top of `profile.php`), `$pdo`, `$user_id` — all already in scope in `profile.php`.
- Produces: on success, `users.company_name`/`company_website`/`company_address`/`company_logo` are updated for `$user_id`; sets `$_SESSION['toast']` or `$_SESSION['error']`; redirects to `profile.php`. Task 3's form posts `action=update_company_info` (fields `company_name`, `company_website`, `company_address`) and `action=upload_company_logo` (file field `company_logo`) to this same handler.

- [ ] **Step 1: Add the `update_company_info` handler**

In `profile.php`, insert this new `elseif` branch immediately after the closing `}` of the existing `elseif ($action === 'upload_picture') { ... }` block (i.e., right before `elseif ($action === 'upload_resume') {`):

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

- [ ] **Step 2: Add the `upload_company_logo` handler**

Immediately after the block from Step 1, still before `elseif ($action === 'upload_resume')`:

```php
    elseif ($action === 'upload_company_logo') {
        if ($user['role'] !== 'employer') {
            $_SESSION['error'] = "Access denied. Only employer accounts can update company settings.";
            header("Location: profile.php");
            exit;
        }
        $file = $_FILES['company_logo'] ?? null;
        if (!$file || $file['error'] === UPLOAD_ERR_INI_SIZE || ($file['size'] ?? 0) > 2 * 1024 * 1024) {
            $_SESSION['error'] = "Uploaded logo exceeds the maximum allowed size limit (2MB).";
        } elseif ($file && $file['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'svg', 'gif'])) {
                $upload_dir = 'uploads/company_logos/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                    $_SESSION['error'] = "Directory 'uploads/company_logos/' is not writable. Please check FTP permissions.";
                } else {
                    $path = $upload_dir . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $path)) {
                        $stmt = $pdo->prepare("UPDATE users SET company_logo = ? WHERE id = ?");
                        $stmt->execute([$path, $user_id]);
                        $_SESSION['toast'] = "Company logo updated.";
                    } else {
                        $_SESSION['error'] = "Failed to save company logo.";
                    }
                }
            } else {
                $_SESSION['error'] = "Invalid image format. Use JPG, PNG, SVG, WEBP or GIF.";
            }
        }
        header("Location: profile.php");
        exit;
    }
```

- [ ] **Step 3: Syntax-check the file**

Run:

```bash
php -l profile.php
```

Expected: `No syntax errors detected in profile.php`

- [ ] **Step 4: Commit**

No git repo — skip (per Task 1 Step 4).

---

### Task 3: Company Settings UI panel

**Files:**
- Modify: `profile.php` (inside the `<?php if($user['role'] === 'employer'): ?>` ... `<?php endif; ?>` block that currently only shows for admins — see note below — this task adds a **separate, always-shown-to-employers** panel)

**Interfaces:**
- Consumes: `$user['company_name']`, `$user['company_website']`, `$user['company_address']`, `$user['company_logo']` (all nullable, from the `SELECT * FROM users WHERE id = ?` already run at the top of `profile.php`); posts to the `update_company_info` and `upload_company_logo` actions from Task 2.
- Produces: nothing consumed by later tasks — this is a leaf UI task.

**Important context:** `profile.php` currently has this structure for the employer role (from earlier work in this session):

```php
<?php if($user['role'] === 'admin'): ?>
    <!-- Anthropic AI & PHPMailer SMTP Settings panel (admin-only) -->
<?php elseif($user['role'] === 'candidate'): ?>
    <!-- Saved Default Resume + Danger Zone panels -->
<?php endif; ?>
```

Employers currently fall through to neither branch and see nothing extra. This task adds a **new, separate** `if($user['role'] === 'employer')` block for the Company Settings panel — do not nest it inside the admin or candidate branches.

- [ ] **Step 1: Locate the insertion point**

In `profile.php`, find the "Password Security Form" panel (`<div class="panel-title">🔒 Password Security</div>`, closes with `</form></div>` followed by `<?php if($user['role'] === 'admin'): ?>`). The new panel goes immediately after that closing `</div>` and before `<?php if($user['role'] === 'admin'): ?>`.

- [ ] **Step 2: Add the Company Settings panel**

```php
            <?php if($user['role'] === 'employer'): ?>
                <!-- Company Settings Panel -->
                <div class="panel" style="grid-column: 1 / -1;">
                    <div class="panel-title">🏢 Company Settings</div>
                    <p style="font-size:13px; color:var(--mut); margin-bottom:16px;">Used on candidate-facing emails and exported reports.</p>

                    <form method="POST">
                        <input type="hidden" name="action" value="update_company_info">

                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Company Name</label>
                                <input type="text" name="company_name" placeholder="Acme Sdn Bhd" value="<?= htmlspecialchars($user['company_name'] ?? '') ?>">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Website</label>
                                <input type="url" name="company_website" placeholder="https://acme.com" value="<?= htmlspecialchars($user['company_website'] ?? '') ?>">
                            </div>
                        </div>

                        <div style="margin-bottom:20px;">
                            <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:6px;">Address</label>
                            <textarea name="company_address" rows="3" placeholder="Level 5, Jalan Example, 50000 Kuala Lumpur"><?= htmlspecialchars($user['company_address'] ?? '') ?></textarea>
                        </div>

                        <button type="submit" class="btn-primary" style="width:auto; padding:10px 24px;">Save Changes &rarr;</button>
                    </form>

                    <div style="border-top:1px dashed var(--bdr); margin-top:20px; padding-top:16px;">
                        <label style="display:block; font-size:12px; font-weight:600; color:var(--mut); margin-bottom:10px;">Company Logo</label>
                        <form method="POST" enctype="multipart/form-data" style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                            <input type="hidden" name="action" value="upload_company_logo">
                            <div style="width:64px; height:64px; border-radius:10px; background:var(--surf); border:1px solid var(--bdr); display:flex; align-items:center; justify-content:center; overflow:hidden;">
                                <?php if(!empty($user['company_logo'])): ?>
                                    <img src="<?= htmlspecialchars($user['company_logo']) ?>" alt="Company logo" style="width:100%; height:100%; object-fit:contain;">
                                <?php else: ?>
                                    <span style="font-size:24px;">🏢</span>
                                <?php endif; ?>
                            </div>
                            <div>
                                <input type="file" name="company_logo" accept=".png,.jpg,.jpeg,.svg,.webp,.gif" required style="margin-bottom:6px;">
                                <button type="submit" class="btn-secondary" style="padding:8px 16px; font-size:12px; display:block;">Upload logo</button>
                            </div>
                            <div style="font-size:11px; color:var(--mut);">PNG, JPG, SVG, WebP or GIF &middot; up to 2MB</div>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
```

- [ ] **Step 3: Syntax-check the file**

Run:

```bash
php -l profile.php
```

Expected: `No syntax errors detected in profile.php`

- [ ] **Step 4: Manual verification**

1. Start XAMPP (Apache + MySQL), log in as an employer account, open `profile.php` via the "⚙️ Settings" dashboard link.
2. Confirm the "🏢 Company Settings" panel renders with empty fields (no company data yet) and a placeholder 🏢 icon where the logo preview goes.
3. Fill in Company Name, Website, Address, click "Save Changes &rarr;" — confirm the toast "Company settings saved." appears and the fields still show the saved values after the redirect.
4. Upload a small PNG as the logo, click "Upload logo" — confirm the toast "Company logo updated." appears and the thumbnail now shows the uploaded image.
5. Log in as a candidate and as an admin — confirm neither sees the Company Settings panel.

- [ ] **Step 5: Commit**

No git repo — skip (per Task 1 Step 4).

---

### Task 4: Wire company name into candidate-facing surfaces

**Files:**
- Modify: `jobs.php:14`
- Modify: `candidate_dashboard.php:16`, `candidate_dashboard.php:37`
- Modify: `confirm_interview.php:15`
- Modify: `notifications_helper.php:114`, `notifications_helper.php:160`
- Modify: `admin_dashboard.php:220`, `admin_dashboard.php:229`, `admin_dashboard.php:237`
- Modify: `profile.php:225`
- Modify: `candidate.php:163-165`

**Interfaces:**
- Consumes: `users.company_name` from Task 1.
- Produces: nothing consumed by later tasks — this is a leaf task, but it must be done in one pass since all 9 edits are the same mechanical substitution and are easiest to verify together.

- [ ] **Step 1: `jobs.php` — public job listing query**

Change:

```php
$sql = "SELECT j.*, u.name as employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id WHERE j.status = 'Active'";
```

to:

```php
$sql = "SELECT j.*, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id WHERE j.status = 'Active'";
```

- [ ] **Step 2: `candidate_dashboard.php` — interview-confirmed email trigger query (line 16)**

Change:

```php
$c_info = $pdo->prepare("SELECT c.*, j.job_title, u.name as employer_name, u.email as employer_email FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.id = ?");
```

to:

```php
$c_info = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, u.email as employer_email FROM candidates c LEFT JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.id = ?");
```

- [ ] **Step 3: `candidate_dashboard.php` — applications list query (line 37)**

Change:

```php
$stmt = $pdo->prepare("SELECT c.*, j.job_title, u.name as employer_name FROM candidates c JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.user_id = ? ORDER BY c.created_at DESC");
```

to:

```php
$stmt = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM candidates c JOIN jobs j ON c.job_id = j.id LEFT JOIN users u ON j.employer_id = u.id WHERE c.user_id = ? ORDER BY c.created_at DESC");
```

- [ ] **Step 4: `confirm_interview.php` — interview token lookup (line 15)**

Change:

```php
    $stmt = $pdo->prepare("SELECT c.*, j.job_title, u.name as employer_name, u.email as employer_email 
                           FROM candidates c 
                           LEFT JOIN jobs j ON c.job_id = j.id 
                           LEFT JOIN users u ON j.employer_id = u.id 
                           WHERE c.interview_token = ?");
```

to:

```php
    $stmt = $pdo->prepare("SELECT c.*, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, u.email as employer_email 
                           FROM candidates c 
                           LEFT JOIN jobs j ON c.job_id = j.id 
                           LEFT JOIN users u ON j.employer_id = u.id 
                           WHERE c.interview_token = ?");
```

- [ ] **Step 5: `notifications_helper.php` — interview notifications query (line 114)**

Change:

```php
        SELECT c.id as candidate_id, c.interview_status, c.interview_datetime, c.interview_notes, COALESCE(c.updated_at, c.created_at) as int_time, j.job_title, u.name as employer_name
```

to:

```php
        SELECT c.id as candidate_id, c.interview_status, c.interview_datetime, c.interview_notes, COALESCE(c.updated_at, c.created_at) as int_time, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name
```

- [ ] **Step 6: `notifications_helper.php` — unread messages query (line 160)**

Change:

```php
        SELECT m.id, m.candidate_id, m.body, m.created_at, j.job_title, u.name as employer_name
```

to:

```php
        SELECT m.id, m.candidate_id, m.body, m.created_at, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name
```

- [ ] **Step 7: `admin_dashboard.php` — per-job funnel query (lines 219-231)**

Change:

```php
$admin_job_funnels = $pdo->query("
    SELECT j.id as job_id, j.job_title, u.name as employer_name,
           COUNT(c.id) as applied,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) IN ('review', 'under review', 'reviewing', 'new', '') THEN 1 ELSE 0 END) as review,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) IN ('shortlisted', 'shortlist') THEN 1 ELSE 0 END) as shortlisted,
           SUM(CASE WHEN c.interview_status IN ('Proposed', 'Confirmed') THEN 1 ELSE 0 END) as interviewing,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM jobs j
    LEFT JOIN users u ON j.employer_id = u.id
    LEFT JOIN candidates c ON c.job_id = j.id
    GROUP BY j.id, j.job_title, u.name
    ORDER BY applied DESC, j.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
```

to:

```php
$admin_job_funnels = $pdo->query("
    SELECT j.id as job_id, j.job_title, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name,
           COUNT(c.id) as applied,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) IN ('review', 'under review', 'reviewing', 'new', '') THEN 1 ELSE 0 END) as review,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) IN ('shortlisted', 'shortlist') THEN 1 ELSE 0 END) as shortlisted,
           SUM(CASE WHEN c.interview_status IN ('Proposed', 'Confirmed') THEN 1 ELSE 0 END) as interviewing,
           SUM(CASE WHEN LOWER(COALESCE(c.status, '')) = 'rejected' THEN 1 ELSE 0 END) as rejected
    FROM jobs j
    LEFT JOIN users u ON j.employer_id = u.id
    LEFT JOIN candidates c ON c.job_id = j.id
    GROUP BY j.id, j.job_title, u.name, u.company_name
    ORDER BY applied DESC, j.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
```

Note: `u.company_name` is added to `GROUP BY` alongside `u.name` since it's now part of the selected (non-aggregated) expression — required under MySQL's `ONLY_FULL_GROUP_BY` mode.

- [ ] **Step 8: `admin_dashboard.php` — jobs list query (line 237)**

Change:

```php
$jobs_list = $pdo->query("SELECT j.*, u.name as employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id ORDER BY j.created_at DESC")->fetchAll();
```

to:

```php
$jobs_list = $pdo->query("SELECT j.*, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name FROM jobs j LEFT JOIN users u ON j.employer_id = u.id ORDER BY j.created_at DESC")->fetchAll();
```

- [ ] **Step 9: `profile.php` — candidate's own applications query (line 225)**

Change:

```php
            SELECT c.*, j.job_title, j.department, u.name as employer_name 
```

to:

```php
            SELECT c.*, j.job_title, j.department, COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name 
```

- [ ] **Step 10: `candidate.php` — interview proposal email trigger (lines 163-165)**

Change:

```php
            require_once 'mailer.php';
            $emp_name = $_SESSION['user_name'] ?? 'Hiring Manager';
            $mail_res = send_interview_proposal_email($cand_data['name'], $cand_data['email'], $cand_data['job_title'], $emp_name, $datetime, $notes, $token);
```

to:

```php
            require_once 'mailer.php';
            $emp_lookup = $pdo->prepare("SELECT COALESCE(NULLIF(company_name, ''), name) as employer_display_name FROM users WHERE id = ?");
            $emp_lookup->execute([$_SESSION['user_id']]);
            $emp_name = $emp_lookup->fetchColumn() ?: ($_SESSION['user_name'] ?? 'Hiring Manager');
            $mail_res = send_interview_proposal_email($cand_data['name'], $cand_data['email'], $cand_data['job_title'], $emp_name, $datetime, $notes, $token);
```

- [ ] **Step 11: Syntax-check every modified file**

Run:

```bash
php -l jobs.php && php -l candidate_dashboard.php && php -l confirm_interview.php && php -l notifications_helper.php && php -l admin_dashboard.php && php -l profile.php && php -l candidate.php
```

Expected: `No syntax errors detected` for all seven files.

- [ ] **Step 12: Manual verification**

1. As the employer account you set a company name for in Task 3, post a job (or use an existing one) — view it on `jobs.php` (logged out or as a candidate) and confirm the company name (not the employer's personal name) is shown.
2. As a candidate, apply to that job, then check `candidate_dashboard.php` — confirm the application card shows the company name.
3. As the employer, propose an interview for that candidate — confirm the notification/email flow uses the company name (check via the existing `test_smtp` action or by inspecting the toast/notification text).
4. Log in as a second employer account that has **not** set a company name — confirm job listings and the candidate dashboard still show that employer's personal account name (fallback still works).
5. As an admin, open `admin_dashboard.php` — confirm the jobs table and per-job funnel both show company names where set, personal names otherwise, and that the page loads without a SQL error (this is the check that the `GROUP BY` fix in Step 7 is correct).

- [ ] **Step 13: Commit**

No git repo — skip (per Task 1 Step 4).
