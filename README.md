# IIT Bot Filter for Mautic

IIT Bot Filter is a Mautic 7.x plugin by IntellectIT. It adds a detection signal for email opens and clicks made by cloud email-security link scanners, such as Microsoft Defender for Office 365 Safe Links, Mimecast, Proofpoint and Barracuda. It records the hits it classifies and shows them in an admin page and dashboard widgets. It is for Mautic administrators whose open and click statistics are inflated by these scanners.

## Contents

- [Overview](#overview)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [How detection works](#how-detection-works)
- [Honeypot](#honeypot)
- [IP and ASN enrichment](#ip-and-asn-enrichment)
- [Admin page](#admin-page)
- [Dashboard widgets](#dashboard-widgets)
- [Console commands](#console-commands)
- [Cron](#cron)
- [Permissions](#permissions)
- [Data stored](#data-stored)
- [Page hit sanitisation](#page-hit-sanitisation)
- [Limitations](#limitations)
- [Uninstall](#uninstall)
- [Testing](#testing)
- [Development](#development)
- [Upstream](#upstream)
- [Changelog](#changelog)
- [License](#license)

## Overview

| Component | Location | Function |
|---|---|---|
| Bot detection decorator | `Helper/VelocityBotRatioHelper.php` | Decorates core `Mautic\EmailBundle\Helper\BotRatioHelper`. It adds a burst check based on distinct IP addresses and records the hits it classifies. |
| Hit recorder | `Helper/BlockedHitRecorder.php` | Writes one row per classified hit to `botfilter_blocked_hits`. |
| Honeypot | `EventListener/HoneypotInjectionSubscriber.php`, `Controller/HoneypotController.php` | Optional. Adds a hidden link to sent emails and records requests to it. |
| IP enrichment | `Helper/IpEnricher.php` | Looks up country, city, ASN, organisation and provider for recorded IPs, for display only. |
| Admin UI | `Controller/BotFilterController.php`, `Resources/views/BotFilter/` | Contact list and per-contact drill-down. |
| Dashboard widgets | `EventListener/DashboardSubscriber.php` | Four widgets. |
| Settings | `Integration/IntellectITBotFilterIntegration.php`, `Helper/ConfigProvider.php` | Plugin settings form and how its values are read at runtime. |
| Hit sanitiser | `EventListener/HitEncodingSubscriber.php`, `Helper/Utf8Sanitiser.php` | Fixes invalid UTF-8 and NUL bytes in page hits before they are saved. |

The plugin does not modify any Mautic core files. The decorator is registered through Symfony service decoration in `Config/services.php`.

## Requirements

| Requirement | Detail |
|---|---|
| Mautic | 7.x (`mautic/core-lib ^7.0` in `composer.json`). |
| PHP | The version your Mautic release requires. The code uses PHP 8.0+ syntax (constructor property promotion, nullsafe operator, attributes). |
| PHP extensions | `mbstring` for hit sanitisation. `phar` for `mautic:botfilter:update-asn-db`, which extracts the archive with `PharData`. |
| PHP settings | `allow_url_fopen` enabled, for `mautic:botfilter:update-asn-db`. |
| Database | MySQL 8.0+ or MariaDB 10.2+. The install command uses `information_schema`, and the admin list uses the `ROW_NUMBER()` window function. `MAUTIC_TABLE_PREFIX` is respected. |
| MaxMind licence | Optional. Needed only for ASN enrichment. The command reads it from Mautic's `ip_lookup_auth` setting. |

## Installation

1. Place the plugin at `plugins/IntellectITBotFilterBundle` in the Mautic root. The directory name must match the bundle name.
2. From the Mautic root, run these as the user that runs Mautic, so cache files keep the correct ownership:

   ```bash
   php bin/console cache:clear
   php bin/console mautic:plugins:reload
   php bin/console mautic:botfilter:install
   ```

   `mautic:botfilter:install` creates the plugin's tables and can be re-run safely.
3. In Mautic, open **Settings > Plugins > IIT Bot Filter**, set **Published** to on, and save.
4. Optional: to enable ASN enrichment, run `php bin/console mautic:botfilter:update-asn-db` and add the [cron](#cron) entry.

## Configuration

Settings are edited on **Settings > Plugins > IIT Bot Filter**. They are saved as the integration's feature settings (integration name `IntellectITBotFilter`). No environment variables or service parameters are used.

The settings form has three tabs:

| Tab | Contents |
|---|---|
| Detection | Published toggle, a note describing the current published state, the burst threshold fields, and a sentence restating the current thresholds. |
| Honeypot | Honeypot toggle, a description of the hidden link and the `/bf/honeypot` endpoint, email ID allowlist, and a deliverability warning. |
| Diagnostics | Read-only status of: both capture tables present; GeoLite2-ASN database age (stale after 7 days); enrichment cache row count. |

### Settings

| Key | Default | Meaning |
|---|---|---|
| Published (integration state) | off | The plugin's master switch. See [Published state](#published-state). |
| `max_distinct_ips` | `3` | Number of distinct IPs within the window that marks a burst. Values below `2` fall back to `3`. |
| `window_seconds` | `30` | Length of the look-back window, in seconds. Values below `1` fall back to `30`. |
| `honeypot_enabled` | off | Adds the honeypot link to outgoing HTML emails. |
| `honeypot_email_ids` | blank | Comma-separated email IDs that get the honeypot link. Blank means all emails. |

`Helper/ConfigProvider.php` reads the settings once and caches them for the lifetime of the service: once per web request, but only once for a long-running process such as a messenger worker. If the integration is missing or unpublished, or reading the settings throws, the defaults above are used and the plugin is treated as disabled.

### Published state

| State | Behaviour |
|---|---|
| Published | Core's bot check runs first, then the burst check runs on opens and clicks. Classified hits are recorded. If the honeypot is enabled, the link is added to emails. |
| Unpublished | The decorator passes every call straight to core's `BotRatioHelper`. The burst check does not run, no decorator rows are recorded, and no honeypot link is added. |

## How detection works

### Decision order

When published, `VelocityBotRatioHelper::isHitByBot()` evaluates these checks in order and returns `true` at the first match:

| Order | Check | Recorded `reason` |
|---|---|---|
| 1 | Core `BotRatioHelper::isHitByBot()`: time from send, IP blocklist and user-agent blocklist. See [docs/upstream-issue.md](docs/upstream-issue.md) for core's scoring. | `core-3signal` |
| 2 | Click burst | `burst-click` |
| 3 | Open burst | `burst-open` |

Core calls `isHitByBot()` from both email open tracking (`EmailModel::hitEmail()`) and click tracking (`PageModel::hitPage()`, for hits that have an email stat). When it returns `true`, core treats the hit as a bot hit. Hits that core rejects before calling `isHitByBot()` never reach the plugin and are not recorded.

### Burst signal

Both burst checks count distinct IP addresses already stored for the current email and contact. The window runs from `hit time - window_seconds` (UTC) with no upper bound. A burst is present when the count is at least `max_distinct_ips`.

| Check | Table queried | Filter |
|---|---|---|
| Click burst | `page_hits` joined to `ip_addresses` | `email_id`, `lead_id`, `date_hit >= window start` |
| Open burst | `email_stats_devices` joined to `ip_addresses` | `stat_id`, `date_opened >= window start` |

The burst check does not use IP lists, user-agent lists or enrichment data.

If a query fails, the check counts as "no burst". If the recorder fails, the error is logged and ignored. Neither failure interrupts tracking.

### What is recorded

Each classified hit adds one row to `botfilter_blocked_hits`:

| Column | Content |
|---|---|
| `reason` | `core-3signal`, `burst-click`, `burst-open` or `honeypot` |
| `channel` | `open` (route `mautic_email_tracker`), `click` (routes `mautic_url_redirect` or `mautic_page_redirect`), otherwise `NULL`. Also `NULL` when the hit is processed outside an HTTP request. |
| `email_id`, `lead_id`, `stat_id`, `tracking_hash` | From the email stat |
| `email_domain` | Domain of the stat's email address, or of the contact's email if the stat has none |
| `ip`, `user_agent` | Request IP, and user agent cut to 255 bytes |
| `url` | Redirect target for clicks, cut to 2048 bytes. `NULL` when the hit is processed outside an HTTP request. |
| `distinct_ip_count`, `trigger_ips`, `window_seconds` | Burst evidence; the IPs are stored as a JSON array. `NULL` for `core-3signal` and `honeypot` rows. |
| `date_hit` | Hit time |
| `date_captured` | Insert time (UTC) |

The plugin does not change core's open or click tables when a hit is detected. To correct stored statistics after the fact, use [`mautic:botfilter:recount`](#mauticbotfilterrecount).

## Honeypot

| Aspect | Behaviour |
|---|---|
| Injection event | `EmailEvents::EMAIL_ON_SEND` |
| Injection conditions | Integration published; `honeypot_enabled` on; email ID in the allowlist, or the allowlist blank; the send has an ID hash; the content contains `</body>` |
| Injected markup | `<a href=".../bf/honeypot?h=<idHash>" data-mautic-disable-tracking="true" style="display:none;..." aria-hidden="true">.</a>`, inserted before `</body>` |
| Endpoint | `/bf/honeypot?h=<hash>`, any HTTP method (public route `mautic_botfilter_honeypot`) |
| Response | Always `204 No Content`, whether or not the hash is valid |
| Recording | If the hash matches an email stat, one row with `reason = honeypot` is written |

The `data-mautic-disable-tracking` attribute stops core from rewriting the link as a tracked redirect. The honeypot changes outgoing email content, so send a seed test and confirm deliverability before enabling it for all emails.

## IP and ASN enrichment

Enrichment is used only for display: the admin page, the provider rail and the drill-down. It has no effect on whether a hit is classified as a bot.

| Field | Source |
|---|---|
| `country`, `city` | Most recent `ip_addresses.ip_details` row for the IP. Mautic core stores this data. |
| `asn`, `org` | `GeoLite2-ASN.mmdb`, read with the `GeoIp2\Database\Reader` class |
| `provider` | A name from the built-in cloud ASN map if the ASN is listed there; otherwise the ASN organisation |
| `is_datacenter` | `1` only when the ASN is in the built-in cloud ASN map |
| `source` | `maxmind`, `ipdetails` or `none` |

Built-in cloud ASN map:

| Provider | ASNs |
|---|---|
| Amazon AWS | 16509, 14618, 39111 |
| Microsoft Azure | 8075 |
| Microsoft | 8068, 8069 |
| Google Cloud | 15169, 396982, 19527 |
| Cloudflare | 13335 |
| DigitalOcean | 14061 |
| Vultr/Choopa | 20473 |
| OVH | 16276 |
| Hetzner | 24940 |
| Proofpoint | 14080, 26211 |

Results are cached in `botfilter_ip_enrichment`. An IP is enriched the first time it appears on a drill-down page (only the rows on that page), or when `mautic:botfilter:enrich-ips` runs. Cached rows are not refreshed.

The ASN database is stored at `%kernel.cache_dir%/../ip_data/GeoLite2-ASN.mmdb`, which is usually `var/cache/ip_data/GeoLite2-ASN.mmdb`. `php bin/console cache:clear` leaves this directory in place. Deleting all of `var/cache/` also deletes the database; run `mautic:botfilter:update-asn-db` again afterwards.

## Admin page

Location: **Channels > Bot Filter** (route `mautic_botfilter_index`, path `/botfilter/{page}` under Mautic's `/s` admin prefix).

### Contact list

| Element | Detail |
|---|---|
| Health strip | Protection active or inactive; honeypot on or off, with allowlist count; ASN database missing, fresh, stale (stale after 7 days) or unknown (file age unreadable); last capture time |
| Table columns | Contact, Hits, IPs, Reasons, Scanner (most common provider for the contact), Last seen |
| Sorting | Contact, Hits (default, descending), IPs, Last seen |
| Search | Contact first name, last name, email |
| Provider rail | Top 5 providers by hit count, plus an "Unclassified" count. Search does not affect it; the ownership clamp does. |

### Contact drill-down

Route `mautic_botfilter_contact`, path `/botfilter/contact/{leadId}/{page}`.

| Element | Detail |
|---|---|
| Header | Total hits, distinct IPs and last seen, across all of the contact's rows. A "Behind {provider}" badge appears when one classified provider accounts for at least half of the hits. A "Honeypot confirmed" badge appears when any `honeypot` row exists. |
| Table columns | Date, IP, Reason, Location, Organisation (the provider name if the ASN is in the built-in map, otherwise the ASN organisation; with ASN and a datacenter badge), URL |
| Sorting | Date (default, descending), IP, Reason |
| Search | IP, URL |
| Filter | Reason |

## Dashboard widgets

Widget category: **IIT Bot Filter**.

| Widget type | Title | Content |
|---|---|---|
| `botfilter.total.blocked` | Bot hits filtered — total (last 30 days) | Count of rows |
| `botfilter.blocked.hits` | Bot hits filtered — daily breakdown (last 30 days) | Count per UTC day and reason |
| `botfilter.contacts.affected` | Contacts affected by bots (last 30 days) | Distinct contacts |
| `botfilter.top.contacts` | Top contacts affected by bots | Top 10 contacts by hit count over the last 30 days, linked to the drill-down |

All widgets filter on `date_captured` and apply the ownership clamp described under [Permissions](#permissions). For viewers clamped to their own contacts, the two count widgets show the sub-line "Last 30 days · contacts you own".

## Console commands

| Command | Purpose | Options (default) |
|---|---|---|
| `mautic:botfilter:install` | Creates `botfilter_blocked_hits` and `botfilter_ip_enrichment` if they are missing, and adds the `bf_lead` and `bf_captured` indexes if they are missing. | none |
| `mautic:botfilter:update-asn-db` | Downloads GeoLite2-ASN and writes it to the `ip_data` directory. | none |
| `mautic:botfilter:enrich-ips` | Enriches every distinct IP in `botfilter_blocked_hits` that is not already cached. | none |
| `mautic:botfilter:prune` | Deletes `botfilter_blocked_hits` rows whose `date_captured` is older than the cutoff. With `--apply`, also deletes enrichment rows whose IP has no remaining hit. | `--older-than` (`180` days, minimum `1`); `--apply` (off, which is a dry run) |
| `mautic:botfilter:recount` | Removes burst opens from stored statistics and re-derives the related aggregates. | `--window` (`30` seconds, minimum `1`); `--min-ips` (`3`, minimum `2`); `--apply` (off, which is a dry run); `--skip-trackables` (off) |

### mautic:botfilter:update-asn-db

- Reads the licence key from `ip_lookup_auth`. If the value contains `:`, the part after the colon is used; otherwise the whole value is used.
- Downloads from MaxMind's `/app/geoip_download` endpoint with `edition_id=GeoLite2-ASN`. This endpoint needs only the licence key.
- Exits non-zero, with an error message, in these cases: no key; data directory missing and cannot be created, or not writable; no response; non-2xx HTTP status (MaxMind's reason text is included); empty body; `GeoLite2-ASN.mmdb` not in the archive; extraction or copy fails.

### mautic:botfilter:recount

Detection: an `email_stats_devices` row is marked as a bot open if it falls inside any sliding window of `--window` seconds (end exclusive) that contains at least `--min-ips` distinct `ip_id` values for the same `stat_id`.

| Step | Table | Change |
|---|---|---|
| 1. Opens | `email_stats`, `email_stats_devices` | Removes the matching entries from the serialized `open_details`. Recomputes `open_count`, `is_read`, `date_read` and `last_opened` from the remaining entries. Deletes the bot device rows. Skips any stat whose `open_details` cannot be unserialized. |
| 2. Read counts | `emails` | Sets `read_count` to the count of `email_stats` rows with `is_read = 1`, for emails changed in step 1 |
| 3. Trackables | `channel_url_trackables` | For every `channel = 'email'` row, sets `hits = COUNT(*)` and `unique_hits = COUNT(DISTINCT tracking_id)` from `page_hits`. Skipped with `--skip-trackables`. |

- With `--apply`, all steps run in one transaction, which is rolled back on any error.
- The command does not delete rows from `page_hits`. If burst click rows are removed from `page_hits` separately, remove them before running this command, so that step 3 re-derives from the cleaned data.
- Back up `email_stats`, `email_stats_devices`, `emails` and `channel_url_trackables` before running with `--apply`.
- Use `-v` to print per-stat detail.

## Cron

The plugin does not install cron entries. Suggested schedule:

| Command | Suggested frequency | Reason |
|---|---|---|
| `mautic:botfilter:update-asn-db` | Twice weekly | The health strip and Diagnostics tab mark the database stale after 7 days. Two runs a week keep it fresh even if one run fails. |
| `mautic:botfilter:enrich-ips` | Daily (optional) | Fills the cache for new IPs so the provider rail and Scanner column show providers instead of "Unclassified". |
| `mautic:botfilter:prune --apply` | Weekly (optional) | Keeps `botfilter_blocked_hits` bounded. |

Example:

```cron
23 3 * * 3,6 php /path/to/mautic/bin/console mautic:botfilter:update-asn-db
40 3 * * *   php /path/to/mautic/bin/console mautic:botfilter:enrich-ips
50 3 * * 0   php /path/to/mautic/bin/console mautic:botfilter:prune --older-than=180 --apply
```

Pick minutes that do not overlap with existing Mautic cron jobs. Run the commands as the user that runs Mautic.

## Permissions

| Surface | Access rule |
|---|---|
| Menu item and admin list | `lead:leads:viewown` or `lead:leads:viewother` |
| Admin list rows, provider rail | Users without `lead:leads:viewother` see only contacts where `owner_id` is their user ID |
| Contact drill-down | `hasEntityAccess('lead:leads:viewown', 'lead:leads:viewother', owner)` on the contact; otherwise access is denied |
| Health strip | Install-wide values; no contact data |
| Dashboard widgets | Shown to users with `lead:leads:viewown` or `lead:leads:viewother`. Data follows the same ownership clamp, and widget cache entries are kept separate for each ownership scope. |
| Settings | Core **Settings > Plugins** page; the plugin adds no permission of its own |
| `/bf/honeypot` | Public, unauthenticated |

## Data stored

| Table | Created by | Content | Retention |
|---|---|---|---|
| `botfilter_blocked_hits` | `mautic:botfilter:install` | One row per classified hit ([columns](#what-is-recorded)) | Kept until `mautic:botfilter:prune --apply` |
| `botfilter_ip_enrichment` | `mautic:botfilter:install` | One row per enriched IP: `country`, `city`, `org`, `asn`, `is_datacenter`, `provider`, `source`, `enriched_at` | Orphaned rows deleted by `mautic:botfilter:prune --apply` |

| File | Created by |
|---|---|
| `%kernel.cache_dir%/../ip_data/GeoLite2-ASN.mmdb` | `mautic:botfilter:update-asn-db` |

If the tables are missing, recording fails silently (a warning is logged), the widgets show empty results, and the admin page shows a query error message.

## Page hit sanitisation

`EventListener/HitEncodingSubscriber.php` is a Doctrine `prePersist` listener on `Mautic\PageBundle\Entity\Hit`. It runs whether or not the integration is published.

| Fields | Treatment |
|---|---|
| `query` and `browserLanguages` (array values and string keys, recursively), `url`, `referer`, `userAgent` | Invalid UTF-8 sequences and NUL bytes replaced with U+FFFD |
| `urlTitle`, `remoteHost`, `pageLanguage`, `trackingId` | Same replacement, then truncated to 191 characters |

This stops page hits with invalid bytes from failing to insert into `utf8mb4` columns when MySQL runs with `STRICT_TRANS_TABLES`. It also stops Mautic's `ArrayType` from rejecting serialized arrays that contain NUL.

## Limitations

- **Early hits of a burst are not caught.** The live check runs before the current hit is saved, so it flags only once `max_distinct_ips` distinct IPs are already stored. `mautic:botfilter:recount` corrects stored open statistics after the fact.
- **Scanners using few IPs are not caught.** Scanners that use fewer than `max_distinct_ips` addresses within the window are not detected by the burst signal.
- **Multi-device users can be false positives.** A real contact who opens or clicks the same email from `max_distinct_ips` or more IPs within the window is classified as a bot.
- **Opens and clicks are counted separately.** The click check counts only `page_hits` and the open check counts only `email_stats_devices`. IPs from the two tables are not combined.
- **The recorded table is incomplete.** `botfilter_blocked_hits` holds only hits that reach the decorator while published, plus honeypot hits. Hits rejected earlier by core are not recorded.
- **Honeypot hits are recorded when unpublished.** `/bf/honeypot` records a hit for any valid hash, including when the integration is unpublished. Links sent while the honeypot was enabled stay active in delivered emails.
- **Some emails do not get the honeypot link.** It is not added to content without `</body>`, or to sends without an ID hash.
- **Enrichment cache is not refreshed.** Cached rows are never refreshed. `enrich-ips` does not update IPs that are already cached.
- **Provider names depend on enrichment.** Provider attribution depends on the ASN database and the small built-in ASN map. IPs that are not enriched count as "Unclassified".
- **`recount` step 3 rewrites all trackables.** The trackables step rewrites every email trackable whose stored counts differ from `page_hits`, including rows unrelated to bot hits.
- **Hits processed asynchronously have no channel or URL.** When a hit is handled outside an HTTP request (for example by an asynchronous messenger worker), `channel` and `url` are recorded as `NULL`.
- **Setting changes need a worker restart.** Settings are cached for the lifetime of the service. A long-running process, such as a messenger worker, keeps using the settings it first read until it is restarted.
- **Widget link path is hard-coded.** The "Top contacts" widget link is `/s/botfilter/contact/{id}`, which assumes Mautic is served from the web root.

## Uninstall

The plugin has no uninstall routine and does not drop its tables.

1. Unpublish the integration on **Settings > Plugins > IIT Bot Filter**.
2. Remove `plugins/IntellectITBotFilterBundle`.
3. Run `php bin/console cache:clear` and `php bin/console mautic:plugins:reload`.
4. Optionally drop `botfilter_blocked_hits` and `botfilter_ip_enrichment` (with the table prefix, if one is set), and delete `GeoLite2-ASN.mmdb` from the `ip_data` directory.

## Testing

Tests are standalone PHP scripts, not PHPUnit, and run with Mautic's autoloader. Several of them connect to the configured database. See [Tests/README.md](Tests/README.md) for how the scripts locate Mautic, each script, the environment variables it needs, and its database impact.

## Development

- [docs/architecture.md](docs/architecture.md): components, request flow, integration contract, widget permissions and caching, and container rebuild rules.
- [CONTRIBUTING.md](CONTRIBUTING.md): how to contribute.
- [Tests/README.md](Tests/README.md): running the test scripts.

## Upstream

| Upstream issue | Status | Relation |
|---|---|---|
| [mautic/mautic#16263](https://github.com/mautic/mautic/issues/16263) | Open | Proposes the burst signal in core's `BotRatioHelper`. Write-up: [docs/upstream-issue.md](docs/upstream-issue.md); patch: [docs/upstream-patch.diff](docs/upstream-patch.diff). |
| [mautic/mautic#15944](https://github.com/mautic/mautic/issues/15944) | Closed (not planned) | Reported that core's GeoLite2-City download used the legacy `/app/geoip_download` endpoint. Core 7.1.3 and later use `/geoip/databases/GeoLite2-City/download` with HTTP Basic `accountId:licenseKey`. `mautic:botfilter:update-asn-db` still uses the legacy endpoint, which needs only the licence key. |

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
