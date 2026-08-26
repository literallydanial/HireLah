# Candidate AI Match Tab Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the "🤖 AI Match & Analysis" tab on `candidate.php` to match the supplied mockup — Rank/Pass badges, a radial score gauge, a job title moved into the header, a new Skills chip row, and reordered content ending with the "Why We Should Hire" pitch panel.

**Architecture:** A single new PHP query computes the candidate's rank among their job's other applicants. The existing `recommendation` field is mapped to a Pass/Fail label with no new data. The `.score-circle` CSS rule becomes a conic-gradient ring instead of a flat border. The tab's existing content blocks (bars, Strengths/Gaps, Education/Experience, Pitch panel) are reordered in place and a new Skills chip block (using the `$skills` array already decoded at the top of the file) is inserted — no new files, no schema changes, no changes to the other three tabs.

**Tech Stack:** PHP (procedural), PDO/MySQL, plain HTML/CSS matching existing `candidate.php` conventions — no build step, no automated test suite. Verification is `php -l` plus manual in-browser testing, same approach used for prior work this session.

## Global Constraints

- Rank is standard competition-style ranking (ties share a rank number; the next distinct lower score skips ahead accordingly) — computed as `COUNT(candidates on the same job_id with a strictly higher overall_score) + 1`.
- Pass/Fail is derived only from the existing `recommendation` field: `Strong Hire`/`Hire` → Pass (green), `Maybe`/`Do Not Hire` → Fail (red). No new database column.
- The other three tabs (Messages & Interview, Questionnaires, Resume & Video) and the action bar above the tabs are out of scope — do not modify them.
- Reuse the `$skills` array already decoded at candidate.php:297 (`$skills = json_decode($c['skills'], true) ?: [];`) for the new Skills chip row — do not add a second decode of the same column.

---

### Task 1: Rank computation and header job-title move

**Files:**
- Modify: `candidate.php`

**Interfaces:**
- Produces: `$candidate_rank` (int), available to the badge-row markup added in Task 3.

- [ ] **Step 1: Add the rank query**

Change (`candidate.php`, right after the existing score/skills/strengths/gaps setup):

```php
$score = $c['overall_score'];
$color = $score >= 80 ? 'var(--grn)' : ($score >= 60 ? 'var(--acc)' : ($score >= 40 ? 'var(--org)' : 'var(--red)'));
$skills = json_decode($c['skills'], true) ?: [];
$strengths = json_decode($c['strengths'], true) ?: [];
$gaps = json_decode($c['gaps'], true) ?: [];
?>
```

to:

```php
$score = $c['overall_score'];
$color = $score >= 80 ? 'var(--grn)' : ($score >= 60 ? 'var(--acc)' : ($score >= 40 ? 'var(--org)' : 'var(--red)'));
$skills = json_decode($c['skills'], true) ?: [];
$strengths = json_decode($c['strengths'], true) ?: [];
$gaps = json_decode($c['gaps'], true) ?: [];

$rank_stmt = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE job_id = ? AND overall_score > ?");
$rank_stmt->execute([$c['job_id'], $c['overall_score']]);
$candidate_rank = (int)$rank_stmt->fetchColumn() + 1;
?>
```

- [ ] **Step 2: Move the job title into the profile header**

Change:

```php
                    <div>
                        <div style="font-size:21px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($c['name']) ?></div>
                        <div style="font-size:12px; color:var(--mut); margin-top:3px; display:flex; gap:12px; flex-wrap:wrap;">
                            <?php if($c['email']): ?><span>📧 <?= htmlspecialchars($c['email']) ?></span><?php endif; ?>
                            <?php if($c['phone']): ?><span>📱 <?= htmlspecialchars($c['phone']) ?></span><?php endif; ?>
                        </div>
                    </div>
```

to:

