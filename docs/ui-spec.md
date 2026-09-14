# BotFilter UI Spec

Reference for the BotFilter admin UI (the list page, contact drill-down, dashboard widgets and
settings modal): tokens, components, per-surface layout, states, and the string keys each surface
uses. Section references (§) point to sections within this document. Data flow, SQL rules and
permission handling behind these surfaces are described in `docs/architecture.md`.

Everything here is expressed in **Mautic 7 core primitives**. No third-party CSS/JS. One plugin
stylesheet, `Assets/css/bot-filter.css`.

---

## 1. Design tokens

Do not invent colours. Mautic 7's light theme defines Carbon-derived custom properties on `:root`
(`app/bundles/CoreBundle/Assets/css/app/scss/_variables.scss`). Use the variables, not the hexes —
they are listed here only for readability, and so dark/solarized themes inherit correctly.

| Role | Variable | Light value |
|---|---|---|
| Page background | `--background` / `--layer-01` | `#ffffff` / `#f4f4f4` |
| Panel surface | `--layer-02` | `#ffffff` |
| Hover row | `--layer-hover-01` | `#e8e8e8` (this design uses `--background-hover` `#f1f1f1`) |
| Hairline | `--border-subtle-01` | `#e0e0e0` |
| Field underline | `--border-strong-01` | `#8d8d8d` |
| Primary text | `--text-primary` | `#161616` |
| Secondary text | `--text-secondary` | `#525252` |
| Helper text | `--text-helper` | `#6f6f6f` |
| Brand / links / active page | `--interactive`, `--link-primary` | `#4e5e9e` (`$mautic-primary`) |
| Success | `--support-success` | `#24a148` |
| Warning | `--support-warning` | `#f1c21b` |
| Error | `--support-error` | `#da1e28` |
| Info | `--support-info` | `#0043ce` |

**Radius: 0.** `$border-radius: 0px` in `components/_brand.scss`. No rounded corners anywhere except
the 50% status dots.

**Spacing:** core's `--spacing-*` scale. In practice this design uses only 4 / 8 / 12 / 16 / 24 px.

**Type scale** (core tokens):

In the built `media/css/app.css`, none of these exist as a bare custom property. Each is only
defined as four suffixed properties (`--<token>-font-size`, `-line-height`, `-font-weight`,
`-letter-spacing`) — e.g. there is no `--heading-03`, only `--heading-03-font-size` etc. Core also
ships a ready utility class per token (`.type-heading-03`, `.type-heading-06`,
`.type-heading-compact-01`, `.type-body-compact-01`, `.type-label-01`) that already applies all
four. **Use the utility class** on the element rather than referencing a token name directly in the
plugin CSS.

| Use | Utility class | Size / line / weight |
|---|---|---|
| Page title | `.type-heading-03` | 20 / 28 / 400 |
| Number tile value | `.type-heading-06` | 42 / 50 / 300 |
| Drill-down stat value | `.type-heading-04` | 28 / 36 / 400 |
| Panel heading, table header | `.type-heading-compact-01` | 14 / 18 / 600 |
| Body, table cell | `.type-body-compact-01` | 14 / 18 / 400 |
| Helper, meta, badges | `.type-label-01` | 12 / 16 / 400, `letter-spacing: .32px` |

**Numerals:** every count, IP-count and date cell gets `font-variant-numeric: tabular-nums`.

**Monospace:** IP addresses and console commands only — `ui-monospace, SFMono-Regular, Menlo, monospace`.

**Icons:** Remixicon, already shipped by core. Used in this design: `ri-search-line`, `ri-filter-line`,
`ri-arrow-up-down-line` / `ri-arrow-up-line` / `ri-arrow-down-line` (sort), `ri-arrow-left-double-line`
and friends (pagination), `ri-server-line` (datacenter badge), `ri-radar-line` (empty state),
`ri-information-line` (capture note), `ri-alert-line` (honeypot warning).

**Menu icon: none.** The entry is a plain text item reading **Bot Filter** under Channels. Channels'
other children carry no icon at this level, and a shield glyph would read as a Mautic-core security
feature rather than a plugin.

---

## 2. Shared components

### 2.1 Badge — reason

One shared class, four modifiers. 20px tall, 8px horizontal padding, 12px/.32px text, square.

