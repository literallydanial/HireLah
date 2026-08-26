# Glassmorphism Visual Redesign

## Purpose

Convert the app's visual design system to glassmorphism (frosted-glass panels: translucency + blur + subtle highlight borders) across the entire site, unifying `admin_dashboard.php`'s separate "Vibrant Modern SaaS" skin into the same look. Must keep working with the existing light/dark theme toggle.

## Context

Nearly every panel-like surface in the app (`.panel`, `.table`, `.stat-box`, `.modal`, `.chip`, `header`, `.notif-dropdown`) renders through CSS custom properties (`--surf`, `--card`, `--bdr`, `--shadow-*`) defined once in `style.css` and overridden under `[data-theme="dark"]`. The background is already a purple radial-gradient (`--bg-gradient`) with a faint logo watermark, and `header` already uses `backdrop-filter: blur(10px)`. This gives a natural foundation for glass — panels floating over a colorful blurred background is the effect's core visual idea.

`admin_dashboard.php` has its own embedded `<style>` block from an earlier redesign, using a separate `--grad-admin-purple` solid-gradient look for its stat cards and health-check tiles. It does not currently use the shared translucent/blur treatment.

## Decisions (confirmed with user)

- **Scope**: unify `admin_dashboard.php`'s custom cards into the same glass system — no separate "vibrant" skin left behind.
- **Intensity**: subtle. Panels stay mostly opaque and readable; blur is gentle, not a dramatic "liquid glass" look.
- **Buttons**: `.btn-primary` / `.btn-secondary` stay solid-gradient, untouched. Only panel/surface-level elements become glass. This keeps CTAs high-contrast against the new translucent panels.
- **Approach**: token-level change in `style.css`, not a new `.glass` utility class applied in markup. Because shared classes already drive nearly every surface across all ~26 pages, changing the underlying tokens and shared selectors cascades everywhere without touching page markup. Adding a class to every panel element across every page was considered and rejected — same visual result, far larger diff, higher risk of missed spots.

## Design

### 1. Token changes in `style.css` `:root` (light theme)

- `--surf`: solid `#FFFFFF` → translucent white, e.g. `rgba(255, 255, 255, 0.55)`.
- `--card`: solid `#FFFFFF` → translucent white, e.g. `rgba(255, 255, 255, 0.6)`.
- New tokens:
  - `--glass-blur: blur(16px) saturate(140%)`
  - `--glass-border: 1px solid rgba(255, 255, 255, 0.35)` (inner highlight edge, layered visually with existing `--bdr`)
- `--shadow-md` / `--shadow-lg` softened slightly (lower opacity) so shadows read as "floating glass" rather than the current solid-card drop shadow.

### 2. Token changes under `[data-theme="dark"]`

- `--surf`: solid `#131026` → `rgba(19, 16, 38, 0.55)`.
- `--card`: solid `#1C1738` → `rgba(28, 23, 56, 0.5)`.
- `--glass-border` override: `1px solid rgba(255, 255, 255, 0.08)` (dimmer highlight, appropriate for dark backgrounds).
- Shadows keep dark theme's existing higher-opacity black shadows (already tuned for the dark background), just slightly softened for consistency with the lighter glass panels.

### 3. Shared component rules

Apply `backdrop-filter: var(--glass-blur)` (+ `-webkit-backdrop-filter`) and the new `--glass-border` to:

- `.panel`
- `.table`
- `.modal`
- `.stat-box`
- `header`
- `.notif-dropdown`

`.chip` and its variants (`.chip-shortlisted`, `.chip-review`, `.chip-rejected`) keep their existing tinted-translucent backgrounds (already glass-like) but pick up `backdrop-filter: blur(8px)` (lighter than panels, since chips are small) for visual consistency.

### 4. `admin_dashboard.php` embedded styles

Locate the embedded `<style>` block's card/stat-tile/health-indicator rules currently using `--grad-admin-purple` as a solid background fill. Convert those specific rules to use the same translucent background + `var(--glass-blur)` + `--glass-border` treatment as `.panel`/`.stat-box`, so the admin dashboard visually matches the rest of the site. The `--grad-admin-purple` token itself is kept for any accent/text-gradient uses (e.g. gradient text), only the solid card-fill usages change.

### 5. Out of scope

- `.btn-primary` / `.btn-secondary` — stay solid, no change.
- Any markup changes — this is a CSS-only change.
- Browsers without `backdrop-filter` support degrade to the translucent background color with no blur — acceptable for this internal tool, no fallback logic needed.

## Testing / Verification

Manual visual check via the browser preview tool, covering:
- A candidate-facing page (e.g. `jobs.php` or `candidate_dashboard.php`)
- Employer dashboard (`employer_dashboard.php`)
- Admin dashboard (`admin_dashboard.php`)
- Both light and dark theme (via the existing theme toggle)

No automated tests exist in this codebase (PHP, no build step); verification is `php -l` on any touched `.php` file (only `admin_dashboard.php`) plus the manual visual pass above.
