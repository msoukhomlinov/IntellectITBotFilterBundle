# Changelog

All notable changes to this plugin are documented here. Format follows
[Keep a Changelog](https://keepachangelog.com/); this project adheres to
[Semantic Versioning](https://semver.org/).

## [1.10.2] — 2026-09-14

### Fixed

- **`ArgumentCountError` on Mautic 7.2.0 and later.** `VelocityBotRatioHelper` called
  `parent::__construct()` with no arguments. Core 7.2.0 added a required
  `DeviceDetectorFactoryInterface` argument to `BotRatioHelper::__construct()`, so building
  the decorator failed and open/click tracking returned HTTP 500. The decorator no longer
  calls the parent constructor; all detection is delegated to the inner core helper.

### Added

- `composer.json` (`type: mautic-plugin`, `mautic/core-lib ^7.0`), SPDX license headers,
  `SECURITY.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, issue and pull request templates,
  `docs/architecture.md`.
- Test scripts locate Mautic via the `MAUTIC_ROOT` environment variable (default
  `/var/www/html`) and read fixture IDs from `BOTFILTER_TEST_*` variables.

## [1.10.1] — 2026-09-10

v1.10.0 fixed one of **two** ways a request's bytes can cost a page hit. This is the other.

### Fixed

- **A NUL byte in the request still lost the hit, and an encoding check could never
  catch it.** NUL is perfectly valid UTF-8 (U+0000), so `mb_check_encoding()` returns
  true and v1.10.0's sanitiser left it untouched. But
  `Mautic\CoreBundle\Doctrine\Type\ArrayType::convertToDatabaseValue()` throws a
  `ConversionException` for any serialized array containing `chr(0)` — **before** the
  query reaches MySQL. So two different layers reject two different byte classes for
  two different reasons, and the real scanner payload
  (`\xAC\xED\x00\x05sr\x00\x11java.util.HashMap`) carries **both**: sanitising for
  only one of them still lost the row.

  `Utf8Sanitiser::sanitiseString()` now replaces NUL with U+FFFD **first**, before the
  encoding pass, since the encoding pass provably cannot see it.

### Fixed (amended 2026-09-10 — same version, test-only change)

- **The live test could report success while some of its assertions never ran.** The write
  and the read-back shared one `try`/`catch`, so a read that threw or found no row skipped
  the round-trip assertions and the script still exited 0. The write and the read now keep
  separate error state, the read-back assertions are unconditional, the rollback happens
  before any assertion, and the number of checks that ran is asserted. No plugin behaviour
  changed.

### Notes

- v1.10.0's database round trip used a `TEMPORARY` table through raw PDO, which bypasses
  `ArrayType`, so it did not cover this failure. New `Tests/HitEncodingLiveTest.php`
  persists a real `Hit` through the EntityManager inside a transaction that is always
  rolled back, and checks that nothing is left behind (12 checks).
- `HitEncodingTest` checks 62 → 76, including a negative control showing the unsanitised
  array is rejected by the `chr(0)` guard.
- Upgrade: method bodies only; no new service and no constructor change. Run
  `mautic:plugins:reload` to update the stored plugin version.

## [1.10.0] — 2026-09-09

A page hit whose request carried bytes that are not valid UTF-8 could not be stored,
and the failed INSERT took the whole request down with it.

### Added

- `Helper/Utf8Sanitiser` — replaces invalid UTF-8 sequences with U+FFFD, recursing
  through nested arrays and sanitising array KEYS as well as values, with an optional
  character-count cap for `varchar` columns.
- `EventListener/HitEncodingSubscriber` — a Doctrine `prePersist` listener that applies
  the sanitiser to every client-controlled field on `Mautic\PageBundle\Entity\Hit`:
  `query`, `browserLanguages`, `url`, `referer`, `userAgent`, `urlTitle`, `remoteHost`,
  `pageLanguage` and `trackingId`. The geo fields are left alone — they come from the
  MaxMind databases, not the request.

### Fixed

- **A request carrying non-UTF-8 bytes returned 500 and lost its page hit.**
  Triggered by scanners sending e.g. `POST /com.example.TestService` with a serialized
  Java object (`\xAC\xED\x00\x05...`) as the body. `PageModel::getHitQuery()` merges GET
  and POST wholesale into `Hit::$query`, Doctrine serializes that array into the utf8mb4
  `page_hits.query` column, and MySQL with `STRICT_TRANS_TABLES` enabled (the MySQL 8
  default) rejects the INSERT with `1366 Incorrect string value`. Doctrine then closes the
  EntityManager, so everything later in the request fails too.

  With `MAUTIC_MESSENGER_DSN_HIT` set to `sync://default` the hit is handled inside the
  web request and nothing is queued, so the cost is a 500 per malformed request plus the
  lost hit. With an asynchronous hit transport, check `messenger_messages` for a failing
  message that keeps retrying.

  Sanitising rather than dropping matters for the case that is not a scanner: a
  mostly-ASCII value carrying a few legacy-encoded bytes now stores readably instead of
  costing the row.

### Notes

- The sanitiser caps the `varchar(191)` fields because substitution can *lengthen* a
  value (U+FFFD is three bytes), and an over-long value is error 1406 under
  `STRICT_TRANS_TABLES` — the same failure class, one column along.
- Two query keys differing only in their invalid bytes collapse into one and the later
  value wins. Accepted deliberately: the alternative is losing the whole row.
- Tests 60 → 122 checks. `Tests/HitEncodingTest.php` carries a **negative control**
  proving the unsanitised scanner payload is still rejected with `1366` against a
  `TEMPORARY` table of the same type and collation, so a no-op fix could not pass; it
  also asserts `STRICT_TRANS_TABLES` is actually set, since without it that control
  cannot fail. It skips loudly if the database is unreachable.

## [1.9.3] — 2026-09-01

Two status surfaces (list health strip and settings Diagnostics tab) could disagree.

### Fixed
- **The two ASN staleness verdicts disagreed for a full day.** The list page's health strip
  compared raw seconds against `7 * 86400`, while the settings Diagnostics tab compared a
  *floored day count* with `> 7`. Anything between exactly 7 and 8 days old was therefore
  amber on one surface and green on the other. The threshold now lives once, as
  `IpEnricher::ASN_DB_STALE_SECONDS` (the class that already owns `asnDbInfo()`), and both
  surfaces compare the unrounded age against it; the floored day count is display-only.
  `getDiagnostics()` gained `asnAgeSeconds` and `asnStale` for this, and applies the strip's
  existing rule that an unreadable mtime counts as stale rather than fresh.
- **The enrichment-cache row showed a green dot beside "Unknown".** The dot was hardcoded
  `'ok'`, but the count is null precisely when the capture table is missing or the query threw
  — so the row reported success for the exact partially-installed state the tab exists to
  reveal. `getDiagnostics()` now returns `enrichmentAvailable` (readable vs not, distinct from
  a legitimate count of 0) and the dot derives from it.

## [1.9.2] — 2026-09-01

Review fixes, plus ASN database maintenance fixes.

### Fixed
- **Dashboard widget scope leaked into other bundles' widgets.** `onWidgetDetailGenerate()`
  folded `bf_scope` into the widget's params before checking whether the widget was one of
  ours. Core dispatches `DETAIL_GENERATE` to every subscriber and not every earlier subscriber
  `stopPropagation()`s for the type it handled, so other bundles' widgets such as
  `email.sent.read.count` and `campaign.leads.added` received `bf_scope`. Widget params
  form part of core's cache key, so unrelated widgets were partitioned per ownership scope —
  defeating their shared cache for every viewer — and their handlers were handed a parameter
  they never declared. Now returns immediately unless `$event->getType()` is in `$this->types`.
- **`mautic:botfilter:update-asn-db` failed silently.** The download used an error-suppressed
  `@file_get_contents()` and reported only a generic "Download failed (check license key /
  network / GeoLite2 entitlement)", with no HTTP status and no MaxMind reason string, so a
  failing run — scheduled or manual — gave nobody anything to act on. It now sends
  `ignore_errors` so MaxMind's own plain-text reason survives a 4xx, parses the **last**
  status line out of `$http_response_header` (the legacy endpoint redirects, so the first status line is not
  the final one), and distinguishes
  no-response / non-2xx / empty-body. A bad key now reports
  `Download failed: HTTP 401 from download.maxmind.com — Invalid license key.`
- **Unchecked `copy()` reported success on a failed write.** If the destination
  `GeoLite2-ASN.mmdb` could not be written (e.g. owned by another user), the command still
  printed `[OK] … updated` and exited 0. The copy is now checked, and the data dir is
  verified writable up front.
- **One empty `/tmp` file leaked per run.** `tempnam()` creates its file, but the code
  appended `.tar.gz` to the returned path and only ever unlinked the suffixed copy, so the
  original 0-byte reservation was orphaned every time. Both paths are now cleaned up on every
  exit path. (The `.tar.gz` suffix itself is required — `PharData` infers the compression
  format from the filename.)

### Notes
- The command intentionally stays on MaxMind's legacy `/app/geoip_download` endpoint, which
  authenticates with the license key alone. Core Mautic's `MaxmindDownloadLookup` (7.1.3 and
  later) uses the newer
  `/geoip/databases/<edition>/download` endpoint, which requires HTTP Basic
  `accountId:licenseKey`. A comment in the command records this. This command accepts
  `ip_lookup_auth` either as a bare license key or as `accountId:licenseKey` (it uses the
  part after the colon). Core's own GeoLite2-City download, however, needs the
  `accountId:licenseKey` form — if `ip_lookup_auth` lacks the numeric account ID, core's
  download fails with HTTP 401 even though this command succeeds.
