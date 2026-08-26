# Questionnaire Builder Redesign — Design

Date: 2026-08-19

## Problem

The employer supplied a mockup of a "New Questionnaire" modal (Basic Info
section with Title / Related Job Title / Status / Description, plus a
Questions section where each question has a Type dropdown — Short Text,
Long Text, Multiple Choice, Checkbox, Rating Scale, Yes/No, Number — and a
Required checkbox) and asked for the questionnaire builder to be adjusted
to match it.

Today's implementation (`questionnaire.php`) is much simpler: a side panel
(not a modal) with just a Title, a job dropdown, and a flat list of
plain-text questions (`questions_json` is a JSON array of strings). Every
question is answered as free text and is always required
(`answer_questionnaire.php` renders a `<textarea>` per question,
`required` attribute always on). There is no per-question type, no
required flag, no description, and no status.

## Scope decisions (from clarifying questions)

- **Full end-to-end**: question Type is functionally meaningful — candidates
  see type-appropriate inputs on `answer_questionnaire.php`, not just
  plain text everywhere.
- **Related Job Title stays a real dropdown** of the employer's own posted
  jobs (relabeled to match the mockup's field name/position), preserving
  the existing behavior where `job_id` lets "Send Questions" on a
  candidate auto-match the right questionnaire to that candidate's job
  (`candidate.php`'s `$saved_questionnaires` query:
  `WHERE employer_id = ? OR job_id = ?`).
- **Status = Active / Draft.** Active questionnaires behave as today.
  Draft questionnaires remain visible/editable in the employer's own
  Templates Library but are excluded from the "Select Saved Questionnaire
  Template" picker in `candidate.php`'s Send Questions modal.
- **Choice options UI**: when a question's Type is Multiple Choice or
  Checkbox, an inline "Options" list appears under that question with a
  text input per option, a "+ Add Option" button, and a ✕ remove button
  per option — mirroring the existing "+ Add Question" pattern.
- **Rating Scale**: fixed 1–5 (no configurable range — YAGNI; can be
  extended later if needed).
- **Number**: plain HTML number input, no min/max constraints.
- **Yes/No**: rendered as two radio buttons ("Yes" / "No").

## Data model

Migration `schema_update9.sql`:

```sql
ALTER TABLE questionnaires ADD COLUMN description TEXT DEFAULT NULL;
ALTER TABLE questionnaires ADD COLUMN status VARCHAR(20) DEFAULT 'Active';
```

`questions_json` moves from `["question text", ...]` to an array of
objects:

```json
[
  {
    "text": "What is your notice period?",
    "type": "short_text",
    "required": true
  },
  {
    "text": "Which environments have you deployed to?",
    "type": "checkbox",
    "required": false,
    "options": ["AWS", "Azure", "GCP", "On-prem"]
  }
]
```

Type slugs (matching the mockup's dropdown order): `short_text`,
`long_text`, `multiple_choice`, `checkbox`, `rating_scale`, `yes_no`,
`number`. `options` is present only for `multiple_choice` and `checkbox`.

## Backward compatibility

No data migration script for existing rows. Every place that reads a
question from `questions_json` treats a plain string item as shorthand for
`{text: <string>, type: 'long_text', required: true}` — i.e. "old-style
question, rendered as a free-text box, always required," which matches
current behavior exactly. This covers:

- Existing saved templates created before this change.
- The **unchanged** "quick send custom questions" flow in `candidate.php`'s
  Send Questions modal (Tab 2: "✍️ Make New Questions") — it keeps writing
  plain strings to `questions_json` and is out of scope for this redesign
  (it's a separate, simpler ad-hoc flow, not the templated builder the
  mockup targets). Because of the backward-compatible read path, these
  ad-hoc questionnaires keep working exactly as they do today.

A template naturally upgrades to the new object format the next time it's
opened and re-saved through the redesigned builder.

## Builder UI (`questionnaire.php`)

The "Create / Edit Questionnaire" panel becomes an actual modal (dark
overlay, centered card, ✕ close button in the header) — matching the
mockup's structure and layout — instead of today's side-by-side panel.
The Saved Templates Library stays as its own column; "+ Create New
Questionnaire" and each template's "✏️ Edit Template" link open the modal
instead of scrolling to an inline form.

**Basic Info section:**
- Title (text input, required) — unchanged.
- Related Job Title (select dropdown of the employer's jobs, same options
  as today's "Associated Job Posting" dropdown, relabeled).
- Status (select: Active / Draft, default Active).
- Description / Instructions (textarea, optional) — new; shown to
  candidates at the top of `answer_questionnaire.php` when non-empty.

**Questions section:**
- Header "Questions (N)" with a live count and a "+ Add Question" button,
  matching the mockup.
- Each question row: question text input, a Type `<select>`, a Required
  checkbox, and a ✕ remove button.
- When Type is Multiple Choice or Checkbox, an Options sub-list appears
  (as described above).
- Changing a question's Type via JS shows/hides its Options sub-list
  without a page reload.

**Footer:** Cancel (closes modal) / Save (submits the form) — matching the
mockup's Cancel / Create buttons.

The existing add/remove-question JS (`addQuestion`, `removeQuestion`,
`reindexQuestions`) is extended to also manage each question's Type
select and Options sub-list, and a new `addOption`/`removeOption` pair
handles the Options sub-list.

## Save handler (`questionnaire.php`)

`save_questionnaire` POST handling changes to:
- Read `title`, `job_id`, `status`, `description` as before/new plain
  fields.
- Read parallel arrays `questions[]`, `question_types[]`,
  `question_required[]`, and `question_options[]` (the latter a
  JSON-encoded array of option-arrays, one per question, submitted via a
  hidden input populated by JS right before submit — avoids fragile
  nested `name="options[][]"` indexing across dynamically added/removed
  questions).
- Build the `questions_json` array of objects described above, skipping
  blank question text rows (same as today), and only including `options`
  for `multiple_choice`/`checkbox` types (empty options filtered out).
- `UPDATE`/`INSERT` includes the new `description` and `status` columns.

## Status filtering (`candidate.php`)

The `$saved_questionnaires` query (used to populate the "Select Saved
Questionnaire Template" dropdown in the Send Questions modal) gets an
added condition so Draft templates don't show up there:

```sql
WHERE (employer_id = ? OR job_id = ?) AND (status IS NULL OR status != 'Draft')
```

(`status IS NULL` is included defensively so pre-migration rows stay
visible in the picker regardless of whether MySQL backfills them to
`'Active'` or leaves them `NULL` when the column is added — the condition
is correct either way, so this doesn't need to be verified up front.)

The Templates Library on `questionnaire.php` itself shows all statuses
(Draft included) with a small status chip on each card, so employers can
still see/edit their drafts.

## Candidate-facing rendering (`answer_questionnaire.php`)

For each question, resolve `text`/`type`/`required`/`options` (with the
string-shorthand fallback above), then render:

| Type | Input | Answer storage |
|---|---|---|
| `short_text` | `<input type="text">` | string |
| `long_text` | `<textarea>` | string |
| `multiple_choice` | radio group over `options` | string (selected option) |
| `checkbox` | checkbox group over `options` | JSON array of selected strings |
| `rating_scale` | 5 radio buttons styled as stars, values 1–5 | string `"1"`–`"5"` |
| `yes_no` | two radio buttons, "Yes"/"No" | string `"Yes"`/`"No"` |
| `number` | `<input type="number">` | string |

`required` only adds the HTML `required` attribute (and, for radio/checkbox
groups, a small JS check before submit, since HTML `required` doesn't work
reliably across a group of same-named radios/checkboxes with none
pre-checked) when true; otherwise the field is optional and can be left
blank.

Checkbox answers are submitted as `answers[<idx>][]` and stored as a JSON
array under that index in `answers_json` (which already stores one JSON
blob per request, so no schema change needed there — the per-question
value just becomes either a string or an array depending on type).

The "Your Submitted Answers" read-only view (shown after submission, same
file) renders each answer back appropriately: arrays are shown as a
comma-separated or bulleted list, everything else as plain text — same
`text`/`type` resolution as the form view.

## Other touch points needing small read-path updates

These don't change behavior, only how they read a question item, since
question items can now be either a plain string (legacy/ad-hoc) or an
object (new builder):

- `questionnaire.php` — the Templates Library preview list
  (`foreach($q_list as $idx => $q_item)`) reads `$q_item['text'] ?? $q_item`.
- `candidate.php` — the "Screening Questionnaires" submitted-answers view
  (`foreach($questions_list as $q_idx => $q_text)`) reads
  `$q['text'] ?? $q` for the question, and renders the answer as a
  comma-joined list when it's a PHP array (checkbox answers) or plain text
  otherwise.
- `candidate.php` — the `updateTemplatePreview()` JS function reads
  `q.text ?? q` instead of assuming `q` is a string.
- `mailer.php` — `send_questionnaire_email`'s question-list loop reads
  `is_array($q_text) ? ($q_text['text'] ?? '') : $q_text` before
  `htmlspecialchars()`.

## Testing

Manual, in-browser (no automated test suite in this project), using fresh
test employer/candidate accounts (cleaned up afterward), same approach as
the Company Settings verification:

1. Open "+ Create New Questionnaire" — confirm it opens as a modal matching
   the mockup's layout and fields.
2. Build a questionnaire with at least one of each question type,
   including a Multiple Choice and a Checkbox question with 2–3 options
   each, one Required and one not required. Save it with Status = Active.
3. Save a second questionnaire with Status = Draft.
4. Confirm the Templates Library shows both, with a status chip.
5. From a candidate's profile page, open "Send Questions to Candidate" →
   confirm only the Active questionnaire appears in the saved-template
   dropdown, not the Draft one.
6. Send the Active questionnaire, open the candidate link
   (`answer_questionnaire.php`) as the candidate — confirm each question
   renders with the right input type, required questions block submission
   when blank, non-required ones don't.
7. Submit answers, including selecting multiple checkbox options — confirm
   the "Your Submitted Answers" view renders correctly, and the employer's
   candidate-profile "Screening Questionnaires" tab shows the same answers
   correctly (including the checkbox list).
8. Confirm a legacy-format questionnaire (plain string questions) — e.g.
   one sent via the untouched "quick send custom questions" flow — still
   sends and answers correctly as free-text/required, unchanged.
