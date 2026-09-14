# Tests

Standalone validation scripts (not PHPUnit). They run under Mautic's PHP with Mautic's
autoloader. Each prints a pass/fail summary and exits non-zero on failure.

## Running

Scripts locate Mautic via `MAUTIC_ROOT` (default `/var/www/html`). They load
`$MAUTIC_ROOT/vendor/autoload.php`, and the database tests read
`$MAUTIC_ROOT/config/local.php`.

Run them from the Mautic root, as the user that runs Mautic:

```bash
export MAUTIC_ROOT="$(pwd)"
php plugins/IntellectITBotFilterBundle/Tests/LogicTest.php
```

> **Database warning.** `IntegrationTest.php`, `RecorderTest.php`, `HoneypotControllerTest.php`,
> `IpEnricherTest.php`, `HitEncodingLiveTest.php` and the database round-trip section of
> `HitEncodingTest.php` connect to the Mautic database configured in
> `$MAUTIC_ROOT/config/local.php`. They wrap writes in rolled-back transactions (or a
> TEMPORARY table), and `IntegrationTest.php` briefly changes the integration's published
> state and feature settings before restoring them. A crash mid-run can still leave changes
> behind. **Run them against a non-production instance.**

`php -l` does not catch an unresolved `use` for a class constant. Run the relevant test
(which loads the class) to catch it.

## Environment variables

Tests that need existing records read their IDs from the environment and print `[SKIP]`
(exit 0) when they are unset.

| Variable | Used by | Meaning |
|---|---|---|
| `MAUTIC_ROOT` | All scripts | Mautic root directory. Default `/var/www/html`. |
| `BOTFILTER_TEST_STAT_ID` | IntegrationTest, RecorderTest, HoneypotControllerTest | `email_stats.id` with an email, a lead and a `tracking_hash` |
| `BOTFILTER_TEST_EMAIL_ID` | IntegrationTest, RecorderTest | `emails.id` (the stat's email) |
| `BOTFILTER_TEST_LEAD_ID` | IntegrationTest, RecorderTest | `leads.id` (the stat's lead) |
| `BOTFILTER_TEST_IP_IDS` | IntegrationTest | Three comma-separated `ip_addresses.id` values, e.g. `11,12,13` |

```bash
MAUTIC_ROOT="$(pwd)" \
BOTFILTER_TEST_STAT_ID=123 BOTFILTER_TEST_EMAIL_ID=4 BOTFILTER_TEST_LEAD_ID=56 \
BOTFILTER_TEST_IP_IDS=11,12,13 \
  php plugins/IntellectITBotFilterBundle/Tests/IntegrationTest.php
```

## Scripts

| Script | Database | Covers |
|---|---|---|
| `LogicTest.php` | No | `RecountBotStatsCommand` algorithms |
| `ConfigProviderTest.php` | No | `ConfigProvider` settings reader |
| `HoneypotInjectionTest.php` | No | Honeypot link injection |
| `HitEncodingTest.php` | Optional | `Utf8Sanitiser` and `HitEncodingSubscriber` |
| `HitEncodingLiveTest.php` | Yes | Page hit persistence through the EntityManager |
| `IntegrationTest.php` | Yes | Decorated `BotRatioHelper` burst detection and capture |
| `RecorderTest.php` | Yes | `BlockedHitRecorder` |
| `HoneypotControllerTest.php` | Yes | `/bf/honeypot` endpoint |
| `IpEnricherTest.php` | Yes | `IpEnricher` |

### LogicTest.php

Pure logic, no database. Exercises the `RecountBotStatsCommand` algorithms
(`burstRowIds`, `pruneOpenDetails`) via reflection with crafted inputs.

Expected: `16 passed, 0 failed`. Covers sliding-window boundaries (strict `< window`),
distinct-IP counting, late clusters, and `open_details` pruning (duplicate timestamps,
overcounts, empty blob, unparseable blob skipped).

### ConfigProviderTest.php

Pure unit, no database. Stubs `IntegrationHelper` and asserts `isEnabled()` (false when
there is no integration or it is unpublished, true when published), code defaults when
absent or unpublished, feature settings applied when published, and threshold clamping.

Expected: `14 passed`.

### HoneypotInjectionTest.php

Pure unit (stub router, no database). Asserts that `EMAIL_ON_SEND` injects the hidden,
non-trackable honeypot link, and that nothing is injected when disabled.

Expected: `4 passed, 0 failed`.

### HitEncodingTest.php

Unit checks of `Utf8Sanitiser` and `HitEncodingSubscriber`, plus a database round trip
into a TEMPORARY table. The round trip is skipped if `$MAUTIC_ROOT/config/local.php` is
missing or the database is unreachable.

### HitEncodingLiveTest.php

Database. Persists a `Hit` carrying non-UTF-8 bytes and NUL through the real
EntityManager inside a transaction that is always rolled back, then checks that no row is
left behind. It also asserts that all 12 expected checks ran.

### IntegrationTest.php

Database, rolled back. Boots the kernel, resolves the real decorated `BotRatioHelper`,
inserts a synthetic burst inside a transaction, asserts `isBurstScanner` / `isHitByBot`,
then rolls back. It verifies that the `page_hits` count is unchanged afterwards.

Expected: `13 passed, 0 failed` (10 burst/open checks and 3 capture checks). It writes and
rolls back against `page_hits` and `email_stats_devices`. It temporarily publishes the
integration with known thresholds (3 IPs / 30 seconds), then restores the original
published state and feature settings. Fixtures come from the environment variables above.

> A harmless `Undefined array key "MAUTIC_TABLE_PREFIX"` warning may come from the
> standalone bootstrap, not the plugin, when no table prefix is configured.

### RecorderTest.php

Database, rolled back. Asserts that `BlockedHitRecorder` inserts one row and is
exception-safe. Both checks run inside rolled-back transactions, so nothing is persisted.

Expected: `4 passed, 0 failed`.

### HoneypotControllerTest.php

Database, rolled back. Asserts that `/bf/honeypot` records a `reason=honeypot` row for a
valid tracking hash and returns 204, and that an invalid hash returns 204 with no row.

Expected: `4 passed, 0 failed`.

### IpEnricherTest.php

Database, rolled back. Asserts that `IpEnricher::enrich()` returns the full enrichment
shape and caches the row (a second call is a cache hit and adds no row).

Expected: `5 passed`.