- **The refresh must be scheduled.** The cron entry is documented, not auto-installed, and
  without one `GeoLite2-ASN.mmdb` silently goes stale. Deployments must add an entry
  themselves; see the README. Twice weekly is recommended rather than weekly because MaxMind
  rebuilds GeoLite2 on Tue/Fri and this plugin flags the database stale past 7 days, so one
  missed weekly run trips the warning.

## [1.9.1] — 2026-09-01

Review fixes. No behaviour changes beyond the defects listed.

### Fixed
- **Diagnostics reported "installed" with only one of the two capture tables present.**
  `(bool) $conn->fetchOne(...) >= 2` casts before comparing, so `true >= 2` evaluates
  `true >= true` — any non-zero count passed. A partially installed or partially migrated
  instance therefore saw a green "Active" row precisely when it needed to be told to rerun
  `mautic:botfilter:install`. Now casts to `(int)` first.
- **Paginated lists could duplicate or skip rows between pages.** Neither the admin list nor
  the contact drill-down had a unique tie-breaker in its `ORDER BY`, and ties are the norm
  here (many contacts share a single hit count). MySQL
  may order tied rows differently per query, so a row could appear on two pages and another
  on none. New `buildOrderBy()` helper appends `h.lead_id` (list) / `id` (drill-down).
