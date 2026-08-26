# Admin Dashboard Visual Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `admin_dashboard.php` a "sleek and modern" visual pass — gradient stat tiles, glass-accented health cards, filled-pill tabs, and restyled tables — that adapts to the site's existing light/dark toggle, with zero functional change.

**Architecture:** New gradient/glow CSS custom properties are added to `admin_dashboard.php`'s own `<style>` block (page-scoped, not the shared `style.css`), split across `:root` and `[data-theme="dark"]` exactly like the site's existing theme tokens. All new visual rules use selectors scoped to admin-dashboard-only ancestor classes (`.stats-summary-grid .stat-box`, `.admin-tr .chip-rejected`, etc.) — several classes involved (`.stat-box`, `.chip`, `.chip-rejected`/`.chip-review`/`.chip-shortlisted`) are shared globally with other pages (`employer_dashboard.php`, `job_dashboard.php`, and every candidate status chip site-wide), so nothing here may touch the bare global class definitions in `style.css`.

**Tech Stack:** Plain CSS custom properties + PHP (procedural, existing conventions) — no build step, no JS changes, no automated test suite. Verification is `php -l` plus manual in-browser testing (light and dark mode), same approach used all session.

## Global Constraints

- **No functional change.** Every form, button, search/filter script (`filterUsersTable()`), and the tab-switching JS (`switchAdminTab()`) must work exactly as before — this plan only changes CSS and adds a few inline `<span>`/class attributes.
- **Must not affect other pages.** `.stat-box` is also used by `employer_dashboard.php` and `job_dashboard.php`; `.chip`/`.chip-rejected`/`.chip-review`/`.chip-shortlisted` are used site-wide. Every new rule must be written with a compound selector scoped to an admin-dashboard-only ancestor class (`.stats-summary-grid`, `.admin-tr`, `.health-card`, `.tab-btn`) — never edit the bare global class rules in `style.css`.
- **Adapts to light/dark**, not permanently dark — every new gradient/glow token gets both a `:root` (light) and `[data-theme="dark"]` value, mirroring the site's existing pattern already in `style.css`.
- All new CSS lives inside `admin_dashboard.php`'s existing inline `<style>` block (the one starting at `.page-header { ... }`), not in `style.css`.

---

### Task 1: Gradient/glow design tokens and base rules

**Files:**
- Modify: `admin_dashboard.php`

**Interfaces:**
- Produces: CSS custom properties `--grad-purple`, `--grad-violet`, `--grad-green`, `--grad-amber`, `--grad-red`, `--glow-purple`, `--glow-violet`, `--glow-green`, `--glow-amber`, `--glow-red` (each with a `:root` and `[data-theme="dark"]` value); CSS classes `.stat-grad-purple`, `.stat-grad-violet`, `.stat-grad-green`, `.stat-grad-amber`, `.accent-red`, `.accent-amber`, `.accent-green`, `.avatar-chip`. Tasks 2–6 apply these classes to markup; none of them need to know the token values, only the class/token names.

- [ ] **Step 1: Add the token and base-rule block**

Change (the top of the existing inline `<style>` block):

```php
    <style>
        .page-header {
```

to:

```php
    <style>
        :root {
            --grad-purple: linear-gradient(135deg, #8B5CF6, #6D28D9);
            --grad-violet: linear-gradient(135deg, #6366F1, #4338CA);
            --grad-green:  linear-gradient(135deg, #10B981, #047857);
            --grad-amber:  linear-gradient(135deg, #F59E0B, #B45309);
            --grad-red:    linear-gradient(135deg, #F43F5E, #BE123C);
            --glow-purple: 0 8px 20px rgba(139, 92, 246, 0.25);
            --glow-violet: 0 8px 20px rgba(99, 102, 241, 0.25);
            --glow-green:  0 8px 20px rgba(16, 185, 129, 0.25);
            --glow-amber:  0 8px 20px rgba(245, 158, 11, 0.25);
            --glow-red:    0 8px 20px rgba(244, 63, 94, 0.25);
        }
        [data-theme="dark"] {
            --grad-purple: linear-gradient(135deg, #7C3AED, #4C1D95);
            --grad-violet: linear-gradient(135deg, #4F46E5, #3730A3);
            --grad-green:  linear-gradient(135deg, #059669, #064E3B);
            --grad-amber:  linear-gradient(135deg, #D97706, #78350F);
            --grad-red:    linear-gradient(135deg, #E11D48, #881337);
            --glow-purple: 0 8px 24px rgba(124, 58, 237, 0.4);
            --glow-violet: 0 8px 24px rgba(79, 70, 229, 0.4);
            --glow-green:  0 8px 24px rgba(5, 150, 105, 0.35);
            --glow-amber:  0 8px 24px rgba(217, 119, 6, 0.35);
            --glow-red:    0 8px 24px rgba(225, 29, 72, 0.35);
        }
        .stat-grad-purple { background: var(--grad-purple); box-shadow: var(--glow-purple); }
        .stat-grad-violet { background: var(--grad-violet); box-shadow: var(--glow-violet); }
        .stat-grad-green  { background: var(--grad-green);  box-shadow: var(--glow-green); }
        .stat-grad-amber  { background: var(--grad-amber);  box-shadow: var(--glow-amber); }
        .avatar-chip {
            width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: #fff;
            flex-shrink: 0;
        }
        .page-header {
```

