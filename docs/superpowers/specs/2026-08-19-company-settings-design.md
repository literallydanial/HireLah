# Company Settings — Design

Date: 2026-08-19

## Problem

Employers currently have no way to record a company identity distinct from
their personal account name. The `users.name` field doubles as both the
login/display name and the "company name" shown to candidates, which is
wrong for accounts where the registered person differs from the company
(e.g. an HR staffer signing up on behalf of "Acme Sdn Bhd").

The employer wants a "Company Settings" panel (mockup supplied) on their
existing Settings page (`profile.php`, linked from the employer dashboard
nav as "⚙️ Settings") to capture: Company Name, Website, Address, and a
Company Logo — used on candidate-facing surfaces (job listings, dashboards,
notifications, interview emails).

## Data model

Add four nullable columns to `users` (mirrors the existing `profile_picture`
column pattern — one row per account, no new table needed since each
employer account maps to exactly one company in this app):

- `company_name` VARCHAR(255) DEFAULT NULL
- `company_website` VARCHAR(255) DEFAULT NULL
- `company_address` TEXT DEFAULT NULL
- `company_logo` VARCHAR(255) DEFAULT NULL — stored relative path, same
  convention as `profile_picture`

Migration file: `schema_update8.sql`, following the existing
`schema_update.sql` … `schema_update7.sql` numbering convention. Applied
directly against the local XAMPP MySQL database.

## UI

New panel "🏢 Company Settings" added to `profile.php`, inside the
`role === 'employer'` branch (sibling to the existing profile-info and
password panels, and separate from the admin-only "Anthropic AI & PHPMailer
SMTP Settings" panel). Layout matches the supplied mockup:

- Row: Company Name | Website (2-column grid)
- Address (full-width textarea)
- Company Logo: thumbnail preview + "Upload logo" file input, helper text
  "PNG, JPG, SVG, WebP or GIF · up to 2MB"
- Save changes button

Subtitle: "Used on candidate-facing emails and exported reports."

## Backend

Two new POST actions in `profile.php`, following the same
validate → persist → session toast → redirect pattern as the existing
`upload_picture` / `upload_resume` handlers:

- `update_company_info` — trims and saves `company_name`, `company_website`,
  `company_address` via `UPDATE users ... WHERE id = ?`.
- `upload_company_logo` — validates extension (png/jpg/jpeg/svg/webp/gif)
  and size (≤ 2MB), saves to `uploads/company_logos/`, updates
  `company_logo`. Rendered via `<img src="...">` (safe for uploaded SVGs —
  the browser does not execute embedded scripts in an `<img>`-loaded SVG).

Both actions are only reachable by employers (existing `require_login()` +
role branch already scopes the panel; handlers additionally no-op with a
session error if `$user['role'] !== 'employer'`, matching the defense-in-depth
pattern just added for the admin-only settings actions).

## Site-wide company-name wiring

Candidates currently see the employer's personal `users.name` labeled as
the employer/company in several places, via `u.name as employer_name`:

- `jobs.php` — public job listing query
- `candidate_dashboard.php` — applications list query, interview-confirm
  email trigger query
- `confirm_interview.php` — interview-confirm query
- `notifications_helper.php` — two notification queries
- `admin_dashboard.php` — two admin listing queries
- `profile.php` — questionnaire query
- `candidate.php` — interview-proposal trigger uses
  `$_SESSION['user_name']` directly (not SQL)

All of these change to prefer the new company name, falling back to the
account name when blank:

```sql
COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name
```

`candidate.php`'s trigger changes from `$_SESSION['user_name']` to a lookup
of the logged-in employer's `company_name` (fallback to
`$_SESSION['user_name']`) before calling `send_interview_proposal_email`.

No changes to `mailer.php` itself — it already accepts `$employer_name` as
a plain parameter; only the callers change what they pass in.

## Testing

Manual, in-browser (no automated test suite exists in this project):

1. Log in as an employer, open Settings, fill in Company Settings, save —
   confirm toast and persisted values on reload.
2. Upload a logo (PNG and SVG) — confirm thumbnail updates and file lands
   in `uploads/company_logos/`.
3. Post a job, view it as a candidate on `jobs.php` and the candidate
   dashboard — confirm the company name (not the employer's personal name)
   is shown.
4. Propose an interview — confirm the email (via existing test-email flow
   or logs) shows the company name.
5. Leave company name blank on a second employer account — confirm
   fallback to their personal account name still works everywhere.
