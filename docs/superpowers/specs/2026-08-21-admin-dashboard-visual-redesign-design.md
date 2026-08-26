# Admin Dashboard Visual Redesign — Design

Date: 2026-08-21

## Problem

The employer asked for `admin_dashboard.php` to look "sleek and modern." Through
the visual companion, the specific gaps were narrowed down to: too
dense/cluttered, dated table/list styling, and flat/low-polish visuals (flat
cards, plain badges, underline-only tabs).

## Scope decisions (from clarifying questions and visual companion)

- **Direction chosen: "Vibrant Modern SaaS."** Gradient-filled stat tiles and
  status badges, glass-style panels, bolder color energy — replacing the
  current flat `var(--surf)` cards with solid 1px borders.
- **Adaptive, not permanently dark.** The gradient/glass treatment must shift
  tone with the site's existing 🌙 light/dark toggle (`[data-theme="dark"]` in
  `style.css`), the same way every other page already does — not a
  permanently-dark "control room" look that ignores the toggle.
- **Styling only — no functional change.** Same data, same columns, same
  search/filter/action buttons (`filterUsersTable()`, status forms, delete
  forms, Add User modal, tab switching JS). This is a CSS/markup-only pass
  over existing content.
- **Full-page scope**: stat tiles, the two health cards (DB Health, Claude AI
  Engine — including the API key indicator card added earlier this session),
  the tab bar, and both tables (Registered Accounts, Job Postings Moderation).

## Visual language

New gradient/shadow tokens, one pair (light/dark) per semantic color already
in use on this page, defined as CSS custom properties scoped to
`admin_dashboard.php`'s own `<style>` block (not global `style.css`, since
this treatment is specific to this page for now):

```css
:root {
    --grad-purple: linear-gradient(135deg, #8B5CF6, #6D28D9);
    --grad-green:  linear-gradient(135deg, #10B981, #047857);
    --grad-amber:  linear-gradient(135deg, #F59E0B, #B45309);
    --grad-red:    linear-gradient(135deg, #F43F5E, #BE123C);
    --glow-purple: 0 8px 20px rgba(139, 92, 246, 0.25);
    --glow-green:  0 8px 20px rgba(16, 185, 129, 0.25);
}
[data-theme="dark"] {
    --grad-purple: linear-gradient(135deg, #7C3AED, #4C1D95);
    --grad-green:  linear-gradient(135deg, #059669, #064E3B);
    --grad-amber:  linear-gradient(135deg, #D97706, #78350F);
    --grad-red:    linear-gradient(135deg, #E11D48, #881337);
    --glow-purple: 0 8px 24px rgba(124, 58, 237, 0.4);
    --glow-green:  0 8px 24px rgba(5, 150, 105, 0.35);
}
```

(Mirrors the existing `:root` / `[data-theme="dark"]` split already used in
`style.css` for `--surf`, `--card`, etc. — same mechanism, just page-scoped
tokens for the new gradients.)

## Component changes

**Stat tiles** (`.stats-summary-grid .stat-box`, 4 tiles): background becomes
the matching gradient variable instead of flat `var(--card)`; icon badge
removed in favor of a light icon directly on the gradient; number and label
in white/near-white for contrast; `box-shadow` uses the matching `--glow-*`
token.

**Health cards** (DB Health, Claude AI Engine): the outer card keeps its
existing `var(--surf)` panel (these hold dense diagnostic text, which stays
readable on a neutral panel), but the status badge (`.badge-pill`) and the
key headline metric (Connection Latency / Live Key Check) get the gradient
treatment as a small inline chip, replacing the current plain color-only
text. The two cards' internal grid becomes slightly tighter to read faster
at a glance — no information removed.

**Tabs** (`.tab-btn`): the active tab gets a filled rounded-pill background
using `--grad-purple` (matching the currently-active accent color already
used for `.tab-btn.active`'s underline) instead of just a colored bottom
border; inactive tabs unchanged.

**Tables** (`.admin-table` / `.admin-tr` rows, both Registered Accounts and
Job Postings Moderation): each row gets a 3px left accent bar and a subtle
background hover-highlight. Accent color and chip gradient are driven by the
row's *existing* chip class — no new status logic is introduced:

- Registered Accounts: role chip classes already assigned in the PHP —
  `chip-rejected` (admin) → red/rose gradient, `chip-review` (employer) →
  amber gradient, `chip-shortlisted` (candidate) → green gradient.
- Job Postings Moderation: every row currently uses `chip-shortlisted`
  regardless of the job's actual `status` value (a pre-existing quirk, out
  of scope to fix here) — so every job row gets the same green accent/chip
  gradient, consistent with what the chip already visually implies today.

Chips themselves get a soft gradient-tinted background (using the same
`--grad-*` tokens) instead of flat single-color text. The user avatar-initial
circle (already present) gets the matching gradient background instead of
flat purple. Row height, columns, and all existing action buttons/forms are
unchanged.

## Testing

Manual, in-browser, using the existing real admin account (no test data
needed — this is a display-only change):

1. Load the dashboard in light mode — confirm stat tiles, health card badges,
   active tab, and table rows all show the lighter gradient variant with
   good text contrast.
2. Toggle to dark mode (🌙) — confirm every one of those same elements swaps
   to the darker gradient variant automatically, matching how the rest of
   the site's dark mode already behaves.
3. Confirm all existing functionality still works unchanged: user search/
   filter, status update forms, delete forms, Add User modal, tab switching,
   and the API key health indicator's colored states (Active/Not Configured/
   etc. from the earlier feature) still show correctly under the new styling.
4. Resize to a narrow viewport and confirm the existing `@media (max-width:
   1024px)` responsive rules still collapse the grids sensibly with the new
   styling applied.
