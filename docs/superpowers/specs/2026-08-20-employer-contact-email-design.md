# Employer Candidate-Contact Email — Design

Date: 2026-08-20

## Problem

Every candidate-facing email (interview proposal, questionnaire dispatch) is
sent with no Reply-To header at all — `mailer.php` only calls
`$mail->setFrom(...)` using the single global SMTP `from` address from
`config.json`. A candidate hitting "reply" has nowhere useful to go, and
there's no way for the app to reflect "this specific employer" in the reply
path.

Symmetrically, when a candidate confirms an interview, the notification
email is sent **to** the employer's account login email (`users.email`,
via `u.email as employer_email` in `candidate_dashboard.php` and
`confirm_interview.php`) — with no way for the employer to route that to a
different inbox (e.g. a shared HR address) if they'd rather not use their
personal login email for candidate correspondence.

Both gaps are solved by the same new setting: a per-employer, optional
"Contact Email" that governs all candidate-related mail traffic — both
directions — falling back to the account login email when unset.

## Scope decisions (from clarifying questions)

- **Optional, with fallback.** Contact Email defaults to unset; when unset,
  everything behaves exactly as today (Reply-To ends up pointing at the
  login email, notifications go to the login email). No forced setup step.
- **Both directions.** Once set, Contact Email is used both as the
  Reply-To on candidate-facing emails AND as the destination for the
  "candidate confirmed interview" notification — one address governs all
  candidate-related mail for that employer.
- Only the two candidate-facing send functions in `mailer.php`
  (`send_interview_proposal_email`, `send_questionnaire_email`) need a
  Reply-To added — the OTP/password-reset emails (`send_otp_email`,
  `send_password_reset_email`) are user-account emails, not
  candidate-correspondence, and are out of scope.

## Data model

Migration `schema_update10.sql`:

```sql
ALTER TABLE users ADD COLUMN contact_email VARCHAR(255) DEFAULT NULL;
```

## Shared helper

New `employer_helpers.php`, following the same one-function-per-file
pattern as `questionnaire_helpers.php`:

```php
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

This both supplies the new Reply-To lookups and replaces the ad-hoc
company-name-only lookup already sitting in `candidate.php`'s
`schedule_interview` handler (added for the Company Settings feature),
which duplicates half of what this helper now does.

## UI

A new full-width field in the existing "🏢 Company Settings" panel on
`profile.php`, placed directly below the Company Name/Website grid row and
above the Address field: "Candidate Contact Email (Optional)",
`type="email"`, placeholder `hr@acme.com — leave blank to use your account
email`.

## Backend

`update_company_info` in `profile.php` (already handles
`company_name`/`company_website`/`company_address`) additionally reads
`contact_email`, trims it, and — only if non-empty — validates it with
`filter_var($contact_email, FILTER_VALIDATE_EMAIL)`. An empty string is
always accepted (clears the field back to "unset"). An invalid non-empty
value sets `$_SESSION['error']` and re-shows the form without saving,
matching the existing validation-failure pattern elsewhere in this file.

## Mailer changes

`send_interview_proposal_email()` and `send_questionnaire_email()` in
`mailer.php` each gain a new trailing parameter, `$reply_to_email = ''`.
When non-empty, they call `$mail->addReplyTo($reply_to_email, $employer_name)`
right after `$mail->setFrom(...)`. `$employer_name` already exists as a
parameter of `send_interview_proposal_email`; `send_questionnaire_email`
does not currently take one, so it gains `$employer_name = ''` too (used
only for the Reply-To display name — the email body itself is unaffected,
staying in scope with the design's focus on the reply path, not rewording
the email content).

Call sites in `candidate.php` (`schedule_interview` and
`send_questionnaire` handlers) call `get_employer_contact($pdo,
$_SESSION['user_id'])` once and pass `['name']`/`['email']` through to the
respective mailer function.

## Notification routing

`candidate_dashboard.php` and `confirm_interview.php`'s queries change:

```sql
COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, u.email as employer_email
```

to:

```sql
COALESCE(NULLIF(u.company_name, ''), u.name) as employer_name, COALESCE(NULLIF(u.contact_email, ''), u.email) as employer_email
```

No other change needed in either file — `send_interview_confirmed_email`
already just takes whatever email string it's handed as the recipient.

## Testing

Manual, in-browser/curl, using fresh test employer/candidate accounts
(cleaned up afterward), same approach as the previous two features this
session:

1. Leave Contact Email unset — propose an interview, confirm the SMTP
   test/log shows a Reply-To header set to the employer's account login
   email (`get_employer_contact()`'s `COALESCE` always resolves to a real
   address here, since `users.email` is a required column — so Reply-To is
   always present, never blank, even before Contact Email is ever set).
2. Set an invalid string (`not-an-email`) as Contact Email — confirm the
   save is rejected with an error and the field is unchanged.
3. Set a valid Contact Email distinct from the login email — propose an
   interview and send a questionnaire — confirm both emails' Reply-To
   header is the new Contact Email with the company display name.
4. As the candidate, confirm the interview — confirm the "interview
   confirmed" notification is sent to the Contact Email, not the login
   email.
5. Clear Contact Email back to blank — repeat interview confirm — confirm
   the notification now goes back to the login email (fallback still
   works after having been set once).