- **Sorting the list by Contact ignored the chosen direction on the surname.** `ORDER BY a, b
  DESC` applies `DESC` to `b` only, so `ORDER BY l.lastname, l.firstname DESC` sorted
  surnames ascending in both directions. `SORT_MAP` now holds an expression *list* and
  `buildOrderBy()` applies the direction to every expression.
- **Provider rail went stale after a live search.** The rail renders outside the `.page-list`
  ajax-swap target (like the health strip), so a search-scoped rollup could never be
  refreshed — the previous filter's counts sat beside the new result set. The rollup is now
  install-wide, matching the rail's "Scanners hitting you" heading. The ownership clamp is
  still applied, so a viewown-only user's rail never counts hits on contacts they cannot see.
- **Dashboard widgets were denied to a `viewother`-without-`viewown` role** that the admin
  page itself allows. Core reads one `$permissions` array through two gates with opposite
  semantics — `WidgetDetailEvent::hasPermissions()` is OR, `WidgetTypeListEvent::hasPermissions()`
  is AND — so both permissions are now listed (correct for the render gate) and
  `onWidgetListGenerate()` is overridden to apply OR on the "add widget" picker path.
- **Duplicate nested `class="page-list"`** in both list templates: the ajax fragment repeated
  the class of the wrapper it is swapped into, so two elements matched the swap target. Core's
  own list templates put the class on the wrapper only.
- **Dashboard "daily breakdown" labelled every bucket one day late for ~10 hours of every
  day.** `DateHelper::toDate()`'s third argument is the *input* format, not the output format,
  and `DateTimeHelper::setDateTime()` parses with `createFromFormat()` **without** a leading
  `!` — so the unspecified time fields came from the current wall clock and the result was then
  shifted UTC → site timezone. With a site timezone ahead of UTC (e.g. UTC+10), any render in
  the last hours of the UTC day named the following day. Replaced `fmtDate()` with `fmtDay()`, which
  formats the UTC day bucket with no timezone conversion.