| Reason code | Label key | Background | Text |
|---|---|---|---|
| `burst-open` | `mautic.botfilter.reason.burst_open` → "Open burst" | `--label-background-purple` `#e8daff` | `--label-color-purple` `#6929c4` |
| `burst-click` | `mautic.botfilter.reason.burst_click` → "Click burst" | `--label-background-blue` `#d0e2ff` | `--label-color-blue` `#0043ce` |
| `core-3signal` | `mautic.botfilter.reason.core_3signal` → "Core signals" | `--label-background-gray` `#e0e0e0` | `--label-color-gray` `#161616` |
| `honeypot` | `mautic.botfilter.reason.honeypot` → "Honeypot" | `--label-background-teal` `#9EF0E5` | `--label-color-teal` `#005C59` |

Unknown codes fall back to the gray modifier with the raw code as text (forward-safe). Labels are
translation keys defined once in `messages.ini`; the Twig templates and `DashboardSubscriber` both
resolve them from there.

### 2.2 Badge — datacenter

`--notification-background-warning` `#fcf4d6` fill, 1px `--support-warning` border, `#684e00` text,
`ri-server-line` + label. Shown only when `enrich.is_datacenter` is truthy; otherwise the badge is
omitted (the Organisation cell still shows the provider or org name, or an em dash when neither is
known).

### 2.3 Number tile

