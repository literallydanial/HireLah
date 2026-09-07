# AI Resume Builder — Fresh Graduate Option

## Purpose

The AI Resume Builder (`resume_builder.php`) assumes every candidate has a job history: Chat mode's very first question is "tell me about your most recent job," and the no-API-key fallback summary always claims "hands-on experience" even when there is none. Add a "fresh graduate / little work experience" option so both the question flow and the generated content are appropriate for someone without a full-time job history.

## Context

- `resume_builder.php` has two input modes sharing one `<form id="builderForm">`: **Form mode** (`#formPanel`, plain fields) and **Chat mode** (`#chatPanel`, a scripted JS conversation that fills hidden inputs then calls `document.getElementById('builderForm').submit()`).
- The POST handler (lines ~36-89) builds `$input` (target_title, experience[], education[], skills, extra_notes) and calls `generate_resume_document($api_key, $input)` in `ai.php`, which tries the real Gemini API first and falls back to `generate_fallback_resume_document($input)` if no key/call failure.
- Chat mode's `chatStart()`/`chatAdvance()` (JS) drive a fixed step machine (`exp_company` → `exp_role` → `exp_duration` → `exp_notes` → `exp_more` → ... → `edu_degree` → ... → `skills` → submit). It has no path to skip straight past work experience.

## Design

### 1. Toggle

Add a checkbox to the Basics panel (form mode's top section, outside `#formPanel`/`#chatPanel` so it isn't disabled by mode-switching):

```html
<label class="f" style="display:flex; align-items:center; gap:6px; margin-top:8px;">
    <input type="checkbox" name="is_fresh_grad" id="freshGradCheck" style="width:auto;">
    I'm a fresh graduate / have little work experience
</label>
```

It submits as a normal POST field in both modes. Server-side: `$is_fresh_grad = isset($_POST['is_fresh_grad']);` added alongside the other `$input` fields in the POST handler, and included in `$input` passed to `generate_resume_document()`.

### 2. Chat mode flow change

At the point `chatStart()` runs (mode switched to chat), read `document.getElementById('freshGradCheck').checked` into a module-level `let chatIsFreshGrad = false;` (set inside `chatStart()`).

- If **not** fresh grad: unchanged — current experience-first flow.
- If fresh grad: `chatStart()` sends "Let's start with your education — what's your degree or qualification?" and sets `chatStep = 'edu_degree'` directly (skipping the `exp_*` steps entirely on entry). The existing `edu_degree` → `edu_school` → `edu_year` → `edu_more` loop runs unchanged. When `edu_more` answers "no", instead of jumping straight to `skills` as it does today, branch on `chatIsFreshGrad`:
  - fresh grad → ask "Do you have any internships, part-time jobs, or class/personal projects you'd like to include? Type them in, or type 'skip'." with `chatStep = 'exp_intro_skip'`.
  - not fresh grad → existing behavior (go straight to `skills`).
  
  `exp_intro_skip` step: if the answer (trimmed, case-insensitive) is empty or `"skip"`/`"no"`/`"none"`, go straight to the `skills` question. Otherwise, treat the answer as the first experience's company/role combined free text is too ambiguous — instead re-enter the existing `exp_company` step machine (`chatStep = 'exp_company'`) and re-show the message "Great — what would you call that (company/organization name)?" so the existing company→role→duration→notes→more loop runs exactly as today, just reached from a different entry point. This reuses all existing experience-collection code with a single new entry branch, no duplication.

Non-fresh-grad users see zero behavior change.

### 3. Form mode panel reorder + relabel

A `change` listener on `#freshGradCheck` (in the same `<script>` block) toggles:
- DOM order: when checked, `expContainer`'s parent panel (`.panel` wrapping "💼 Work Experience") is moved after the Education panel via `insertBefore`; unchecked reverses it. (Both panels are direct children of `#formPanel`; swap is a simple two-node reorder.)
- Panel title text: the "💼 Work Experience" panel-title div's text toggles to "💼 Internships / Part-Time Jobs / Projects (optional)" when checked, back to "💼 Work Experience" when unchecked. Implemented by giving that title div an `id="expPanelTitle"` and swapping `textContent`.

No field name/shape changes in form mode — same `exp_*[]`/`edu_*[]` inputs either way.

### 4. Backend generation (`ai.php`)

**`generate_resume_document($api_key, $input)`**: when `!empty($input['is_fresh_grad'])`, append one extra instruction paragraph to the existing prompt (after "OUTPUT REQUIREMENTS" block, before the closing), e.g.:

> "This candidate is a fresh graduate with little or no full-time work history. Write the summary around their education, academic projects, and coursework. Treat any listed internships/part-time work as supporting evidence of transferable skills, not a career history. Do not imply years of professional experience the candidate doesn't have. Emphasize potential, foundational skills, and eagerness to start their career."

No change to the JSON output schema — same `summary`/`experience`/`education`/`skills` shape.

**`generate_fallback_resume_document($input)`**: replace the hardcoded summary line. Current:

```php
'summary' => "Motivated " . $target_title . " with hands-on experience across " . (count($experience) > 0 ? "roles including " . ($experience[0]['role'] ?: 'recent positions') : "prior positions") . ". Automated draft (AI not configured) — consider refining this summary further.",
```

New: branch on `!empty($input['is_fresh_grad']) || empty($experience)`:
- fresh-grad-or-no-experience branch: `"Motivated recent graduate pursuing a role as " . $target_title . (!empty($education) ? ", with a foundation in " . ($education[0]['degree'] ?: 'their field of study') . (!empty($education[0]['school']) ? ' from ' . $education[0]['school'] : '') : "") . ". Automated draft (AI not configured) — consider refining this summary further."`
- else (existing behavior, has experience and not fresh grad): unchanged current line.

This also fixes the pre-existing awkward "prior positions" fallback phrasing for anyone who submits zero experience entries, independent of the new checkbox.

### Out of scope

- No new form fields/sections beyond the one checkbox (confirmed: wording/flow change only, not a new "Projects" data model).
- No change to `resume_ai_assist.php` (the per-bullet "Ask AI" helper) — it already only fires when the candidate has typed notes, so it's naturally unaffected.
- No change to the `resume_builds` table schema (already fixed separately — see the `run_migration_resume_builds.php` bug fix in this same session).

## Testing / Verification

No automated tests in this codebase. Verification:
- `php -l` on `resume_builder.php` and `ai.php` after changes.
- Manual walkthrough in the browser (candidate session): toggle checkbox in Form mode, confirm panel reorder + relabel happens live; toggle in Chat mode, confirm it opens with the education question and the internship question is skippable both ways (skip vs. providing an answer); generate one resume via the fallback path (no experience, fresh-grad checked) and confirm the summary text reads sensibly.