- **Drill-down crashed on a hit recorded with no IP.** `{% if h.enrich.asn %}` was the only
  unguarded access in a cell where every sibling guards for `enrich` being `null` — and
  `enrich` *is* null whenever `ip` is null (nullable column, and both writers pass a nullable
  value). With Twig `strict_variables` enabled that throws; on the ajax sort/search/page paths
  the exception is raised in a sub-request, so it surfaces as a **200** whose payload is the
  error page, with no 500 at the edge to alert on. Now `{% if h.enrich.asn ?? null %}`.
- **"Scanner" column and "Behind X" badge were non-deterministic on ties.** Neither
  `fetchTopProviderByLead()`'s window function nor `buildContactStats()`'s `LIMIT 1` had a
  tie-breaker, so a contact whose hits split evenly across two providers got an arbitrary
  winner that could differ per execution — and the two resolved the tie independently, so the
  drill-down badge could name a different provider than the row just clicked. Both now break
  ties on provider name.
- **"Bot hits filtered — total" hid its own ownership clamp.** The query is owner-clamped but
  the sub-line was hardcoded to the unclamped string, so a viewown-only user saw a much smaller
  "total, last 30 days" than an admin with nothing on the tile accounting for it. Now uses the
  same `isOwnerClamped()` sub-line as the sibling widget.