```php
                    <div>
                        <div style="font-size:21px; font-weight:800; color:var(--txt);"><?= htmlspecialchars($c['name']) ?></div>
                        <div style="font-size:12px; color:var(--acc); font-weight:700; margin-top:2px;">Applied for: <?= htmlspecialchars($c['job_title']) ?></div>
                        <div style="font-size:12px; color:var(--mut); margin-top:3px; display:flex; gap:12px; flex-wrap:wrap;">
                            <?php if($c['email']): ?><span>📧 <?= htmlspecialchars($c['email']) ?></span><?php endif; ?>
                            <?php if($c['phone']): ?><span>📱 <?= htmlspecialchars($c['phone']) ?></span><?php endif; ?>
                        </div>
                    </div>
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/candidate.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

Open any candidate's page — confirm the job title now appears under the candidate's name in the profile header (the badge row below still shows the old chips for now; that's fixed in Task 3).

- [ ] **Step 5: Commit**

No git repo in this project — skip, per the pattern from prior plans this session.

---

### Task 2: Radial score gauge

**Files:**
- Modify: `candidate.php`

**Interfaces:**
- Consumes: `$score`, `$color` (already defined at candidate.php:295-296).
- Produces: nothing consumed by later tasks — leaf visual task.

- [ ] **Step 1: Replace the `.score-circle` CSS rule**

Change:

```css
        .score-circle {
            width: 84px; height: 84px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800; font-family: monospace;
            border: 4px solid <?= $color ?>; color: <?= $color ?>;
            box-shadow: 0 0 10px <?= $color ?>40;
        }
```

to:

```css
        .score-circle {
            width: 84px; height: 84px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 24px; font-weight: 800; font-family: monospace;
            color: <?= $color ?>;
            background: conic-gradient(<?= $color ?> <?= $score ?>%, var(--dim) <?= $score ?>% 100%);
            box-shadow: 0 0 10px <?= $color ?>40;
            position: relative;
        }
        .score-circle::before {
            content: '';
            position: absolute;
            width: 68px; height: 68px; border-radius: 50%;
            background: var(--card);
        }
        .score-circle span {
            position: relative;
            z-index: 1;
        }
```

- [ ] **Step 2: Wrap the score number in a `<span>` so it sits above the mask**

Change:

```php
                    <div class="score-circle"><?= $score ?></div>
```

to:

```php
                    <div class="score-circle"><span><?= $score ?></span></div>
```

- [ ] **Step 3: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/candidate.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 4: Manual verification**

Open a candidate with a low score (e.g. 25%) and one with a high score (e.g. 85%) — confirm the circle now renders as a partial colored ring proportional to the score (not a full solid-color border), with the score number legible and centered on top of the ring.

- [ ] **Step 5: Commit**

No git repo — skip.

---

### Task 3: Badge row — Rank and Pass/Fail

**Files:**
- Modify: `candidate.php`

**Interfaces:**
- Consumes: `$candidate_rank` from Task 1, `$c['recommendation']`, `$c['status']`.
- Produces: nothing consumed by later tasks — leaf task.

- [ ] **Step 1: Replace the badge row**

Change:

```php
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                            <span class="chip" style="color:var(--txt); border-color:var(--bdr); background:var(--dim)"><?= htmlspecialchars($c['job_title']) ?></span>
                            <?php 
                            $v_color = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'var(--grn)' : ($c['recommendation'] == 'Maybe' ? 'var(--org)' : 'var(--red)');
                            ?>
                            <span class="chip" style="color:<?= $v_color ?>; border-color:<?= $v_color ?>; background:transparent; font-weight:700;">
                                💡 Recommendation: <?= htmlspecialchars($c['recommendation']) ?>
                            </span>
                            <span class="chip chip-<?= strtolower($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span>
                        </div>
```

to:

```php
                        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px;">
                            <span class="chip" style="color:var(--org); border-color:var(--org); background:transparent; font-weight:700;">
                                🏆 Rank #<?= $candidate_rank ?>
                            </span>
                            <?php
                            $is_pass = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire');
                            $pass_color = $is_pass ? 'var(--grn)' : 'var(--red)';
                            ?>
                            <span class="chip" style="color:<?= $pass_color ?>; border-color:<?= $pass_color ?>; background:transparent; font-weight:700;">
                                <?= $is_pass ? '✅ Pass' : '❌ Fail' ?>
                            </span>
                            <span class="chip chip-<?= strtolower($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span>
                        </div>