Centred, 28px vertical padding. Value in `.type-heading-06`, `tabular-nums`. **One** sub-line in
`.type-label-01` `--text-helper` giving the *window and scope* ("last 30 days", "last 30 days ·
contacts you own"). Do not print the metric name inside the tile — core already draws the widget
title.

### 2.4 Health tile (list page only)

Label (`.type-label-01`, `--text-helper`) / status row (8px dot + `.type-heading-03` value) / optional
meta line (`.type-label-01`, `--text-helper`). Four tiles in a 1px-gapped grid on a
`--border-subtle-01` background, which produces the hairline separators without per-tile borders.

Dot colours: `--support-success` healthy, `--support-warning` degraded/stale, `--border-strong-01`
off-by-choice or unknown (honeypot disabled is not an error). The last-capture tile is informational:
no dot, value in `.type-body-compact-01`.

| Tile | States |
|---|---|
| Protection | "Active" (success dot) / "Inactive" (neutral dot) |
| Honeypot | "On" (success dot) with an allowlist meta line / "Off" (neutral dot, no meta line) |
| ASN database | "Missing" (neutral dot) when the `.mmdb` is absent; "Unknown" (neutral dot) when its mtime cannot be read; "Stale" (warning dot) past 7 days; otherwise "Fresh" (success dot). Age meta line whenever the age is known. |
| Last capture | Localised date of the most recent capture, or "Unknown" when there are none or the read failed |

No tile ever renders blank or surfaces an exception.

### 2.5 Table

Header cells: `.type-heading-compact-01`, 12/16px padding, bottom hairline. Sortable headers are core's
`@MauticCore/Helper/tableheader.html.twig` — a `btn btn-ghost btn-block jc-space-between` filling the
cell, label left, sort icon right (`ri-arrow-up-down-line` unsorted `--border-strong-01`,
`ri-arrow-up-line`/`ri-arrow-down-line` active `--text-primary`), active column tinted
`--background-hover`. Non-sortable headers are a plain padded `<span>` — same metrics, no button.

Body rows: 10/16px padding, bottom hairline, `--background-hover` on row hover. Rows are not
clickable as a whole; on the list page the contact name is the link to the drill-down. Contact cell
is two lines — name (link), email in `.type-label-01` `--text-helper`.

### 2.6 Toolbar

Core's `@MauticCore/Helper/list_toolbar.html.twig` (which wraps `search.html.twig`): panel heading
with the search input. The drill-down renders one reason `<select>` (class `not-chosen`, so core does
not convert it to a Chosen widget) in its own row directly below the toolbar; changing it reloads the
`.page-list` fragment with `reason=<code>`. Placeholder is `mautic.core.search.placeholder` — do not
define a plugin key for it.

### 2.7 Pagination

Core's `@MauticCore/Helper/pagination.html.twig`, unmodified: limit `<select>` right, page list centred,
`«  ‹  1 2 3  ›  »` square 34px cells, active page filled `--interactive`, disabled arrows
`--border-disabled`. Footer line is core's own
`mautic.core.pagination.items|pages|total` composite. It requires `totalItems, page, limit, sessionVar,
baseUrl` — all supplied by the controller.

Session vars: `botfilter` (`mautic.botfilter.*`) for the list, `botfilter.contact`
(`mautic.botfilter.contact.*`) for the drill-down — separate, so paging a contact doesn't reset the
list page.

---

## 3. Surface 1 — admin list (`/s/botfilter/{page}`)

Vertical order: page title + subtitle → health strip → two-column body (table panel left, rail right).
Rail is 320px and collapses under the table at `@media (max-width: 768px)` — core has no
`--screen-md` custom property; 768px is core's own `md` breakpoint as a literal value.

Subtitle: "Engagement discarded as cloud link-scanner traffic, by contact."
(`mautic.botfilter.list.description` — one line, states what the page *is*.)

**Health strip** — four tiles, §2.4.

**Table columns**

| Column | Sortable | Sort key → SQL (whitelist) | Notes |
|---|---|---|---|
| Contact | yes | `contact` → `l.lastname, l.firstname` | two-line cell; name links to drill-down |
| Hits | yes, **default DESC** | `hits` → `hits` | tabular |
| IPs | yes | `ips` → `ips` | tabular; distinct IPs |
| Reasons | no | — | badge set, wraps |
| Scanner | no | — | top provider for that contact, `.type-label-01`; "Unclassified" when its hits have no enriched provider; em dash if the lookup returned nothing |
| Last seen | yes | `lastseen` → `last` | `dateToFull`; `MAX(COALESCE(date_hit, date_captured))` |

Any sort key not in the map falls back to `hits`. The chosen direction applies to every expression
in the key, and `h.lead_id ASC` is appended as a unique tie-breaker so paging is stable. Search binds
`:search` against `l.firstname`, `l.lastname`, `l.email` with `LIKE`.

**Provider rollup rail** — panel headed "Scanners hitting you"
(`mautic.botfilter.list.providers.header`). Each row: provider name + count on one baseline, and a
6px `--interactive` bar on a `--border-subtle-01` track (width = share of the largest row, the
Unclassified row included — not share of total). Top 5. Hits without an enriched provider group into
one final muted "Unclassified" row rather than being dropped — otherwise the bars imply more coverage
than exists. The panel is omitted when there is nothing to show.
Counts are **install-wide** (still ownership-clamped), never scoped to the table's search term:
the rail renders outside the `.page-list` ajax-swap target, so a search-scoped value could not be
refreshed by a live search and would sit stale beside the new results. Same rule as the health strip —
everything outside the swap target is unfiltered.

Below the rail, a persistent info note (`--notification-background-info`, 3px `--support-info` left rule)
stating the capture caveat: only hits reaching the decorator are recorded
(`mautic.botfilter.list.capture_caveat`). It is shown whether or not the rollup has data, so the count
is not read as "all bot traffic".

---

## 4. Surface 2 — contact drill-down (`/s/botfilter/contact/{leadId}/{page}`)

Breadcrumb ("Bot Filter › {contact}") → identity header panel → hits table panel.

**Identity header**: 48px initials square (purple label tokens), name (`.type-heading-03`), email
(`.type-body-compact-01`, `--text-secondary`), and up to two inference badges — "Behind {provider}"
when one real provider (not Unclassified) accounts for at least half of that contact's hits (ties
broken by provider name, the same rule as the list's Scanner column), and "Honeypot confirmed" when
any `honeypot` row exists. Right side: three stats across all of the contact's hits (hits filtered,
distinct IPs, last seen) and a button to the core contact record.

**Hits table**: Date (default sort DESC; shows `date_hit`, falling back to `date_captured`) · IP (mono)
· Reason badge · Location (city, country; em dash when unknown) · Organisation (provider, falling
back to org, else em dash; ASN sub-line when known; datacenter badge §2.2) · URL (single-line,
ellipsised, `.type-label-01`; em dash for opens, which have no URL).

Sort whitelist: `date` → `COALESCE(date_hit, date_captured)`, `ip` → `ip`, `reason` → `reason`, with
`id ASC` appended as the tie-breaker. Search binds `:search` against `ip` and `url`. Reason filter is a
bound `=` on `reason`, validated against the four known codes (an unknown value means no filter).