- [ ] **Step 2: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/admin_dashboard.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

Load the page — confirm it renders exactly as before (these are just new, unused-so-far CSS rules/tokens; no visual change yet).

- [ ] **Step 4: Commit**

No git repo in this project — skip, per the pattern from every prior plan this session.

---

### Task 2: Gradient stat tiles

**Files:**
- Modify: `admin_dashboard.php`

**Interfaces:**
- Consumes: `.stat-grad-purple`/`.stat-grad-violet`/`.stat-grad-green`/`.stat-grad-amber` from Task 1.
- Produces: nothing consumed by later tasks — leaf visual task.

- [ ] **Step 1: Add the scoped white-text override rules**

Change (right after the `.avatar-chip` rule added in Task 1):

```php
        .avatar-chip {
            width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: #fff;
            flex-shrink: 0;
        }
        .page-header {
```

to:

```php
        .avatar-chip {
            width: 26px; height: 26px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 11px; font-weight: 800; color: #fff;
            flex-shrink: 0;
        }
        .stats-summary-grid .stat-box {
            border: none;
        }
        .stats-summary-grid .stat-box .stat-val,
        .stats-summary-grid .stat-box .stat-lbl {
            color: #fff !important;
        }
        .stats-summary-grid .stat-box .stat-lbl {
            opacity: 0.85;
        }
        .stats-summary-grid .stat-box .logo-box {
            background: rgba(255, 255, 255, 0.18) !important;
            border-color: transparent !important;
            color: #fff !important;
        }
        .page-header {
```

- [ ] **Step 2: Apply the gradient classes to the 4 tiles**

Change:

```php
        <div class="stats-summary-grid">
            <div class="stat-box">
                <div class="logo-box" style="color:var(--acc); border-color:var(--acc);">👥</div>
                <div><div class="stat-val" style="color:var(--acc)"><?= $total_users ?></div><div class="stat-lbl">Registered Accounts</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--grn); border-color:var(--grn);">💼</div>
                <div><div class="stat-val" style="color:var(--grn)"><?= $total_jobs ?></div><div class="stat-lbl">Total Job Openings</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--pur); border-color:var(--pur);">🤖</div>
                <div><div class="stat-val" style="color:var(--pur)"><?= $total_candidates_eval ?></div><div class="stat-lbl">Resumes Evaluated</div></div>
            </div>
            <div class="stat-box">
                <div class="logo-box" style="color:var(--gold); border-color:var(--gold);">⏳</div>
                <div><div class="stat-val" style="color:var(--gold)"><?= $unverified_users ?></div><div class="stat-lbl">Pending OTP Users</div></div>
            </div>
        </div>
```

to:

```php
        <div class="stats-summary-grid">
            <div class="stat-box stat-grad-purple">
                <div class="logo-box">👥</div>
                <div><div class="stat-val"><?= $total_users ?></div><div class="stat-lbl">Registered Accounts</div></div>
            </div>
            <div class="stat-box stat-grad-green">
                <div class="logo-box">💼</div>
                <div><div class="stat-val"><?= $total_jobs ?></div><div class="stat-lbl">Total Job Openings</div></div>
            </div>
            <div class="stat-box stat-grad-violet">
                <div class="logo-box">🤖</div>
                <div><div class="stat-val"><?= $total_candidates_eval ?></div><div class="stat-lbl">Resumes Evaluated</div></div>
            </div>
            <div class="stat-box stat-grad-amber">
                <div class="logo-box">⏳</div>
                <div><div class="stat-val"><?= $unverified_users ?></div><div class="stat-lbl">Pending OTP Users</div></div>
            </div>
        </div>
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/admin_dashboard.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

1. Load the dashboard in light mode — confirm the 4 stat tiles now show solid gradients (purple, green, violet, amber) with white numbers/labels and a translucent icon badge, replacing the flat white/bordered tiles.
2. Toggle dark mode (🌙) — confirm the gradients swap to their darker variants automatically.
3. Visit `employer_dashboard.php` or `job_dashboard.php` (whichever has stat boxes) — confirm their stat tiles are **unchanged** (still flat, no gradient) — this is the check that the scoping in Step 1 didn't leak.

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 3: Health card badges and metric chips

**Files:**
- Modify: `admin_dashboard.php`

**Interfaces:**
- Consumes: `--grad-green`, `--grad-red`, `--glow-green` (via inline PHP-computed colors, not the CSS classes — the health cards' colors are dynamic per status, computed in PHP as `$db_status_color`/`$claude_status_color`, so this task uses inline `style` gradients keyed off those same PHP variables rather than the fixed `.stat-grad-*` classes).
- Produces: nothing consumed by later tasks — leaf visual task.

- [ ] **Step 1: Gradient-fill the DB Health status badge**

Change:

```php
                    <span class="badge-pill" style="background:rgba(0, 232, 122, 0.12); color:<?= $db_status_color ?>; border:1px solid <?= $db_status_color ?>;">
                        ● <?= $db_status ?>
                    </span>
```

to:

```php
                    <span class="badge-pill" style="background:linear-gradient(135deg, <?= $db_status_color ?>, <?= $db_status_color ?>CC); color:#fff; border:none; box-shadow:0 4px 12px <?= $db_status_color ?>40;">
                        ● <?= $db_status ?>
                    </span>
```

- [ ] **Step 2: Gradient-fill the "Connection Latency" headline metric**

Change:

```php
                    <div><span style="color:var(--mut);">Connection Latency:</span> <strong style="color:var(--grn);"><?= $db_ping_ms ?> ms</strong></div>
```

to:

```php
                    <div><span style="color:var(--mut);">Connection Latency:</span> <strong style="background:var(--grad-green); -webkit-background-clip:text; background-clip:text; color:transparent;"><?= $db_ping_ms ?> ms</strong></div>
```

- [ ] **Step 3: Gradient-fill the Claude AI Engine status badge**

Change:

```php
                    <span class="badge-pill" style="background:rgba(157, 38, 255, 0.12); color:<?= $claude_status_color ?>; border:1px solid <?= $claude_status_color ?>;">
                        ● <?= $claude_status ?>
                    </span>
```

to:

```php
                    <span class="badge-pill" style="background:linear-gradient(135deg, <?= $claude_status_color ?>, <?= $claude_status_color ?>CC); color:#fff; border:none; box-shadow:0 4px 12px <?= $claude_status_color ?>40;">
                        ● <?= $claude_status ?>
                    </span>
```

- [ ] **Step 4: Gradient-fill the "Live Key Check" headline metric**

Change:

```php
                    <div><span style="color:var(--mut);">Live Key Check:</span> <strong style="color:var(--pur);"><?= $claude_latency_ms ?> ms</strong></div>
```

to:

```php
                    <div><span style="color:var(--mut);">Live Key Check:</span> <strong style="background:var(--grad-violet); -webkit-background-clip:text; background-clip:text; color:transparent;"><?= $claude_latency_ms ?> ms</strong></div>
```

- [ ] **Step 5: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/admin_dashboard.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 6: Manual verification**

1. Confirm the DB Health and Claude AI Engine status badges now show a solid gradient-filled pill with white text instead of translucent-background colored text, in both light and dark mode.
2. Confirm "Connection Latency" and "Live Key Check" values now render with a gradient-filled text effect instead of flat colored text.
3. Re-trigger the three API key states from the earlier feature (Active / Not Configured / Model Not Available, by temporarily editing `config.json`'s `ai_model`/`api_key` and reloading with a fresh login session, then restoring it) — confirm each status color still flows correctly into the new gradient badge.

- [ ] **Step 7: Commit**

No git repo — skip.

---

### Task 4: Filled-pill active tab

**Files:**
- Modify: `admin_dashboard.php`

**Interfaces:**
- Consumes: `--grad-purple` from Task 1.
- Produces: nothing consumed by later tasks — leaf visual task.

- [ ] **Step 1: Replace the underline active-tab style with a filled pill**

Change:

```php
        .tab-btn {
            padding: 10px 18px;
            border: none;
            background: transparent;
            color: var(--mut);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
        }
        .tab-btn.active {
            color: var(--acc);
            border-bottom-color: var(--acc);
        }
