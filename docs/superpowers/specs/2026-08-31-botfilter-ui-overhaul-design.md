# BotFilter UI/UX Overhaul — Design & Requirements Spec

Status: approved for design/implementation (amended after Opus QA pass, 2026-08-31)
Author: Claude (session with Max Soukhomlinov), 2026-08-31

## 1. Background

IntellectITBotFilterBundle (v1.5.4) has a strong, iteratively-hardened backend — retroactive
recount/prune commands, IP/ASN enrichment, honeypot capture, IDOR-safe ownership clamping,
7 standalone validation scripts / 60 checks (no PHPUnit — local `check()` helper, some run
against live tables inside rolled-back transactions), a maintained CHANGELOG, and a filed
upstream Mautic issue (mautic/mautic#16263). Its UI, however, predates any deliberate visual
design pass: the admin list/drill-down pages are raw Bootstrap tables with inline styles,
there is no dedicated `Assets/css`/`Assets/js`, no shared design tokens, and no
pagination/sort/search on either list-shaped view.

By contrast, IntellectITActivityCalendarBundle (v1.0.2) went through a deliberate three-stage
pipeline before implementation: a functional spec, a Claude Design visual pass (a committed
`.dc.html` canvas plus a fully worked `docs/ui-spec.md` covering colour tokens, spacing/type
scale, and per-component states), then implementation aligned to that design. That pipeline is
what produced its UI quality bar, and this spec exists to run BotFilter through the same
pipeline.

## 2. Goal

Bring BotFilter's UI/UX to the same quality bar as ActivityCalendar, across all four of its
user-facing surfaces, without regressing its backend robustness — and without regressing
security (ownership clamping, injection-safety) in the process.

## 3. Scope — in

Four surfaces, all in scope for this initiative:

1. **Admin list page** (`/s/botfilter`, `BotFilterController::indexAction`,
   `Resources/views/BotFilter/list.html.twig`) — affected-contacts list.
2. **Contact drill-down page** (`/s/botfilter/contact/{leadId}`,
   `BotFilterController::contactAction`, `Resources/views/BotFilter/contact.html.twig`) —
   per-contact bot-hit detail.
3. **Dashboard widgets** (4 total: 2 number widgets rendering
   `Resources/views/Widgets/number.html.twig`, 2 table widgets rendering core's
   `@MauticCore/Helper/table.html.twig`) — summary tiles on the main Mautic dashboard.
   Widget content occupies the inner card body only — core's
   `DashboardBundle/Resources/views/Widget/detail.html.twig` always draws the outer tile
   card, header, title and actions dropdown, and there is no hook to restyle that chrome.
   Design/implementation must work within it, not around it.
4. **Settings/config form** — the plugin's integration config screen (published/unpublished
   master switch, honeypot toggle, allowlist, thresholds), defined in
   `Integration/IntellectITBotFilterIntegration.php`. **Correction from the original draft of
   this spec**: the customization surface here is wider than "labels/help text only" — core's
   `AbstractIntegration` exposes `getFormTemplate()` (fully replaceable form template),
   `getFormTheme()` (custom Symfony form theme), and `getFormNotes($section)` for
   `authorization`/`features`/`feature_settings`/**`custom`** sections (styled alert blocks or
   a whole bespoke section), consumed by `PluginController::editAction` (`:296-338`). This
   form also **renders in a modal with tabs**, not a standalone page — design must account for
   that, not assume a full page.

**Functionality additions**, not just visual polish, are in scope for surfaces 1 and 2:

- Pagination (Mautic's standard list pagination — firm requirement, not conditional: prod
  already has a contact with 211 recorded hits, so this is needed on day one, not a "when it
  grows" concern)
- Column sort
- Search/filter (at minimum: search by contact name/email; filter drill-down by reason/date
  range if the underlying query supports it cheaply)

**Dashboard widget permissions** are in scope: `EventListener/DashboardSubscriber.php` never
populates `$permissions`, and core's `checkPermissions()` is a no-op when it's empty. The
`top.contacts` table widget currently has **no owner clause** on its query, so a
`lead:leads:viewown`-only user sees other users' contacts' names/emails on the dashboard —
this directly contradicts the plugin's own v1.5.1 IDOR fix on the admin pages and must be
closed as part of this work, not treated as a style-only pass.

**i18n cleanup** is in scope wherever these surfaces are touched — this includes PHP, not
just Twig:
- `Resources/views/BotFilter/list.html.twig`, `contact.html.twig`,
  `Resources/views/Widgets/number.html.twig` (hardcoded table headers/labels/empty-state text)
- `EventListener/DashboardSubscriber.php` (hardcoded `headItems` arrays and tile `label`
  strings — note `table.html.twig` already runs `headItems` through `|trans`, so these must
  become translation keys, not stay pre-translated English)
- `Controller/BotFilterController.php` (`REASON_LABELS` constant)

New/changed keys follow the existing convention already used for menu/widget titles
(`mautic.botfilter.menu.index`, `mautic.widget.botfilter.*`): new keys as
`mautic.botfilter.<surface>.thead.<field>` / `.header.index` / `.contentblock.*`. Reuse core's
own generic keys rather than redefining them — `mautic.core.search.placeholder`,
`mautic.core.pagination.items|pages|total`, `mautic.core.id`, `mautic.core.name` — for the
corresponding generic UI text.

**Known duplication to fix while touching these strings**: the two number widgets currently
render a `label` string inside the tile (`DashboardSubscriber.php`) that duplicates the
widget's own core-drawn title (from `mautic.widget.botfilter.*` in `messages.ini`) — the
design pass must pick one (value-only tile content, dropping the inner label) and remove the
other.

## 4. Scope — out

- No changes to backend detection logic (`VelocityBotRatioHelper`), CLI commands, honeypot
  capture, IP/ASN enrichment, or the `botfilter_blocked_hits`/enrichment-cache schema.
- No changes to the existing admin-page (list/drill-down) ownership-clamp *logic* — it's
  correct today; the redesign must preserve it exactly. (Widget permissions, unlike the admin
  pages, currently have no such logic and must gain it — see Scope — in.)
- Full i18n audit of *other* plugins (ActivityCalendar has the same gap) — tracked as a
  separate future concern, not part of this initiative.
- Any third-party JS/CSS framework beyond what Mautic core already ships (ActivityCalendar
  chose custom Twig+CSS over a library; default to the same unless a surface genuinely needs
  more).
- Per-contact BotFilter signal on the core contact detail page (`getContactTimelineLink()`
  style integration) — reasonable follow-up, explicitly deferred, not silently dropped.

## 5. Technical approach (added after QA — this section did not exist in the original draft)

The original draft said "add Mautic-standard pagination/sort/search" without specifying the
mechanism. It is not optional detail — the naive implementation is both non-functional and a
SQL-injection risk on this specific plugin, so the mechanism is specified here as a hard
constraint on the design and implementation tasks.

**5.1 Route.** BotFilter's routes (`Config/config.php`) have no `{page}` segment
(`/botfilter`, `/botfilter/contact/{leadId}`). Core's pagination helper generates links as
`$baseUrl/$page`, and core's `{page}` route-parameter default/requirement wiring
(`CoreSubscriber`) only applies to routes that declare `{page}`. Both routes must gain a
`{page}` variant (e.g. `/botfilter/{page}`, `/botfilter/contact/{leadId}/{page}`) as part of
issues #5/#6. This is a `Config/config.php` change → requires `cache:clear` +
`mautic:plugins:reload` (cache:clear first, per stack convention) before it takes effect.

**5.2 Ajax list/fragment split.** Core list views split into an index template and a
fragment, branched by `tmpl`:
```twig
{% set isIndex = 'index' == tmpl ? true : false %}
{% set tmpl = 'list' %}
{% extends isIndex ? '@MauticCore/Default/content.html.twig' : '@MauticCore/Default/raw_output.html.twig' %}
```
with the table wrapped in `<div class="page-list">…</div>` as the target
`Mautic.reorderTableData`/`limitTableData`/`filterTableData` swap into via
`Mautic.loadContent(route, '', 'POST', '.page-list')`. `list.html.twig` must adopt this split
(a new `_list.html.twig` fragment included by both the index and raw-output paths) instead of
its current unconditional `{% extends '@MauticCore/Default/content.html.twig' %}`; same
pattern applies to the drill-down page if it gains its own hit-list pagination.

**5.3 Controller sequence.** Core's canonical index-action sequence (see
`LeadBundle/Controller/ListController::indexAction`,
`AbstractStandardFormController` around `getIndexItems()`/`postActionRedirect()`) that
`indexAction`/`contactAction` must be brought in line with:
1. `$page = $request->get('page', session()->get('mautic.botfilter.page', 1))`, stored back
   to session.
2. `$limit = session()->get('mautic.botfilter.limit', coreParametersHelper('default_pagelimit'))`.
3. `$start = (1 === $page) ? 0 : ($page - 1) * $limit`.
4. `$search = $request->get('search', session()->get('mautic.botfilter.filter', ''))`, written
   back to session.
5. `$orderBy`/`$orderByDir` read from session, **through the whitelist in 5.4** — never used
   to build SQL directly.
6. Run a separate `COUNT(DISTINCT h.lead_id)` query for `$totalItems` (the list query is a
   `GROUP BY`, so its row count isn't the contact count).
7. If `$start` is beyond `$totalItems`, `postActionRedirect()` back to the last valid page
   (core's out-of-range behaviour).
8. View gets `sessionVar`, `searchValue`, `totalItems`, `page`, `limit`, `tmpl`; set
   `passthroughVars.route = generateUrl('mautic_botfilter_index', ['page' => $page])` (current
   code omits `page` here).
9. On the drill-down, apply `LIMIT`/`OFFSET` **before** `$enricher->enrichMany()` — today
   `contactAction` enriches every hit unconditionally before rendering, so pagination must cut
   the row set first or the cost is paid regardless.

**5.4 SQL safety.** BotFilter's queries are hand-built interpolated SQL, not Doctrine
QueryBuilder — core's injection safety (bound `search` parameter, `orderBy` validated against
Doctrine-mapped fields) does not apply here by default. Required:
- Sort: a hardcoded whitelist map from the (session-stored) sort key to a literal SQL
  fragment, e.g. `['hits' => 'hits', 'lastname' => 'l.lastname', 'lastseen' => 'last']`, with
  a safe default if the key isn't in the map. Never interpolate the session's raw orderBy
  value into SQL.
- Search: a bound `:search` parameter with `LIKE`, never string-concatenated into the query.
- The existing bare `catch (\Throwable $e) {}` around these queries (`BotFilterController.php`)
  must be changed to at least log the throwable — today a broken or malicious query fails
  silently and renders as "no bot hits recorded yet", which would mask exactly the class of
  bug this work could introduce.
- `GROUP BY h.lead_id` while selecting `l.firstname`/`l.lastname`/`l.email` relies on
  `ONLY_FULL_GROUP_BY` being off; this stack's current `sql_mode` allows it, but MySQL 8.4
  defaults to enabling it. Add the lead columns to `GROUP BY` (or wrap in `ANY_VALUE()`) while
  touching this query, so it doesn't depend on a non-default server setting.

**5.5 Reusable core includes.** The design (`ui-spec.md` from #4) should specify pagination,
sort, search and empty-state chrome in terms of these existing core templates/macros rather
than inventing new ones — all usable from a plugin view with no core changes:
- `@MauticCore/Helper/pagination.html.twig` — needs `totalItems, page, limit, sessionVar, baseUrl`.
- `@MauticCore/Helper/tableheader.html.twig` — needs `sessionVar, orderBy, text, class, default`
  per column (reads `mautic.<sessionVar>.orderby/.orderbydir` from session).
- `@MauticCore/Helper/list_toolbar.html.twig` (wraps `search.html.twig`) — needs
  `searchValue, action, target, tmpl`.
- `@MauticCore/Helper/noresults.html.twig` — empty state.

**5.6 "Last seen" consistency.** The list query uses `MAX(h.date_captured)` for "Last seen";
the drill-down uses `date_hit` per row. Both columns exist but `date_hit` is nullable and
`date_captured` is not. Pick one semantic for "when did this bot hit happen" (recommend:
`date_hit`, coalesced to `date_captured` when null) and use it consistently on both surfaces
so the list's "Last seen" matches the drill-down's most recent row, and so sorting by it
doesn't sort a partially-null column.

**5.7 Settings-form deliverable.** Given the corrected scope in section 3 item 4, the design
pass decides whether to use `getFormTheme()` and/or `getFormNotes('custom')`, and must design
for the tabbed-modal presentation core actually uses (`PluginController::editAction`), not a
standalone page.

**5.8 Plugin icon.** Neither `Assets/img/icon.png` nor `Assets/img/intellectitbotfilter.png`
exists, so the Settings → Plugins card currently falls back to core's generic icon. Add one as
part of #4 (design) and #8 (implementation) — it's the most visible part of the settings
surface and was missing from the original scope.

**5.9 Menu icon — deliberate prior decision.** The BotFilter menu entry has no icon by
design (PR #1 explicitly removed one: "Remove icon from botfilter menu item"). The design
pass should re-decide this deliberately rather than silently reintroducing an icon.

**5.10 Asset loading.** Follow ActivityCalendar's existing pattern —
`{{ includeStylesheet('plugins/IntellectITBotFilterBundle/Assets/css/bot-filter.css') }}`
inside `{% block content %}`, which core loads asynchronously via `Mautic.loadStylesheet()`
and survives ajax navigation with no `mautic:assets:generate` step even at `APP_ENV=prod`
(expect a brief FOUC on first paint of a table-heavy page — acceptable, matches
ActivityCalendar's own approach). No `docker-compose.yml` change is needed — the plugin
directory is already bind-mounted — and `traefik/dynamic/block_sensitive_paths.yml` does not
block plugin `Assets/` paths (only dotfile-prefixed paths).

## 6. Process pipeline

Mirrors ActivityCalendar's spec → design → implementation shape, with an explicit deployment
step ActivityCalendar didn't need to think about as hard (this plugin adds routes and several
new translation keys, both of which need a cache/plugin-reload cycle to take effect):

1. **This spec** — functional/design-requirements document (this file), including the
   technical approach in section 5.
2. **Claude Design pass** — one canvas (`.dc.html`) and one `docs/ui-spec.md` covering all
   four surfaces as a single coherent design language (shared tokens, shared table/badge/
   number-tile components, the reusable core includes named in 5.5, the settings-modal
   constraint in 5.7, and the icon in 5.8).
3. **Implementation**, one task per surface, each landing on the shared branch
   `feat/botfilter-ui-overhaul`, in this order (not independent — #6 and #7 both build on the
   shared CSS/token scaffolding and controller pagination pattern #5 establishes first):
   1. Admin list page (establishes shared `Assets/css`, pagination/sort/search scaffolding)
   2. Contact drill-down page (reuses the scaffolding from step 1)
   3. Dashboard widgets (inner-content only, per section 3 item 3; fold in the permission fix)
   4. Settings/config form
   Each of these steps that changes `Config/config.php` (routes) or adds translation keys
   requires, before it's considered done: `cache:clear` → `mautic:plugins:reload` (only if
   `config.php` changed) → restart `mautic_web`.
4. **QA & verification** — this is a **live production, single-node stack** with no separate
   dev/staging environment (there is no "start the dev stack" step available here). Verify
   either:
   - in a throwaway container built from the same production image (the approach already
     used to validate the Traefik sensitive-paths rule), or
   - directly against production during a short, user-approved window, with the existing
     backup-before-bulk-edit convention followed and a clear rollback (git revert + cache
     clear + restart) ready before starting.
   Either way: exercise the golden path and edge cases (empty state, the existing 211-hit
   contact as the "large result set" case — no synthetic fixtures needed, prod data already
   covers it, pagination boundaries, search with no results) for each surface, in a real
   browser, before opening the PR.

## 7. Acceptance criteria

- All four surfaces visually consistent with each other (shared design tokens: colour,
  spacing, type scale, component styles for tables/badges/number tiles), within the bounds
  core imposes on widget chrome (section 3 item 3) and the settings modal (section 3 item 4).
- List and drill-down pages support pagination, column sort, and search per section 5
  (real route `{page}` segment, ajax `tmpl`/`page-list` split, whitelist-sort/bound-search —
  not a superficial reproduction of the visual pattern only).
- Dashboard widgets respect contact ownership (`$permissions` populated, owner clause on the
  `top.contacts` query) — no PII regression relative to the admin pages' existing protection.
- No hardcoded UI strings remain in touched templates or PHP (`DashboardSubscriber.php`,
  `BotFilterController.php` included) — verified with a grep pass for literal English strings
  in the touched files' output paths, not just a visual check.
- No regression to IDOR-safe ownership clamping on the admin pages, dashboard widget data
  accuracy, or the existing validation scripts (7 files / 60 checks). Full-suite re-run is
  only required when backend/query code is touched; for UI-only changes, running the two
  scripts that don't mutate live tables (`LogicTest`, `ConfigProviderTest`,
  `HoneypotInjectionTest`) is sufficient.
- Settings form remains fully functional; any relabeling/regrouping preserves existing config
  keys (no migration needed).
- Verified working end-to-end against the environment described in section 6 step 4 before
  the PR is opened, including the `cache:clear`/`plugins:reload`/restart cycle actually being
  run, not just planned.

## 8. Tracking

Filed as a GitHub epic + 7 linked task issues on `msoukhomlinov/IntellectITBotFilterBundle`
(repo had zero prior issues). Each task issue carries its own acceptance-criteria checklist
and a "Progress log" section updated with dated comments as work spans sessions. Single
branch `feat/botfilter-ui-overhaul`, one PR at the end closing all task issues.

## 9. QA history

- 2026-08-31: Opus sub-agent QA pass against actual code, live DB, and Mautic 7.1.3 core
  source found 4 blockers (broken pagination mechanism, SQL-injection risk from raw
  interpolated queries, a verification plan citing a non-existent dev environment, and an
  unprotected PII leak on dashboard widgets) plus several accuracy/completeness issues (wrong
  test count, incomplete i18n scope, understated settings-form surface, vague acceptance
  criteria). This revision folds in every finding; see git history for the pre-QA version.
