# Architecture

Design notes for maintainers of the IIT Bot Filter plugin (Mautic 7.x): what each component does,
how requests flow, and the rules the code relies on. UI layout, tokens and copy live in
`docs/ui-spec.md`; user-facing behaviour and installation live in `README.md`.

## Contents

- [Components](#components)
- [Capture design](#capture-design)
- [Admin list and drill-down request flow](#admin-list-and-drill-down-request-flow)
- [SQL rules](#sql-rules)
- [Error handling](#error-handling)
- [Integration discovery contract](#integration-discovery-contract)
- [Widget permissions and caching](#widget-permissions-and-caching)
- [DI rebuild safety](#di-rebuild-safety)
- [Verification notes](#verification-notes)
- [Rejected alternatives](#rejected-alternatives)

---

## Components

| Component | Files | Role |
|---|---|---|
| Decorator | `Helper/VelocityBotRatioHelper.php`, `Config/services.php` | Decorates `Mautic\EmailBundle\Helper\BotRatioHelper` (used by open and click tracking). When enabled: core check first (`core-3signal`), then click burst, then open burst. Returns `true` on the first match and records it. |
| Settings reader | `Helper/ConfigProvider.php` | Memoised, exception-safe reader of the integration's feature settings. `isEnabled()` is the master switch (integration exists and is published). |
| Recorder | `Helper/BlockedHitRecorder.php` | One `INSERT` per classification into `botfilter_blocked_hits`. Truncates `url` (2048) and `user_agent` (255), JSON-encodes `trigger_ips`, sets `date_captured` in UTC. Logs and swallows every failure. |
| Honeypot injection | `EventListener/HoneypotInjectionSubscriber.php` | On `EmailEvents::EMAIL_ON_SEND`, adds a hidden non-trackable link before `</body>`. |
| Honeypot endpoint | `Controller/HoneypotController.php` | Public route `mautic_botfilter_honeypot` (`/bf/honeypot`). Records `reason = honeypot` for a valid hash; always returns 204. |
| IP enrichment | `Helper/IpEnricher.php`, `Command/UpdateAsnDbCommand.php`, `Command/EnrichIpsCommand.php` | Geo from core's `ip_addresses.ip_details`, ASN/org from an offline `GeoLite2-ASN.mmdb` in `%kernel.cache_dir%/../ip_data`, datacenter/provider from a curated ASN map. Cached in `botfilter_ip_enrichment`. Owns `ASN_DB_STALE_SECONDS`. |
| Admin pages | `Controller/BotFilterController.php`, `Resources/views/BotFilter/list.html.twig`, `Resources/views/BotFilter/contact.html.twig` | Affected-contacts list and per-contact drill-down under Channels. |
| Dashboard widgets | `EventListener/DashboardSubscriber.php`, `Resources/views/Widgets/number.html.twig` | Four widgets: two number tiles, two core tables. |
| Integration / settings | `Integration/IntellectITBotFilterIntegration.php`, `Resources/views/Integration/form.html.twig`, `Config/config.php` | Settings-only integration card: published toggle, thresholds, honeypot, diagnostics. |
| Hit-encoding sanitiser | `EventListener/HitEncodingSubscriber.php`, `Helper/Utf8Sanitiser.php` | Doctrine `prePersist` listener on `Mautic\PageBundle\Entity\Hit`. Replaces invalid UTF-8 and NUL in client-controlled fields with U+FFFD and caps `varchar(191)` fields, so the insert cannot fail. |
| Maintenance commands | `Command/InstallCommand.php`, `Command/PruneCommand.php`, `Command/RecountBotStatsCommand.php` | Create both tables and indexes; delete old hits and orphaned enrichment rows; retroactively recount inflated stats with the same burst signature. |

Tables:

| Table | Written by | Notes |
|---|---|---|
| `botfilter_blocked_hits` | Recorder (decorator and honeypot endpoint) | Append-only audit log, one row per classification. PK `id`. |
| `botfilter_ip_enrichment` | `IpEnricher` | Cache keyed by `ip`; `INSERT … ON DUPLICATE KEY UPDATE`. |

---

## Capture design

**Burst signal**

| Arm | Source | Match |
|---|---|---|
| Click | `page_hits` joined to `ip_addresses` | same `email_id` + `lead_id`, `date_hit >= since` |
| Open | `email_stats_devices` joined to `ip_addresses` | same `stat_id`, `date_opened >= since` |

- A burst is `COUNT(DISTINCT ip) >= getMaxDistinctIps()`.
- `since` is the hit timestamp minus `getWindowSeconds()`, formatted in UTC to match the stored columns.
- Evidence is recorded with the row: `distinct_ip_count`, `trigger_ips` and `window_seconds`.

**Channel and URL detection (route-based)**

| `_route` of the current request | `channel` | `url` |
|---|---|---|
| `mautic_email_tracker` | `open` | `NULL` |
| `mautic_url_redirect`, `mautic_page_redirect` | `click` | `RedirectModel::getRedirectById(redirectId)->getUrl()`, null-safe |
| anything else, or no request | `NULL` | `NULL` |

Core's call sites do not pass channel or URL to `isHitByBot()`, and the plugin does not modify core.
The request attributes are the only upgrade-safe source.

**What the table holds**

- Only hits the decorator classified while the integration was published, plus honeypot hits.
- Hits that core rejects before `isHitByBot()` runs are not recorded. The table is "hits BotFilter
  classified", not "all bot traffic"; the list page shows a permanent note saying so.
- Core metrics are still cleaned: a classified hit returns `true` exactly as before capture existed.
- No deduplication on the hot path. Aggregation happens in the admin and widget queries.

**Exception isolation on the tracking path**

- Both burst queries are wrapped in `try/catch` and degrade to "not a burst".
- `capture()` wraps channel detection, domain parsing and the recorder call in `try/catch`.
- The recorder catches and logs its own database errors.
- Nothing reachable from open/click tracking can throw.

**Honeypot**

- Injection conditions: honeypot enabled (requires the integration to be published), email ID in the
  allowlist or the allowlist blank, a non-empty ID hash, and content containing `</body>`.
- The anchor carries `data-mautic-disable-tracking="true"`. Without it core rewrites the link into a
  tracked redirect and `/bf/honeypot` is never reached.
- The endpoint reads `h` via `$request->query->all()`. `get()` / `getString()` throw on an array
  parameter (`?h[]=x`), which would return 400.
- The endpoint always returns `204` with an empty body, whether or not the hash matched. There is no
  hash-enumeration oracle.
- A matched hash writes one row with `reason = honeypot` and `channel = NULL`. It is not correlated
  by time window with other hits: it is a standalone, deterministic "this message was machine-scanned"
  signal per contact.
- The endpoint does not check published state. Links already delivered keep recording after the
  integration is unpublished.
- `HoneypotController` does not extend `AbstractController`, so autoconfigure does not tag it. It is
  registered `public` with `controller.service_arguments` in `Config/services.php`; without the tag
  the route cannot resolve the controller and returns 500.

---

## Admin list and drill-down request flow

Routes (`Config/config.php`, `routes.main`, served under `/s/`):

| Route | Path | Action |
|---|---|---|
| `mautic_botfilter_index` | `/botfilter/{page}` | `BotFilterController::indexAction` |
| `mautic_botfilter_contact` | `/botfilter/contact/{leadId}/{page}` (`leadId` = `\d+`) | `BotFilterController::contactAction` |

Core's pagination builds links as `baseUrl/page`, and core only applies its `{page}` defaults to
routes that declare the segment.

**Controller sequence (both actions)**

1. Gate: `hasAccess()` = `lead:leads:viewown` OR `lead:leads:viewother` (same as the menu `access`),
   else `accessDenied()`.
2. Drill-down only: load the contact and call
   `hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', owner_id)`. A missing contact or a
   failed check is denied before any hit data is read, so `leadId` cannot be enumerated.
3. Page: an omitted `{page}` arrives as `0`; fall back to the page remembered in session. An explicit
   page is stored back.
4. `setListFilters()` copies `orderby` / `orderbydir` / `limit` from the request into session.
   `getDefaultOrderDirection()` returns `DESC`.
5. `PageHelperFactoryInterface::make()` yields `limit` and `start`.
6. Resolve sort key and direction through the whitelist, and search (and, on the drill-down, reason)
   through bound parameters. See [SQL rules](#sql-rules).
7. List only: ownership clamp `AND l.owner_id = :uid` unless the user has `lead:leads:viewother`.
8. `COUNT` query for `totalItems`. The list uses `COUNT(DISTINCT h.lead_id)`, because the row query
   is grouped.
9. If `start >= totalItems`, `postActionRedirect()` to the last page. `contentTemplate` must be a
   controller reference (`self::class.'::indexAction'`): on ajax requests it is forwarded as a
   controller, and a Twig path there fails.
10. Page query with `LIMIT` / `OFFSET`.
11. Per-page enrichment only after paging:
    - List: reasons and top provider for the page's `lead_id`s (`IN (:leadIds)`).
    - Drill-down: `IpEnricher::enrichMany()` for the page's IPs only.
12. Panels outside the swap target are computed only when `tmpl == 'index'`. On fragment requests the
    `EMPTY_*` placeholder constants are passed instead (Twig runs with `strict_variables`, so the
    variables must exist).
    - List: health strip and provider rollup.
    - Drill-down: identity header stats.
13. `delegateView()` with `passthroughVars.route` including the current `page`.

Session namespaces: `mautic.botfilter.*` for the list, `mautic.botfilter.contact.*` for the
drill-down. Paging one contact does not reset the list page.

**Template split (`tmpl`)**

- `tmpl == 'index'`: extends `@MauticCore/Default/content.html.twig`. Renders the full page with the
  health strip, rail and toolbar, and a `<div class="page-list">` wrapper containing
  `block('listResults')`.
- Otherwise: extends `@MauticCore/Default/raw_output.html.twig` and renders only `listResults`. Core's
  sort, search and pagination JavaScript swaps it into `.page-list`.
- The fragment must not repeat `class="page-list"`, or two elements match the swap target.
- Everything outside `.page-list` (health strip, provider rail, identity header) is install-wide or
  contact-wide and never scoped to the search term, because a live search cannot refresh it.
- Drill-down `tableheader.html.twig` includes pass an explicit `baseUrl`. Without it core falls back
  to `window.location.pathname` and rewrites the trailing numeric segment. On
  `/s/botfilter/contact/{leadId}` that segment is the `leadId`, not a page.
- The drill-down reason `<select>` has class `not-chosen` so core does not convert it into a Chosen
  widget on page load.

**Stylesheet loading**

`{{ includeStylesheet('plugins/IntellectITBotFilterBundle/Assets/css/bot-filter.css') }}` is emitted
inside the content block:

- List and drill-down: in the `isIndex` branch.
- Also at the top of `form.html.twig` and `number.html.twig`.

Core loads it asynchronously at render, so it survives ajax navigation. There is no asset build step.
A brief unstyled first paint is expected.

**Last seen**

| Surface | Expression |
|---|---|
| List "Last seen" column and drill-down header stat | `MAX(COALESCE(date_hit, date_captured))` |
| Drill-down rows: display | `date_hit`, falling back to `date_captured` |
| Drill-down rows: sort key `date` | `COALESCE(date_hit, date_captured)` |
| Dashboard widgets (30-day window, "Last seen" in top contacts) | `date_captured` |

`date_hit` is nullable; `date_captured` is not. Coalescing keeps the list's "Last seen" equal to the
drill-down's newest row, and avoids sorting a partially-null column.

---

## SQL rules

Queries are hand-written DBAL SQL, so none of Doctrine's QueryBuilder safeguards apply.

| Rule | Implementation |
|---|---|
| Never build `ORDER BY` from input | `SORT_MAP` / `CONTACT_SORT_MAP` map a whitelisted key to a **list** of SQL expressions. Unknown key → default (`hits` / `date`). Direction normalised to `ASC`/`DESC`. |
| Direction applies to every expression | `buildOrderBy()` appends the direction to each expression. `ORDER BY a, b DESC` would sort `a` ascending. |
| Stable paging | `buildOrderBy()` appends a unique tie-breaker with fixed `ASC`: `h.lead_id` (list), `id` (drill-down). Without it, tied rows can move between pages. |
| Bound search | `:search` = `'%'.$search.'%'` with `LIKE`, never concatenated. |
| Validated filters | `reason` is bound AND checked against `KNOWN_REASONS`; an unknown value means no filter rather than matching nothing. |
| Params match the SQL | The rollup receives only the ownership params (`:uid`), not `:search`, so each query's params name exactly what it references. |
| `ONLY_FULL_GROUP_BY`-safe | Every selected non-aggregate column is in `GROUP BY` (`h.lead_id, l.firstname, l.lastname, l.email`); provider grouping uses the same `COALESCE(e.provider, 'Unclassified')` expression it selects. |
| Consistent tie-breaks across surfaces | `fetchTopProviderByLead()` (window function) and `buildContactStats()` both order by `COUNT(*) DESC, provider ASC`, so the Scanner cell and the "Behind X" badge always agree. |
| Table prefix | Every table name is `MAUTIC_TABLE_PREFIX` + name. |
| Bounded per-page queries | Reasons and top provider use `IN (:leadIds)` with `ArrayParameterType::INTEGER` over the current page only, not correlated subqueries. |
| UTC | Stored datetimes are UTC (`gmdate`). Windows are built in UTC. Display converts with `dateToFull(value, 'UTC')` in Twig or `DateHelper` in PHP. A `DATE()` day bucket is formatted without a timezone shift (`DashboardSubscriber::fmtDay()`). |

---

## Error handling

Log failures; do not swallow them. A broken query must never look like an empty install.

| Area | On failure |
|---|---|
| List / drill-down page query | `warning` on the `mautic` channel logger; `queryFailed = true` → plugin's `alert-danger` instead of the empty state. |
| Health strip | Each read independent. `ConfigProvider` and `IpEnricher::asnDbInfo()` (filesystem stat) cannot throw; the last-capture `MAX()` read has its own `try/catch` → logged, value "Unknown". |
| Provider rollup | Own `try/catch` → logged, `EMPTY_ROLLUP` (panel hidden). |
| Reasons / top provider per page | Own `try/catch` each → logged, empty map (cells fall back). |
| Drill-down identity stats | Own `try/catch` → logged, `EMPTY_CONTACT_STATS`. |
| Settings Diagnostics | `error` logged; the form still renders, and rows show warning / "Unknown" rather than success. |
| ASN freshness | An unreadable mtime counts as stale. Both surfaces compare unrounded seconds to `IpEnricher::ASN_DB_STALE_SECONDS`; day counts are display-only. |
| Dashboard widgets | Query errors → empty widget (no raw Doctrine error on the dashboard). |
| Tracking path (decorator, recorder, honeypot) | Never throws. See [Capture design](#capture-design). |
| Twig | `strict_variables` is on. Nullable nested values use `?? null` (e.g. `h.enrich` is null when `ip` is null). On ajax fragment requests a Twig error surfaces as a 200 whose body is the error page, not as a 500. |

---

## Integration discovery contract

The settings card exists only if all three names align; a mismatch fails silently (no card, no error).

| Piece | Value |
|---|---|
| File | `Integration/IntellectITBotFilterIntegration.php` |
| `getName()` | `IntellectITBotFilter` (file name minus `Integration.php`) |
| Service id | `mautic.integration.intellectitbotfilter` (lowercased name), declared in `Config/config.php` under `services.integrations` |

The service's `arguments` must be the `AbstractIntegration` constructor arguments, in order:

1. `event_dispatcher`
2. `mautic.helper.cache_storage`
3. `doctrine.orm.entity_manager`
4. `request_stack`
5. `router`
6. `translator`
7. `monolog.logger.mautic`
8. `mautic.helper.encryption`
9. `mautic.lead.model.lead`
10. `mautic.lead.model.company`
11. `mautic.helper.paths`
12. `mautic.core.model.notification`
13. `mautic.lead.model.field`
14. `mautic.plugin.model.integration_entity`
15. `mautic.lead.model.dnc`
16. `mautic.lead.field.fields_with_unique_identifier`

Other rules:

- `ConfigProvider` looks the integration up by name through `mautic.helper.integration`.
- **Unpublished or absent = stock Mautic.** The decorator delegates every call to core, no burst
  signal runs, no decorator rows are written, and no honeypot link is injected. The only plugin
  behaviour left is the honeypot endpoint recording valid hashes from links already delivered.
- Feature settings keys: `honeypot_enabled`, `honeypot_email_ids`, `max_distinct_ips` (values below 2
  → default 3), `window_seconds` (values below 1 → default 30). The custom template does not rename
  or migrate them.
- `getFormTemplate()` replaces core's template because core's supports only a fixed tab set.
- `getFormNotes('features')` returns `[note, type]`. `getFormNotes('custom')` returns an HTML string:
  the template renders it with `|raw` (HTMLPurifier would strip the layout classes), so
  `renderDiagnosticsHtml()` escapes every value itself.
- The template calls `setRendered()` on `featureSettings.leadFields`, `companyFields`,
  `featureSettings` and `apiKeys`. Otherwise `form_end()` renders core's field-mapping block outside
  the tabs.
- `getFormNotes()` reads `$this->settings` behind `isset()`: it is a typed property that is
  uninitialised until `setIntegrationSettings()` runs.
- Diagnostics reuse the `$em` and `$pathsHelper` that `AbstractIntegration` already injects; no extra
  service wiring.

---

## Widget permissions and caching

| Concern | Behaviour |
|---|---|
| `$permissions` | `['lead:leads:viewown', 'lead:leads:viewother']`, matching the admin page's OR contract. |
| Render gate | Core `WidgetDetailEvent::hasPermissions()` is `in_array(true, …)` → **OR**. |
| Picker gate | Core `WidgetTypeListEvent::hasPermissions()` is `!in_array(false, …)` → **AND**. `onWidgetListGenerate()` is overridden to test one permission at a time and OR the results, so a viewown-only role still sees the widgets in "add widget". |
| viewother-only roles | Core `AbstractPermissions::analyzePermissions()` adds `viewown` whenever `viewother` is granted, so such a role cannot normally be saved. Listing both keeps the gate correct without relying on that. |
| Foreign-widget guard | `onWidgetDetailGenerate()` returns immediately unless `getType()` is one of this bundle's four types. Core dispatches detail generation to every subscriber, not all of them stop propagation, and widget params are part of core's cache key — touching a foreign widget's params would partition its cache per viewer and hand it an undeclared param. |
| Denied viewer | After `checkPermissions()`, if the event has an error message, return before `setTemplateData()`, which writes to the shared cache. |
| Cache scope | Core's widget cache key (`WidgetDetailEvent::getUniqueWidgetId()`) covers params, width, height and locale only — it is global across users. `bf_scope` (`all` or `u<userId>`) is merged into the widget params **before** the first `isCached()` / cache-key call (the key is memoised), giving one cache entry per ownership scope. |
| Ownership clamp | All four widget queries join `leads l` and apply `ownerClause('l')`: no clause for `viewother`, else `AND l.owner_id = :uid`. |
| Fail closed | If the security service is null (partially wired container), the viewer is treated as clamped. If the user helper is also null, `uid = 0` → no contact data. Never unclamped. |
| Visible clamp | Both number tiles switch to the "contacts you own" sub-line when clamped. |
| Window | Last 30 days, bound built with `gmdate` against `date_captured`. |
| Core table template | `headItems` are translated by core; body cells are escaped and not translated, so reasons are translated in PHP (`reasonLabel()`). Link cells use `{type: 'link', link, value}`. There is no raw-HTML cell type. |

---

## DI rebuild safety

**Rule:** a new constructor dependency on a service that is already in the compiled container
(decorator, event subscriber, controller) is added as nullable with a null default
(`private ?Type $dep = null`), and the code degrades when it is null.

**Why:**

- The compiled container is cached generated PHP. Once new code is on disk, a stale container can
  still instantiate the class with the old argument list, both before `cache:clear` runs and while it
  rebuilds.
- A required new argument then throws `ArgumentCountError` before any method body runs. Every request
  resolving that service fails: tracking pixel, click redirect, dashboard, or queue worker.

| Service | Degradation when deps are null |
|---|---|
| `VelocityBotRatioHelper` | No `$inner` → return `false`. `$inner` present but other deps missing → core detection only. |
| `DashboardSubscriber` | `DateHelper` null → `fmtFull()` / `fmtDay()` return the raw value. `Connection` null → the call throws `\Error`, caught → empty widget. Security / user helper null → fail closed (see [Widget permissions and caching](#widget-permissions-and-caching)). |

**Deploy procedure:**

1. Deploy code, then run `cache:clear` immediately and restart PHP-FPM (or the web server) so opcache
   drops the old container.
2. If `Config/config.php` changed (routes, menu, integration service), also run
   `mautic:plugins:reload` after `cache:clear`.
3. Check the application log after the restart for `ArgumentCountError` / "Too few arguments".
4. Queue workers and cron processes that share the same cache directory load the same compiled
   container. Restart or re-run them too, and check their output as well as the web log.

Related facts:

- A new argument bound in `services.php` has no effect until the container is rebuilt. An optional
  argument silently stays null in the meantime (the feature "half works").
- `debug:container` compiles a fresh container, so it does not prove what a running process is using.
- `Tests/` is excluded from the service autoload glob. Otherwise the standalone scripts would execute
  during container compilation.

---

## Verification notes

- **`php -l` does not resolve class names.** An unresolved `use` (or a wrong class) that is only
  referenced from a class constant expression, such as
  `private const ASN_DB_STALE_SECONDS = IpEnricher::ASN_DB_STALE_SECONDS;`, passes lint and fails
  only when the constant is first evaluated. Load the class and read the constant, or run a test that
  does.
- Tests are standalone PHP scripts (see `Tests/README.md`), located through `MAUTIC_ROOT` (default
  `/var/www/html`). Scripts that write do so inside transactions that are always rolled back, but they
  still touch the configured database — use a non-production instance.
- Unit tests that construct objects directly (e.g. the honeypot controller) cannot catch
  route-to-service resolution problems; check a real request to `/bf/honeypot` after wiring changes.

---

## Rejected alternatives

| Alternative | Why not |
|---|---|
| Search-scoped provider rollup | The rail is outside the ajax swap target; it would show the previous search's counts beside new results. |
| `$permissions = ['lead:leads:viewown']` only | Denies a viewother-without-viewown role the admin page allows; replaced by both permissions plus the OR picker override. |
| Coloured reason badge in the daily-breakdown widget | Core's table template has no raw-HTML cell; needs a bespoke widget template (planned enhancement). |
| Core `noresults.html.twig` for "no captures yet" | Its warning styling frames a healthy, quiet install as a problem. Still used for searches that match nothing. |
| Enriching every hit before paging | Enrichment cost would scale with the contact's history instead of the page. |
| `DateHelper::toDate($day, 'UTC', 'Y-m-d')` for day buckets | The third argument is the input format; the parse takes the current wall-clock time and shifts UTC to local, labelling buckets a day late for part of each day. |
| Core's default integration form template | Supports only its fixed tab set; named tabs need a replacement template. |
| `\|purify` on the Diagnostics HTML | Strips the layout classes; values are escaped in PHP and rendered `\|raw`. |
| Correlating a honeypot hit with other hits by time window | Racy; the honeypot is kept as a standalone per-contact signal. |
| Honeypot configuration through environment variables | Replaced by the integration's settings form, which is the single source of truth. |
| Dropping values (or whole page hits) with invalid UTF-8 | Loses the row; invalid sequences are replaced with U+FFFD instead. |
| `preUpdate` for the hit-encoding listener | Client-controlled fields are set at insert; `preUpdate` would need the changeset API for little benefit. |