```

to:

```php
        .tab-btn {
            padding: 10px 18px;
            border: none;
            border-radius: 10px;
            background: transparent;
            color: var(--mut);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .tab-btn.active {
            color: #fff;
            background: var(--grad-purple);
            box-shadow: var(--glow-purple);
        }
```

- [ ] **Step 2: Remove the now-unneeded bottom border from the tab bar container**

Change:

```php
        .tab-controls {
            display: flex;
            gap: 8px;
            border-bottom: 1px solid var(--bdr);
            margin-bottom: 20px;
        }
```

to:

```php
        .tab-controls {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            padding-bottom: 4px;
        }
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/admin_dashboard.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

1. Confirm the active tab ("👥 Registered Accounts" by default) now shows as a filled purple-gradient rounded pill instead of a plain underline.
2. Click "💼 Job Postings Moderation" — confirm the active pill moves to that tab and the inactive one returns to plain text (tab-switching JS `switchAdminTab()` is unchanged, so this should just work).

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 5: Registered Accounts table restyle

**Files:**
- Modify: `admin_dashboard.php`

**Interfaces:**
- Consumes: `.avatar-chip` from Task 1; `--grad-red`/`--grad-amber`/`--grad-green` from Task 1.
- Produces: nothing consumed by later tasks — leaf visual task.

- [ ] **Step 1: Add row hover/accent-bar base rules and gradient chip overrides, scoped to `.admin-tr`**

Change (append right after the `.admin-th` rule, before the `@media` block):

```php
        .admin-th {
            background: var(--surf);
            font-size: 11px;
            color: var(--mut);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            padding: 12px 16px;
            border-bottom: 2px solid var(--bdr);
        }
        @media (max-width: 1024px) {
```

to:

```php
        .admin-th {
            background: var(--surf);
            font-size: 11px;
            color: var(--mut);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            font-weight: 700;
            padding: 12px 16px;
            border-bottom: 2px solid var(--bdr);
        }
        .admin-tr {
            border-left: 3px solid transparent;
        }
        .admin-tr:not(.admin-th):hover {
            background: var(--dim);
        }
        .admin-tr.accent-red   { border-left-color: #F43F5E; }
        .admin-tr.accent-amber { border-left-color: #F59E0B; }
        .admin-tr.accent-green { border-left-color: #10B981; }
        .admin-tr .chip-rejected {
            background: var(--grad-red) !important;
            color: #fff !important;
            border-color: transparent !important;
        }
        .admin-tr .chip-review {
            background: var(--grad-amber) !important;
            color: #fff !important;
            border-color: transparent !important;
        }
        .admin-tr .chip-shortlisted {
            background: var(--grad-green) !important;
            color: #fff !important;
            border-color: transparent !important;
        }
        @media (max-width: 1024px) {
```

(The `!important` here is defensive, not structural — a bare `.admin-tr .chip-rejected` compound selector already outweighs the single-class `.chip-rejected` rule in `style.css` on specificity alone, but keeping `!important` guards against any future same-or-higher-specificity rule added to `style.css` later. This rule only ever matches inside `.admin-tr`, which exists only on this page, so `.chip-rejected`/`.chip-review`/`.chip-shortlisted` remain completely unaffected everywhere else in the app.)

- [ ] **Step 2: Add the row-level accent class and an avatar-initial chip in the Name cell**

Change:

```php
                    <?php foreach($users_list as $u): ?>
                        <div class="admin-tr user-row-item" data-search="<?= strtolower(htmlspecialchars($u['name'] . ' ' . $u['email'] . ' ' . $u['role'])) ?>" data-role="<?= htmlspecialchars($u['role']) ?>">
                            <div style="font-weight:700; color:var(--mut);">#<?= $u['id'] ?></div>
                            <div style="font-weight:800; color:var(--txt); display:flex; align-items:center; gap:6px;">
                                <span><?= htmlspecialchars($u['name']) ?></span>
                            </div>
```

to:

```php
                    <?php foreach($users_list as $u): ?>
                        <?php
                            $u_accent = $u['role'] === 'admin' ? 'accent-red' : ($u['role'] === 'employer' ? 'accent-amber' : 'accent-green');
                            $u_grad = $u['role'] === 'admin' ? 'var(--grad-red)' : ($u['role'] === 'employer' ? 'var(--grad-amber)' : 'var(--grad-green)');
                        ?>
                        <div class="admin-tr user-row-item <?= $u_accent ?>" data-search="<?= strtolower(htmlspecialchars($u['name'] . ' ' . $u['email'] . ' ' . $u['role'])) ?>" data-role="<?= htmlspecialchars($u['role']) ?>">
                            <div style="font-weight:700; color:var(--mut);">#<?= $u['id'] ?></div>
                            <div style="font-weight:800; color:var(--txt); display:flex; align-items:center; gap:8px;">
                                <span class="avatar-chip" style="background:<?= $u_grad ?>;"><?= strtoupper(substr($u['name'] ?: 'U', 0, 1)) ?></span>
                                <span><?= htmlspecialchars($u['name']) ?></span>
                            </div>
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/admin_dashboard.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

1. Confirm every row in the Registered Accounts table now shows a colored left accent bar (red for admin, amber for employer, green for candidate), a gradient-filled role chip matching that same color, and a gradient avatar-initial circle before the name.
2. Hover a row — confirm a subtle background highlight appears (and that the header row, which has the `admin-th` class, does **not** get a hover highlight).
3. Confirm the search box and role filter (`filterUsersTable()`) still work — search for a name/email and filter by role, confirming rows show/hide exactly as before.
4. Confirm the Verify/Unverify and Delete Account buttons/forms in each row still work unchanged.
5. Visit a page elsewhere in the app that shows a `chip-rejected`/`chip-review`/`chip-shortlisted` badge outside this table (e.g. a candidate's application status chip) — confirm it still renders with its original flat color, not the new gradient — this is the check that the `.admin-tr` scoping in Step 1 didn't leak.

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 6: Job Postings Moderation table restyle

**Files:**
- Modify: `admin_dashboard.php`

**Interfaces:**
- Consumes: `.accent-green` and the `.admin-tr .chip-shortlisted` gradient rule from Task 5 (both already apply automatically — this task only adds the row-level class, no new CSS).
- Produces: nothing consumed by later tasks — leaf task, last visual change before verification.

- [ ] **Step 1: Add the accent class to each job row**

Change:

```php
                    <?php foreach($jobs_list as $j): ?>
                        <div class="admin-tr" style="grid-template-columns: 60px 1.8fr 1.4fr 110px 110px 100px;">
```

to:

```php
                    <?php foreach($jobs_list as $j): ?>
                        <div class="admin-tr accent-green" style="grid-template-columns: 60px 1.8fr 1.4fr 110px 110px 100px;">
```

(Every job row already uses the `chip-shortlisted` class for its status chip regardless of the job's actual `status` value — a pre-existing quirk, unchanged and out of scope here — so every row gets the same green accent, consistent with what the chip already visually implies today.)

- [ ] **Step 2: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/admin_dashboard.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

1. Open the "💼 Job Postings Moderation" tab — confirm every row now shows a green left accent bar and a gradient-filled green status chip, and the row hover-highlight works the same as the Registered Accounts table.
2. Confirm the "Delete" button/form on each job row still works unchanged.

- [ ] **Step 4: Commit**

No git repo — skip.

---

### Task 7: End-to-end verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: the complete feature from Tasks 1–6.

- [ ] **Step 1: Full light-mode pass**

Load `admin_dashboard.php` in light mode as a real admin. Confirm: 4 gradient stat tiles, gradient badges/metrics on both health cards, filled-pill active tab, gradient accent bars/chips/avatars on both tables — all rendering with good white-on-gradient text contrast.

- [ ] **Step 2: Full dark-mode pass**

Toggle 🌙 dark mode. Confirm every element from Step 1 swaps to its darker gradient variant automatically, matching how the rest of the site's dark mode already behaves — no element stuck in a light-mode color or vice versa.

- [ ] **Step 3: Confirm zero functional regressions**

Exercise every interactive element on the page: user search box, role filter dropdown, Verify/Unverify buttons, Delete Account forms, Add User modal (open, fill, cancel/submit), tab switching between Registered Accounts and Job Postings Moderation, and the Delete button on a job row. All must behave exactly as before this redesign.

- [ ] **Step 4: Confirm no leakage to other pages**

Load `employer_dashboard.php` (or `job_dashboard.php`) and any page showing a candidate status chip (e.g. a candidate's application card) — confirm none of them picked up the new gradient styling. This is the final check that every new rule stayed scoped to `admin_dashboard.php`.

- [ ] **Step 5: Responsive check**

Resize the browser to a narrow viewport (or use the browser's device toolbar) and confirm the existing `@media (max-width: 1024px)` rules still collapse the stat grid, health grid, and table columns sensibly with the new gradient styling applied — no overflow or broken layout.

- [ ] **Step 6: Report results**

Summarize what was verified and any issues found/fixed to the user.