```

- [ ] **Step 2: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/candidate.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

1. Open a candidate whose `recommendation` is `Strong Hire` or `Hire` — confirm a green "✅ Pass" badge.
2. Open a candidate whose `recommendation` is `Maybe` or `Do Not Hire` — confirm a red "❌ Fail" badge.
3. Confirm the Rank badge shows a number, and the existing status chip (e.g. "Questionnaire Sent") still appears — but the job-title chip is gone from this row (it moved to the header in Task 1).
4. The "💡 Recommendation: Strong Hire" full label is still visible further down, inside the "Why We Should Hire" panel (unchanged) — confirm that text wasn't lost, just no longer duplicated in the badge row.

- [ ] **Step 4: Commit**

No git repo — skip.

---

### Task 4: Reorder content and add the Skills chip row

**Files:**
- Modify: `candidate.php`

**Interfaces:**
- Consumes: `$skills` (already decoded at candidate.php:297 — do not redeclare it).
- Produces: nothing consumed by later tasks — leaf task, but the last content change before verification.

- [ ] **Step 1: Move Education/Experience above the Pitch panel and insert the Skills chip row between them**

Change (the entire region from the "Why We Should Hire Them (Pitch)" comment through the closing `</div>` of the Education/Experience `detail-grid`):

```php
                <!-- Why We Should Hire Them (Pitch) -->
                <?php if($c['note']): ?>
                    <?php 
                    $v_color = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'var(--grn)' : ($c['recommendation'] == 'Maybe' ? 'var(--org)' : 'var(--red)');
                    $v_bg = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'rgba(0, 232, 122, 0.08)' : ($c['recommendation'] == 'Maybe' ? 'rgba(255, 140, 66, 0.08)' : 'rgba(255, 77, 106, 0.08)');
                    $v_border = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'rgba(0, 232, 122, 0.25)' : ($c['recommendation'] == 'Maybe' ? 'rgba(255, 140, 66, 0.25)' : 'rgba(255, 77, 106, 0.25)');
                    ?>
                    <div style="background:<?= $v_bg ?>; border:1px solid <?= $v_border ?>; border-radius:14px; padding:18px; margin-bottom:20px;">
                        <div style="font-size:11px; color:<?= $v_color ?>; font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:8px;">
                            🚀 Why We Should Hire Them (The Pitch)
                        </div>
                        <div style="font-size:14px; color:var(--txt); line-height:1.6; font-weight:500;">
                            <?= htmlspecialchars($c['note']) ?>
                        </div>
                        
                        <?php if($c['suggested_question']): ?>
                            <div style="margin-top:14px; padding-top:14px; border-top:1px solid <?= $v_border ?>; font-size:13px; color:var(--txt);">
                                <strong style="color:<?= $v_color ?>;">🎯 Suggested Interview Focus:</strong>
                                <div style="margin-top:4px; font-weight:500; line-height:1.5;">
                                    <?= nl2br(htmlspecialchars($c['suggested_question'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="detail-grid">
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:7px;">🎓 Education History</div>
                        <div style="font-size:13px; color:var(--txt);"><?= htmlspecialchars($c['education'] ?: '-') ?></div>
                    </div>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:7px;">💼 Work Experience</div>
                        <div style="font-size:13px; color:var(--txt);"><?= htmlspecialchars($c['experience'] ?: '-') ?></div>
                    </div>
                </div>
```

to:

```php
                <div class="detail-grid">
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:7px;">🎓 Education History</div>
                        <div style="font-size:13px; color:var(--txt);"><?= htmlspecialchars($c['education'] ?: '-') ?></div>
                    </div>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:7px;">💼 Work Experience</div>
                        <div style="font-size:13px; color:var(--txt);"><?= htmlspecialchars($c['experience'] ?: '-') ?></div>
                    </div>
                </div>

                <?php if (!empty($skills)): ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px; margin-bottom:20px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px;">🧩 Skills</div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ($skills as $skill): ?>
                                <span class="chip" style="color:var(--txt); border-color:var(--bdr); background:var(--dim);"><?= htmlspecialchars($skill) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Why We Should Hire Them (Pitch) -->
                <?php if($c['note']): ?>
                    <?php 
                    $v_color = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'var(--grn)' : ($c['recommendation'] == 'Maybe' ? 'var(--org)' : 'var(--red)');
                    $v_bg = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'rgba(0, 232, 122, 0.08)' : ($c['recommendation'] == 'Maybe' ? 'rgba(255, 140, 66, 0.08)' : 'rgba(255, 77, 106, 0.08)');
                    $v_border = ($c['recommendation'] == 'Strong Hire' || $c['recommendation'] == 'Hire') ? 'rgba(0, 232, 122, 0.25)' : ($c['recommendation'] == 'Maybe' ? 'rgba(255, 140, 66, 0.25)' : 'rgba(255, 77, 106, 0.25)');
                    ?>
                    <div style="background:<?= $v_bg ?>; border:1px solid <?= $v_border ?>; border-radius:14px; padding:18px; margin-bottom:20px;">
                        <div style="font-size:11px; color:<?= $v_color ?>; font-weight:800; letter-spacing:1px; text-transform:uppercase; margin-bottom:8px;">
                            🚀 Why We Should Hire Them (The Pitch)
                        </div>
                        <div style="font-size:14px; color:var(--txt); line-height:1.6; font-weight:500;">
                            <?= htmlspecialchars($c['note']) ?>
                        </div>
                        
                        <?php if($c['suggested_question']): ?>
                            <div style="margin-top:14px; padding-top:14px; border-top:1px solid <?= $v_border ?>; font-size:13px; color:var(--txt);">
                                <strong style="color:<?= $v_color ?>;">🎯 Suggested Interview Focus:</strong>
                                <div style="margin-top:4px; font-weight:500; line-height:1.5;">
                                    <?= nl2br(htmlspecialchars($c['suggested_question'])) ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
```

- [ ] **Step 2: Syntax-check the file**

```bash
"/c/xampp/php/php.exe" -l "C:/xampp/htdocs/hiresense/candidate.php"
```

Expected: `No syntax errors detected`

- [ ] **Step 3: Manual verification**

1. Open a candidate whose `skills` column has data — confirm a "🧩 Skills" panel with pill chips appears between the Education/Experience row and the "Why We Should Hire" panel.
2. Open a candidate with an empty/null `skills` column — confirm no empty Skills box appears (the `<?php if (!empty($skills)): ?>` guard hides it entirely).
3. Scroll the tab top to bottom and confirm the new order: score/badges/summary → match bars → Strengths/Gaps → Education/Experience → Skills → "Why We Should Hire" pitch panel.
4. Switch to the other three tabs (Messages & Interview, Questionnaires, Resume & Video) — confirm they're unchanged.

- [ ] **Step 4: Commit**

No git repo — skip.

---

### Task 5: End-to-end verification

**Files:**
- None (verification only).

**Interfaces:**
- Consumes: the complete feature from Tasks 1–4.

- [ ] **Step 1: Verify Rank against real data**

Pick a job with 2+ applicants. For one of its candidates, run:

```bash
"C:/xampp/mysql/bin/mysql.exe" -u root hiresense -e "SELECT id, name, overall_score FROM candidates WHERE job_id = <that job's id> ORDER BY overall_score DESC;"
```

Manually count how many rows have a strictly higher `overall_score` than the candidate you're viewing, add 1, and confirm it matches the "🏆 Rank #" badge shown on that candidate's page.

- [ ] **Step 2: Verify Pass/Fail across all four recommendation values**

Using existing candidates (or by temporarily viewing ones already in each bucket), confirm: `Strong Hire` and `Hire` both show green "✅ Pass"; `Maybe` and `Do Not Hire` both show red "❌ Fail".

- [ ] **Step 3: Visual pass over the whole tab**

Open a handful of different candidates (varying scores, varying skills data) and confirm:
- Header shows "Applied for: {job title}" under the name.
- Score circle is a partial ring proportional to score, not a full border.
- Badge row shows Rank, Pass/Fail, and the existing status chip — no job-title chip there anymore.
- Content order is: bars → Strengths/Gaps → Education/Experience → Skills (when present) → Pitch panel.

- [ ] **Step 4: Confirm the other tabs and action bar are untouched**

Click through "💬 Messages & Interview", "📋 Questionnaires", "📄 Resume & Video", and check the status/Send Questions/Schedule Interview/Delete buttons above the tabs — confirm none of this changed.

- [ ] **Step 5: Report results**

Summarize what was verified and any issues found/fixed to the user.
