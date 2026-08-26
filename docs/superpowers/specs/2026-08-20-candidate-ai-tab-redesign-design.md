# Candidate AI Match Tab Redesign — Design

Date: 2026-08-20

## Problem

The employer supplied a mockup of a candidate summary card (header with
name/email/phone, Rank/Pass/status badges, a radial score gauge with a
one-line summary, skill/experience/education bars, Strengths/Gaps, then
Education/Experience text and a Skills chip row) and asked for the
existing "🤖 AI Match & Analysis" tab on `candidate.php` to be redesigned
to match it.

The current tab (`candidate.php`, `#tab_content_ai`, roughly lines
443–569) already has most of this content — score circle, three match
bars, the Strengths/Gaps grid (already using the natural-sentence wording
fixed earlier this session), an Education/Experience text row, and a
"🚀 Why We Should Hire Them" pitch panel — just in a different order, with
a different badge set, no radial gauge, and no rendering of the `skills`
JSON column anywhere.

## Scope decisions (from clarifying questions)

- **Rank badge**: for the candidate's specific `job_id`, count how many of
  that job's other candidates have a strictly higher `overall_score`; rank
  = that count + 1. This is standard competition-style ranking: candidates
  tied on `overall_score` show the same rank number (e.g. two candidates
  tied for the top score both show "Rank #1"), and the next distinct lower
  score jumps to reflect the tie (e.g. "Rank #3", skipping #2) — not
  sequential 1-2-3 ranks regardless of ties.
- **Pass/Fail badge**: derived from the existing `recommendation` field —
  `Strong Hire`/`Hire` → "Pass" (green), `Maybe`/`Do Not Hire` → "Fail"
  (red). No new data.
- **Job title** moves from the badge row into the profile header (a small
  line under the candidate's name: "Applied for: {job_title}").
- **Existing status chip** (e.g. "Questionnaire Sent") is unchanged and
  moves into the same badge row as Rank/Pass.
- **Score circle** becomes a real radial progress gauge (conic-gradient
  arc proportional to the score) instead of a plain solid-color ring.
- **New Skills chip row**: `candidates.skills` (JSON array, already
  populated by `ai.php`) gets rendered as wrapped pill chips — this data
  exists today but isn't shown anywhere in this tab.
- **Content order** becomes: header (unchanged) → score/badges/summary →
  skill/exp/edu bars (unchanged) → Strengths/Gaps grid (unchanged) →
  Education/Experience text row (moved earlier, content unchanged) → new
  Skills chip row → "Why We Should Hire" pitch panel (moved to the end).
- Nothing about the other three tabs (Messages & Interview, Questionnaires,
  Resume & Video) or the action bar above the tabs changes.

## Rank query

```php
$rank_stmt = $pdo->prepare("SELECT COUNT(*) FROM candidates WHERE job_id = ? AND overall_score > ?");
$rank_stmt->execute([$c['job_id'], $c['overall_score']]);
$candidate_rank = (int)$rank_stmt->fetchColumn() + 1;
```

Placed alongside the existing `$strengths`/`$gaps` decode lines (around
line 298), since `$c` is already loaded by that point.

## Header change

Change (around line 357–363):

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

## Score circle → radial gauge

The `.score-circle` CSS rule (currently a flat `border: 4px solid <?= $color ?>`
circle) is replaced with a conic-gradient ring: the colored arc covers
`$score`% of the circle, the rest is a dim track, with an inner white/card
circle masked on top so only a ring shows, and the score number centered:

```css
.score-circle {
    width: 84px; height: 84px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 24px; font-weight: 800; font-family: monospace;
    color: <?= $color ?>;
    background: conic-gradient(<?= $color ?> <?= $score ?>%, var(--dim) <?= $score ?>% 100%);
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

The PHP that renders it changes from `<div class="score-circle"><?= $score ?></div>`
to `<div class="score-circle"><span><?= $score ?></span></div>` so the
number sits above the pseudo-element mask.

## Badge row change

Change (lines 449–458):

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

(The recommendation label itself — "Strong Hire" vs "Hire" — isn't lost;
it's still visible in the "💡 Recommendation" line further down in the
"Why We Should Hire" panel, unchanged.)

## Content reordering + new Skills chip row

The tab's block order (all content itself unchanged except as noted
above) becomes:

1. Score/badges/summary block (existing, modified per above).
2. Skill/Experience/Education match bars (existing, unmodified).
3. Strengths/Gaps grid (existing, unmodified).
4. Education History / Work Experience text row (existing block, moved up
   to directly after Strengths/Gaps instead of after the Pitch panel).
5. **New** Skills chip row:

```php
                <?php $skills_list = json_decode($c['skills'] ?? '[]', true) ?: []; ?>
                <?php if (!empty($skills_list)): ?>
                    <div style="background:var(--surf); border:1px solid var(--bdr); border-radius:10px; padding:14px; margin-top:18px;">
                        <div style="font-size:10px; color:var(--mut); text-transform:uppercase; letter-spacing:0.8px; margin-bottom:10px;">🧩 Skills</div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            <?php foreach ($skills_list as $skill): ?>
                                <span class="chip" style="color:var(--txt); border-color:var(--bdr); background:var(--dim);"><?= htmlspecialchars($skill) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
```

6. "🚀 Why We Should Hire Them" pitch panel (existing block, moved to the
   end, unmodified content).

## Testing

Manual, in-browser, using the existing candidate/employer accounts and
data already in the database (no new test data needed — this is a
read-only display change):

1. Open a candidate with multiple other applicants on the same job —
   confirm the Rank badge matches manually counting how many candidates
   on that job have a higher `overall_score`.
2. Confirm a `Strong Hire`/`Hire` candidate shows a green "✅ Pass" badge,
   and a `Maybe`/`Do Not Hire` candidate shows a red "❌ Fail" badge.
3. Confirm the job title now appears under the candidate's name in the
   header, and the badge row no longer repeats it.
4. Confirm the score circle renders as a partial colored ring (not a full
   solid border) proportional to the score, with the number legible in
   the center.
5. Confirm Skills chips render for a candidate whose `skills` column has
   data, and that the whole block is hidden (not an empty box) for a
   candidate with an empty/missing skills array.
6. Scroll through and confirm the new order: bars → strengths/gaps →
   education/experience → skills → pitch panel — and that nothing from
   the other three tabs changed.