- **Honeypot health tile read "0 allowed" when the honeypot applies to *every* email.** A blank
  allowlist means all emails (`HoneypotInjectionSubscriber::emailAllowed()` returns true on a
  blank setting, and the field's help text says so) — the tile stated the inverse, on the one
  tile where a misread has a deliverability consequence. The allowlist and ASN-age strings are
  now pluralised via Symfony's interval message format, with a dedicated zero case ("All
  emails" / "Updated today"); this also fixes "1 days old".

### Changed
- **Install-level panels are no longer computed on ajax fragment requests.** The health strip,
  provider rail and contact identity header render only inside the templates' `isIndex` branch,
  but their queries ran on every `tmpl=list` request — every live-search keystroke, sort click
  and page/limit change — and the markup was discarded. `fetchProviderRollup()` alone is two
  full scans of `botfilter_blocked_hits`. The list fragment now runs 4 fewer queries, and so
  does the drill-down fragment.

## [1.9.0] — 2026-09-01

### Added
- **Settings form redesign**: the plugin's
  integration config screen now uses a custom `getFormTemplate()` (core's default template
  only supports its own fixed Details/Features/Field-Mapping tabs, with no mechanism for
  arbitrary tab names) with three named tabs — **Detection**, **Honeypot**, **Diagnostics**
  (see `docs/ui-spec.md`).
- **Detection tab**: a `getFormNotes('features')` alert explaining the master switch in
  plain language, styled by published state (success when published, neutral otherwise);
  the two threshold fields side by side; a dashed-border "echo" sentence restating the
  current values in plain English ("Flag a hit as a scanner when... **N** or more distinct
  IP addresses within **W** seconds").
- **Honeypot tab**: a two-line description of what the feature injects, and a required
  (not decorative) warning to seed-test and confirm deliverability before enabling broadly.
- **Diagnostics tab**: read-only status rows — capture tables installed, GeoLite2-ASN
  database age (stale badge past 7 days, using the health strip's dot/threshold
  convention), IP enrichment cache row count. New `getDiagnostics()` method reuses
  `AbstractIntegration`'s already-injected `$em`/`$pathsHelper` rather than adding new
  service wiring.
- **Plugin icon** (`Assets/img/intellectitbotfilter.png`) — previously missing, so
  Settings → Plugins showed core's generic icon. A flat `#4e5e9e` square with a white
  blocked-circle mark. The filename is the lowercased integration name
  (`intellectitbotfilter.png`), which is what core's `AbstractIntegration::getIcon()` looks for.

### Changed
- No config keys renamed or migrated — `appendToForm()`'s field definitions are unchanged;
  only the surrounding template/tabs/copy changed.

## [1.8.0] — 2026-09-01

### Fixed
- **Security: missing ownership check on dashboard widgets.** Users with only `lead:leads:viewown` could see contact details (names, emails)
  for contacts they do not own in the bot-filter dashboard widgets, inconsistent with the
  admin pages' v1.5.1 ownership fix. Fixed in 1.8.0; upgrade recommended. All four widgets
  now apply the same ownership clamp as `BotFilterController::indexAction`
  (`l.owner_id = :uid` when the viewer lacks `lead:leads:viewother`). `$permissions` is set to `['lead:leads:viewown']` (a single value,
  not both) — listing both would have hidden the widgets from the "add widget" picker for a
  viewown-only user, since that gate uses AND logic while the render-time gate uses OR.

### Changed
- Removed the duplicate title on both number-tile widgets: they rendered their own inner
  `label` text that duplicated the core-drawn widget title from `mautic.widget.botfilter.*`.
  `number.html.twig` now shows only the value and one sub-line describing the time window
  (and, for "Contacts affected", whether the ownership clamp is active — "Last 30 days ·
  contacts you own" for a viewown-only viewer).
- `headItems` on both table widgets are now translation keys, matching how
  `@MauticCore/Helper/table.html.twig` already treats them (it runs headers through `|trans`
  but never translated body cell values) — the daily-breakdown table's reason column is now
  explicitly translated via `WidgetDetailEvent::getTranslator()`, since returning a raw key
  for a body cell would have rendered untranslated.
- The daily-breakdown table's reason column shows the same translated label as the list/
  drill-down pages, not the shared coloured badge component — core's `table.html.twig` has no
  raw-HTML cell type (only a `link` type or an auto-escaped scalar), so a badge here would
  require a bespoke widget template.
- All widget number tiles now load `Assets/css/bot-filter.css` and use the same design tokens
  (`.type-heading-06`, `.type-label-01`) as the list/drill-down pages.

## [1.7.0] — 2026-08-31

### Added
- **Contact drill-down redesign**: real pagination
  (route now `/botfilter/contact/{leadId}/{page}`, separate `mautic.botfilter.contact`
  session namespace so paging a contact doesn't reset the list's own page), column sort
  (date/IP/reason), search (bound against IP/URL), and a reason filter (bound `=`, validated
  against the four known codes).
- **Pagination now cuts the row set before enrichment** — a contact with hundreds of hits
  previously had every IP enriched on every view regardless of page; now only the current
  page's IPs are enriched.
- **Identity header**: avatar, name/email, aggregate stats (total hits/distinct IPs/last seen
  across ALL of the contact's hits, not just the current page), and two inference badges —
  "Behind {provider}" when one provider accounts for at least half of all hits, "Honeypot
  confirmed" when any hit has that reason.
- Datacenter badge on the hits table now uses the shared badge component introduced in 1.6.0
  instead of a bare Bootstrap label.
- Reasons/i18n cleanup: the old `REASON_LABELS` constant (English strings baked into the
  controller) is removed — reason display now routes through the same
  `mautic.botfilter.reason.*` translation keys the list page uses.

## [1.6.0] — 2026-08-31

### Added
- **Admin list page redesign**: real Mautic-standard
  pagination (route now `/botfilter/{page}`, core's ajax `tmpl`/`page-list` fragment split),
  column sort (contact, hits, IPs, last seen — via a hardcoded whitelist map, never raw
  interpolation), and search (bound `:search` parameter against contact name/email).
- **Health strip** — four tiles on the list page: protection state, honeypot state +
  allowlist count, ASN database freshness, and last-capture time. Each read is independent
  with its own logged error handling, so one failure never blanks the whole strip.
- **Provider rollup rail** — ranked bar list of the top scanning providers across all
  filtered hits (install-level aggregate, no contact identity).
- **Reasons and Scanner columns** on the list page: per-contact reason badges and the
  contact's top-hitting provider, computed only for the current page's rows (bounded query
  cost via a `ROW_NUMBER()` window-function query, not a per-row correlated subquery).
- New `IpEnricher::asnDbInfo()` — filesystem stat (no query) for the ASN database's
  presence/age, used by the health strip.
- New `Assets/css/bot-filter.css`, the plugin's first stylesheet, using Mautic 7 core's own
  CSS custom properties and utility classes (Carbon-derived design tokens) — no third-party
  CSS/JS.

### Changed
- List query's `GROUP BY` now includes the selected `leads` columns (was relying on
  `ONLY_FULL_GROUP_BY` being off, a non-default MySQL 8 setting).
- "Last seen" now uses `date_hit` coalesced to `date_captured`, consistent with what the
  drill-down page shows from 1.7.0 (was `date_captured` only, which could disagree
  with the drill-down's per-row dates).
- The list query's silent `catch (\Throwable $e) {}` now logs via the `mautic` channel
  logger instead of swallowing the exception — a broken query no longer renders
  indistinguishably from "no bot hits recorded yet".
- Hardcoded list-page UI strings moved to `Translations/en_US/messages.ini`.

## [1.5.4] — 2026-06-15

### Changed
- **DI-rebuild hardening on `VelocityBotRatioHelper` and `DashboardSubscriber`.** Both had
  required constructor args; when a new arg was added (e.g. `ConfigProvider`, `DateHelper`),
  the brief window during `cache:clear` — when the class file on disk already has the new
  signature but the *compiled* DI container still calls the old arity — threw
  `ArgumentCountError` and 500'd the live email tracker / cron consumers until the container
  finished rebuilding. (Self-healing transients tied to old container hashes, never the live
  one, but noisy in the logs.) All injected deps are now nullable with null defaults so a stale
  container degrades gracefully: `VelocityBotRatioHelper` falls back to core bot detection when
  partially wired; `DashboardSubscriber` routes `DateHelper` through `fmtFull()`/`fmtDate()`
  (raw-value fallback) and its `Connection` calls were already try/catch-guarded. No behaviour
  change when fully wired.

## [1.5.3] — 2026-06-15

### Fixed
- **Dates now render in the instance timezone, not raw UTC.** The admin drill-down panels
  (`/s/botfilter` affected-contacts list + per-contact hit history) and the dashboard table
  widgets showed `date_captured` / `date_hit` as the raw stored UTC string, labelled "(UTC)" —
  offset from local time in any non-UTC timezone and inconsistent with every other date in the Mautic UI. Now
  formatted through core's `DateHelper` (`dateToFull` in Twig, `mautic.helper.twig.date` injected
  into `DashboardSubscriber`) with `'UTC'` as the source timezone, so they convert to the logged-in
  user's (or default) timezone and the instance's configured date format. The "(UTC)" column labels
  were dropped. Storage stays UTC (`gmdate`); display only. The day-bucket widget keeps its UTC
  `DATE()` grouping (a date-only bucket can't be unambiguously shifted) — formatting only.

## [1.5.2] — 2026-06-15

### Changed
- **Clearer dashboard widget names.** The two "(last 30 days)" widgets differed only by word
  order ("Filtered bot hits" vs "Bot hits filtered") and were easy to confuse. Renamed to say
  what each shows: *Bot hits filtered — total* (headline number), *Bot hits filtered — daily
  breakdown* (per-day/reason table), *Contacts affected by bots*, *Top contacts affected by bots*.
  Number-widget sub-labels aligned to match.

## [1.5.1] — 2026-06-15

### Fixed
- **Security: missing ownership check on the admin page.** Users with only
  `lead:leads:viewown` could view bot-hit data for contacts they do not own. Fixed in 1.5.1;
  upgrade recommended. Now clamped: the list
  filters `l.owner_id = <current user>` unless the user has `viewother`, and the per-contact
  drill-down enforces `hasEntityAccess('lead:leads:viewown','lead:leads:viewother', owner_id)`
  (→ 403 otherwise). Matches Mautic's contact-list ownership semantics.

## [1.5.0] — 2026-06-15

### Added
- **IP enrichment.** `Helper/IpEnricher` enriches each filtered IP with geo (country/city, reused
  from core's MaxMind `ip_addresses.ip_details`) + ASN/organization + a cloud-provider/datacenter
  flag from MaxMind's free GeoLite2-ASN database, cached in a new `botfilter_ip_enrichment` table.
  Commands: `mautic:botfilter:update-asn-db` (downloads GeoLite2-ASN with the existing MaxMind
  license), `mautic:botfilter:enrich-ips` (pre-warm).
- **Dashboard widgets:** "Bot hits filtered (last 30 days)", "Contacts affected (last 30 days)",
  "Top affected contacts" (links to the admin page).
- **Admin page** `/s/botfilter` (left nav under Channels, access `lead:leads:viewown`/`viewother`):
  affected-contacts list with per-contact tally, and a drill-down showing every filtered date /
  IP / reason with geo + ASN/datacenter enrichment per IP.
- **Retention:** `mautic:botfilter:prune --older-than=<days>` (dry-run default; `--apply` to delete)
  trims old hits + orphaned enrichment rows.
- `Tests/IpEnricherTest.php` (5 checks).

### Changed
- `mautic:botfilter:install` now also creates `botfilter_ip_enrichment` and adds `bf_lead` /
  `bf_captured` indexes to `botfilter_blocked_hits` (idempotent, guarded ALTERs).

## [1.4.1] — 2026-06-15

### Fixed
- **Dashboard widget category showed a raw translation key** (`mautic.botfilter.dashboard.widgets`)
  in the "add widget" dropdown. Core builds two keys per widget — the category group
  (`mautic.<bundle>.dashboard.widgets`) and the type (`mautic.widget.<type>`) — and only the
  latter was defined. Added the category label ("IIT Bot Filter").

### Changed
- Widget table: friendly reason labels (e.g. `burst-open` → "Open burst (scanner)"), header
  "Date / Reason / Count". Unknown reason codes fall through to the raw value.

## [1.4.0] — 2026-06-15

### Changed
- **The Published toggle is now the master switch.** Published = the burst signal runs on opens
  AND clicks + honeypot per checkbox + your thresholds. **Unpublished = standard Mautic only**
  (core's built-in detection still runs on both channels with its weaker signals; no burst
  signal, no honeypot, no capture). Previously the burst signal ran regardless of publish state;
  now unpublishing cleanly reverts to stock behaviour. The plugin does not publish the
  integration automatically: publish it on Settings → Plugins to keep burst protection
  running. Defaults are honeypot off, 3 IPs / 30 s.
- Added `ConfigProvider::isEnabled()` (true only when the integration is published); the
  decorator delegates straight to core when disabled. Integration `getDescription()` explains the
  toggle. Config-field tooltips clarified.

## [1.3.1] — 2026-06-15

### Added
- Concise hover tooltips on all four config fields (honeypot enable, allowlist, burst
  distinct-IP threshold, burst window) explaining what each does and the default/clamp behaviour.

## [1.3.0] — 2026-06-15

### Added
- **Configurable Plugins card.** The bundle now registers a settings-only integration, so it
  appears on Settings → Plugins as "IIT Bot Filter" with a config form: honeypot on/off,
  honeypot email-ID allowlist, burst distinct-IP threshold, and burst window (seconds).
  Settings persist in `plugin_integration_settings`.
- `Tests/ConfigProviderTest.php` (11 pure-unit checks of the settings reader).

### Changed
- **UI is now the source of truth** for honeypot + thresholds, read via a memoised,
  exception-safe `ConfigProvider`. **Publish the integration to activate your settings;**
  while unpublished the plugin uses the prior defaults (honeypot off, 3 IPs / 30 s). Burst
  protection itself is always on regardless of publish state.
- Retired the `MAUTIC_BOTFILTER_HONEYPOT_*` env vars / `parameters` block (superseded by the UI).

## [1.2.2] — 2026-06-15

### Fixed
- **Dashboard widget 30-day bound now UTC.** `date_captured` is stored UTC (`gmdate`), but the
  widget's `-30 days` lower bound was built in the app timezone, skewing
  the window edge by the tz offset. Build the bound with `gmdate` so it matches the column.
  Cosmetic (audit widget).

## [1.2.1] — 2026-06-15

### Fixed
- **Honeypot route 500 (release blocker).** `HoneypotController` is a plain class (does not
  extend `AbstractController`), so Symfony autoconfigure never tagged it
  `controller.service_arguments` — the route resolver could not fetch it from the container
  and `/bf/honeypot` returned 500. Registered the controller explicitly (public + tagged) in
  `Config/services.php`.
- **Honeypot now always returns 204.** An array query param (`?h[]=x`) made `InputBag::getString()`
  throw `BadRequestException` → 400. Read via `->all()` instead, which never throws; a non-string
  value is treated as empty. Removes the only distinguishable-status edge.
- **Burst evidence queries hardened.** `clickBurstEvidence` / `openBurstEvidence` run raw SQL on
  the live open/click path; a transient DB error now degrades to "not a burst" instead of
  surfacing as a 500 on the tracker.

## [1.2.0] — 2026-06-15

### Added
- **Capture (retain, don't drop).** New `botfilter_blocked_hits` table (created by
  `mautic:botfilter:install`) + exception-safe `BlockedHitRecorder`. The decorator now
  records every hit it classifies — `burst-open` / `burst-click` (with `distinct_ip_count`,
  `trigger_ips`, `window_seconds` evidence) and `core-3signal` — with channel + click URL
  detected from the request, contact email domain, stat id and tracking hash. Core metrics
  stay clean (hits are still dropped); the bot hit is retained for audit/analysis. The
  capture path is fully exception-isolated so it can never break a live open/click.
- **Honeypot (forensic, deterministic).** Config-gated (`MAUTIC_BOTFILTER_HONEYPOT_ENABLED`,
  default off; optional `MAUTIC_BOTFILTER_HONEYPOT_EMAIL_IDS` allowlist) `EMAIL_ON_SEND`
  subscriber injects a hidden, non-trackable (`data-mautic-disable-tracking`) link to the
  public `/bf/honeypot` route, which records a `reason=honeypot` row — a deterministic
  "this contact's message was machine-scanned" signal. The route always returns 204 (no
  hash-enumeration oracle; array params coerced via `getString`).
- **Dashboard widget** "filtered bot hits" — counts by day + reason from `botfilter_blocked_hits`.

### Note
- `botfilter_blocked_hits` records only hits that reach the decorator. Hits dropped earlier
  by core (`do_not_track_bots` UA list, device-detector, HEAD/prefetch/DNT/GPC) are not
  captured — the table is "hits the BotFilter decorator classified," not every bot hit.

## [1.1.2] — 2026-06-15

### Fixed
- **Burst signal now fires on opens.** `isBurstScanner` was querying only `page_hits`,
  where email opens are never recorded (opens live in `email_stats_devices`), so scanner
  opens — the larger inflation surface — went undetected live. Split into `isClickBurst`
  (`page_hits`) + `isOpenBurst` (`email_stats_devices` by `stat_id`), OR-ed, sharing one
  UTC window.
- Excluded `Tests/` from the service-autoload glob — the standalone test scripts were being
  executed at every container compile (`cache:clear`).

## [1.1.1] — 2026-06-14

### Added
- `Tests/LogicTest.php` (16 pure-logic checks) and `Tests/IntegrationTest.php` (7 runtime
  checks against the live decorated service, rolled back — expanded to 10 in v1.1.2) plus
  `Tests/README.md`. Document and reproduce the end-to-end validation of the burst signal
  and the recount algorithms.

### Fixed
- **Live burst signal was inert (timezone bug).** `VelocityBotRatioHelper` built the
  window lower bound in the app timezone while `page_hits.date_hit` is stored in UTC, so on
  any instance with a timezone ahead of UTC `date_hit >= :since` matched nothing and the
  signal never fired.
  Now formats the bound in UTC. Fail-safe previously (no false positives), but the live
  filter provided no protection. (The retroactive recount command
  was unaffected — it uses same-column `strtotime` diffs where the offset cancels.)
- `mautic:botfilter:recount`: an unparseable `open_details` blob now skips the stat with
  a warning instead of risking a zeroed `open_count`. (Defensive; none seen in practice.)

## [1.1.0] — 2026-06-14

### Added
- `mautic:botfilter:recount` console command — retroactively removes scanner-inflated
  historical stats using the same burst signature as the live decorator. Corrects
  `email_stats` opens (open_count / is_read / date_read / last_opened + the serialized
  `open_details` blob, and deletes the bot `email_stats_devices` rows), re-derives
  `emails.read_count`, and re-derives `channel_url_trackables` hits/unique_hits from the
  surviving `page_hits` (Mautic semantics: `hits = COUNT(*)`,
  `unique_hits = COUNT(DISTINCT tracking_id)`). Dry run by default; `--apply` to write.
  Options: `--window` (default 30), `--min-ips` (default 3), `--skip-trackables`.

## [1.0.0] — 2026-06-14

### Added
- `VelocityBotRatioHelper` — decorates the core `Mautic\EmailBundle\Helper\BotRatioHelper`
  with a list-free burst/velocity signal. Flags an email open or click as a bot
  when the same email + contact has already been hit from `maxDistinctIps` (default 3)
  or more distinct IP addresses within `windowSeconds` (default 30). Catches cloud
  link-scanning security services (Defender SafeLinks, Mimecast, Proofpoint, Barracuda)
  that evade all three core signals (time-from-send, IP blocklist, UA blocklist).
- Applies to both tracking paths — `EmailModel::hitEmail` (opens) and
  `PageModel::hitPage` (clicks) — via service decoration; no core files changed.
- `docs/upstream-issue.md` and `docs/upstream-patch.diff` — proposed core fix for
  submission to `mautic/mautic`.
