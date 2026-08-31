# BotFilter UI/UX Overhaul — Design & Requirements Spec

Status: approved for design/implementation
Author: Claude (session with Max Soukhomlinov), 2026-08-31

## 1. Background

IntellectITBotFilterBundle (v1.5.4) has a strong, iteratively-hardened backend — retroactive
recount/prune commands, IP/ASN enrichment, honeypot capture, IDOR-safe ownership clamping,
7 test files / 49 assertions, a maintained CHANGELOG, and a filed upstream Mautic issue
(mautic/mautic#16263). Its UI, however, predates any deliberate visual design pass: the admin
list/drill-down pages are raw Bootstrap tables with inline styles, there is no dedicated
`Assets/css`/`Assets/js`, no shared design tokens, and no pagination/sort/search on either
list-shaped view.

By contrast, IntellectITActivityCalendarBundle (v1.0.2) went through a deliberate three-stage
pipeline before implementation: a functional spec, a Claude Design visual pass (a committed
`.dc.html` canvas plus a fully worked `docs/ui-spec.md` covering colour tokens, spacing/type
scale, and per-component states), then implementation aligned to that design. That pipeline is
what produced its UI quality bar, and this spec exists to run BotFilter through the same
pipeline.

## 2. Goal

Bring BotFilter's UI/UX to the same quality bar as ActivityCalendar, across all four of its
user-facing surfaces, without regressing its backend robustness.

## 3. Scope — in

Four surfaces, all in scope for this initiative:

1. **Admin list page** (`/s/botfilter`, `BotFilterController::indexAction`,
   `Resources/views/BotFilter/list.html.twig`) — affected-contacts list.
2. **Contact drill-down page** (`/s/botfilter/contact/{leadId}`,
   `BotFilterController::contactAction`, `Resources/views/BotFilter/contact.html.twig`) —
   per-contact bot-hit detail.
3. **Dashboard widgets** (4 total: 2 number widgets rendering
   `Resources/views/Widgets/number.html.twig`, 2 table widgets rendering core Mautic table
   widget templates) — summary tiles on the main Mautic dashboard.
4. **Settings/config form** — the plugin's integration config screen (published/unpublished
   master switch, honeypot toggle, allowlist, thresholds), defined in
   `Integration/IntellectITBotFilterIntegration.php` and rendered by Mautic core's generic
   integration-config UI. Customization surface here is narrower than the other three (field
   grouping, labels, help text, section layout) since the chrome itself is core-rendered.

**Functionality additions**, not just visual polish, are in scope for surfaces 1 and 2:

- Pagination (Mautic's standard list pagination, matching core list views)
- Column sort
- Search/filter (at minimum: search by contact name/email; filter drill-down by reason/date
  range if the underlying query supports it cheaply)

**i18n cleanup** is in scope wherever these templates/controllers are touched: hardcoded
strings in `list.html.twig`, `contact.html.twig`, `number.html.twig`, and any new
markup/labels move to `Translations/en_US/messages.ini` translation keys, matching the pattern
already used for menu/widget titles.

## 4. Scope — out

- No changes to backend detection logic (`VelocityBotRatioHelper`), CLI commands, honeypot
  capture, IP/ASN enrichment, or the `botfilter_blocked_hits`/enrichment-cache schema.
- No changes to permissions/access model (`lead:leads:viewown`/`viewother`, ownership
  clamping) beyond what's needed to keep the IDOR-safe behaviour intact through the redesign.
- Full i18n audit of *other* plugins (ActivityCalendar has the same gap) — tracked as a
  separate future concern, not part of this initiative.
- Any third-party JS/CSS framework beyond what Mautic core already ships (ActivityCalendar
  chose custom Twig+CSS over a library; default to the same unless a surface genuinely needs
  more).

## 5. Process pipeline

Mirrors ActivityCalendar exactly:

1. **This spec** — functional/design-requirements document (this file).
2. **Claude Design pass** — one canvas (`.dc.html`) and one `docs/ui-spec.md` covering all
   four surfaces as a single coherent design language (shared tokens, shared table/badge/
   number-tile components), the same way ActivityCalendar's `ui-spec.md` specified colour
   system, type scale, spacing, and per-component states before any Twig/CSS was written.
3. **Implementation**, one task per surface, each landing on the shared branch
   `feat/botfilter-ui-overhaul`:
   - Admin list page
   - Contact drill-down page
   - Dashboard widgets
   - Settings/config form
4. **QA & verification** — per top-level `CLAUDE.md` UI rule: start the dev stack, exercise
   the golden path and edge cases (empty states, large hit counts, pagination boundaries) for
   each surface in a real browser before claiming done. Then one PR for the whole branch.

## 6. Acceptance criteria

- All four surfaces visually consistent with each other (shared design tokens: colour,
  spacing, type scale, component styles for tables/badges/number tiles).
- List and drill-down pages support pagination, column sort, and search, matching Mautic's
  standard list-view UX (a user familiar with core Mautic list views should recognize the
  interaction pattern).
- No hardcoded UI strings remain in touched templates; all route through
  `Translations/en_US/messages.ini`.
- No regression to IDOR-safe ownership clamping, dashboard widget data accuracy, or existing
  test suite (7 files / 49 assertions must still pass).
- Settings form remains fully functional; any relabeling/regrouping preserves existing field
  keys (no config migration needed).
- Verified working end-to-end in a real browser against the live dev stack before the PR is
  opened.

## 7. Tracking

Filed as a GitHub epic + 7 linked task issues on `msoukhomlinov/IntellectITBotFilterBundle`
(repo had zero prior issues). Each task issue carries its own acceptance-criteria checklist
and a "Progress log" section updated with dated comments as work spans sessions. Single
branch `feat/botfilter-ui-overhaul`, one PR at the end closing all task issues.