Pagination cuts the row set **before** `enrichMany()` — the enrichment cost scales with the page, not
with the contact's history.

---

## 5. Surface 3 — dashboard widgets

Core draws the tile, header, title and actions dropdown
(`DashboardBundle/Resources/views/Widget/detail.html.twig`); everything below is *inner content only*.

1. **Bot hits filtered — total** — number tile (§2.3).
2. **Contacts affected** — number tile.
3. **Bot hits filtered — daily breakdown** — core `@MauticCore/Helper/table.html.twig`; columns
   Date / Reason / Count. `headItems` are translation keys (core runs them through `|trans`). The
   reason cell is translated plain text, not the §2.1 badge: core's table template has no raw-HTML
   cell type. Rendering the badge here would need a bespoke widget template and is a planned
   enhancement.
4. **Top contacts affected** — same core table; Contact (link to the drill-down) / Hits / Last seen
   (`MAX(date_captured)`).

Both number tiles use the scope-aware sub-line: "last 30 days · contacts you own" when the viewer is
owner-clamped, "last 30 days" otherwise. The sub-line is how the ownership clamp becomes visible
rather than silently changing the number.

**Permissions:** `$permissions` lists `lead:leads:viewown` and `lead:leads:viewother`, and the widget
picker applies them as OR (same contract as the admin page). The ownership clamp applies to **all
four** widgets' queries, the effective scope is folded into the widget params so core's shared cache
never serves one viewer's result to another, and a missing security service fails closed. See
"Widget permissions and caching" in `docs/architecture.md`.

---

## 6. Surface 4 — settings (tabbed modal)

Rendered by `PluginController::editAction` inside a modal — not a page. Core draws the modal chrome
(header with plugin icon and display name "IIT Bot Filter", footer with Cancel / Save & close); only
the body is ours, supplied by `IntellectITBotFilterIntegration::getFormTemplate()`
(`Resources/views/Integration/form.html.twig`).

Above the tabs: the integration description (`getDescription()`) in an info alert.

**Tabs**: Detection · Honeypot · Diagnostics. Implemented by the plugin's own form template, because
core's default integration template supports only its fixed tab set. The fields keep their existing
config keys (no migration).

**Detection tab**
- The Published toggle (`form.isPublished`) first.
- A `getFormNotes('features')` alert explaining the master switch in plain words. Published: burst
  signal on opens and clicks with your thresholds, plus honeypot if enabled. Unpublished: standard
  Mautic only, no burst signal, no honeypot, no capture rows. The alert variant follows state:
  `alert-success` when published, `alert-secondary` otherwise (the PHP returns `default`, which the
  template maps to `secondary`).
- Two fields side by side: distinct IPs (min 2) and window seconds, each with a help tooltip.
- A dashed-border echo line restating the current values as one sentence: *"Flag a hit as a scanner when
  the same email reaches the same contact from **N** or more distinct IP addresses within **W** seconds."*
  It is rendered server-side from the two field values (escaped), not updated live while typing.

**Honeypot tab**
- Enable toggle (`YesNoButtonGroupType`) with a help tooltip, followed by a one-paragraph description
  (`mautic.botfilter.config.honeypot.description`: what it injects, and that `/bf/honeypot` always
  returns 204).
- Email ID allowlist text field; placeholder "e.g. 12,34 — blank = all emails", help tooltip
  "Comma-separated email IDs to honeypot. Leave blank to honeypot every email. …".
- A warning alert rendered directly in the template (`alert-warning`,
  `mautic.botfilter.config.honeypot.warning`): seed-test and confirm deliverability before enabling
  broadly. The feature modifies outgoing message content.

**Diagnostics tab** — read-only status rows, delivered as HTML via `getFormNotes('custom')`: capture
tables installed, GeoLite2-ASN database age, IP enrichment cache size. Each row: status dot + label +
the console command in mono as its sub-line + a value on the right. Tables: success dot when both
tables exist, warning otherwise. ASN database: warning dot when stale (> 7 days, same threshold as the
health strip), neutral dot when missing or its age is unknown. Enrichment cache: success dot when the
count could be read, warning otherwise.

**Plugin icon**: `Assets/img/intellectitbotfilter.png` (the lowercased integration name, which core
uses to find the icon); `Assets/img/icon.png` ships alongside it. Both are 256×256 PNG: a flat
`#4e5e9e` square with a white blocked-circle mark (ring + 45° bar), no text or gradient.

---

## 7. Empty and error states

| Case | Treatment |
|---|---|
| No captures at all | Custom panel: `ri-radar-line`, "No scanners caught yet", body copy explaining this is normal and the filter is watching. **Not** core's `noresults.html.twig` — its `alert-warning` styling frames a healthy install as a problem. |
| List search returned nothing | Core `@MauticCore/Helper/noresults.html.twig` with its default keys. Warning styling is right here: the user did something they can undo. |
| Drill-down search or reason filter returned nothing | Core `noresults.html.twig` with `mautic.botfilter.empty.contact.filtered.header` / `.message`. |
| Contact has no hits | Same custom panel as "no captures", copy scoped to the contact. |
| Health-strip read failed | That tile only (§2.4): ASN "Unknown" with a neutral dot, or last capture "Unknown". Page renders. |
| Provider rollup read failed | Rail panel omitted; the capture note and the table are unaffected. |
| Hit-list query failed | Not an empty state: the failure is logged and the plugin's own `alert-danger` (`mautic.botfilter.error.query_failed`) replaces the table, so a broken query is never indistinguishable from a quiet install. |

---

## 8. Translation keys

Namespace `mautic.botfilter.*`; reuse core keys for generic UI text
(`mautic.core.search.placeholder`, `mautic.core.pagination.*`, `mautic.core.noresults*`).

```
mautic.botfilter.menu.index
mautic.botfilter.dashboard.widgets
mautic.widget.botfilter.total.blocked|blocked.hits|contacts.affected|top.contacts
mautic.botfilter.header.index
mautic.botfilter.list.description
mautic.botfilter.list.providers.header|unclassified
mautic.botfilter.list.thead.contact|hits|ips|reasons|scanner|lastseen
mautic.botfilter.list.contact_fallback
mautic.botfilter.list.capture_caveat
mautic.botfilter.error.query_failed
mautic.botfilter.health.protection|honeypot|asndb|lastcapture
mautic.botfilter.health.value.active|inactive|on|off|unknown|fresh|stale|missing
mautic.botfilter.health.allowlist_count
mautic.botfilter.health.asndb.age
mautic.botfilter.contact.thead.date|ip|reason|location|org|url
mautic.botfilter.contact.stat.hits|ips|lastseen
mautic.botfilter.contact.badge.behind|honeypot_confirmed
mautic.botfilter.contact.open_contact
mautic.botfilter.contact.reason.all
mautic.botfilter.reason.burst_open|burst_click|core_3signal|honeypot
mautic.botfilter.badge.datacenter
mautic.botfilter.empty.header|message
mautic.botfilter.empty.contact.header|message
mautic.botfilter.empty.contact.filtered.header|message
mautic.botfilter.widget.subline.30days|30days_owned
mautic.botfilter.widget.thead.date|reason|count
mautic.botfilter.config.tab.detection|honeypot|diagnostics
mautic.botfilter.config.published.note.on|off
mautic.botfilter.config.burst.ips.label|help
mautic.botfilter.config.burst.window.label|help
mautic.botfilter.config.burst.sentence
mautic.botfilter.config.honeypot.label|help|description|warning
mautic.botfilter.config.allowlist.label|help
mautic.botfilter.config.diagnostics.tables|asndb|enrichment
```

---

## 9. Implementation checklist

**List page** — `bot-filter.css` with §1 tokens and §2 components; `tmpl` index/fragment split with
the `.page-list` wrapper; `{page}` route; controller sequence with the sort whitelist and bound search;
health strip reads with their own logging try/catch; provider rollup query; empty and error states §7.

**Drill-down** — reuse the CSS and the pagination pattern; `{page}` route with a separate session
namespace; LIMIT before `enrichMany()`; identity header; reason filter.

**Widgets** — `number.html.twig` per §2.3; `$permissions` populated with the OR picker override;
owner clause on all four widget queries; scope folded into widget params; `headItems` as keys.

**Settings** — custom form template with the three tabs; `getFormNotes()` alert for the master switch
and HTML for Diagnostics; honeypot warning in the template; threshold echo sentence; plugin icon.

Any change to `Config/config.php` (routes, menu, integration service) needs `cache:clear` →
`mautic:plugins:reload` → a PHP-FPM / web server restart before the surface is considered done.
