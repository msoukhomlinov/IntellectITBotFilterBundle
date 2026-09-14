# Bot detection misses cloud link-scanning security services (SafeLinks / Mimecast / Proofpoint / Barracuda)

**Affects:** Mautic 7.x (`BotRatioHelper`)
**Component:** EmailBundle — `BotRatioHelper`, open and click tracking

## Summary

`BotRatioHelper::isHitByBot()` is called for **both** email opens
(`EmailModel::hitEmail`) and link clicks (`PageModel::hitPage`). Its signals do not
detect cloud email-security gateways that fetch the tracking pixel and every link in a
message on delivery. These fetches are recorded as opens and clicks, which inflates
engagement statistics and affects segment membership, lead scoring and campaign
reporting.

## Current behaviour

`isHitByBot()` scores three signals and flags a hit when `points / 3 >= botRatioThreshold`
(default `0.6`, so 2 of 3 must be true):

1. `isUnderTimeThreshold` — the hit landed less than `timeFromEmailThreshold` seconds
   after the email's **send** time (default 2 seconds);
2. `isIpInIgnoreList` — the source IP is in `blockedIPAddresses`;
3. `isUserAgentInIgnoreList` — the user agent is identified as a bot by the device
   detector, or contains a string from `blockedUserAgents`.

## Why scanners evade the signals

A scanned message typically produces several hits on the same message from multiple
distinct datacenter IP addresses within a few seconds, with mainstream-browser user agents.

1. **Time signal** — scanners fetch on *delivery*, not on send. Delivery can be well
   after send, outside a 2-second window. Widening the window would also flag fast
   human reads.
2. **IP signal** — scanner IP ranges are large, change over time and are not in a
   shipped blocklist. Maintaining such a list is impractical.
3. **User-agent signal** — scanners present real-browser user agents, so neither the
   device detector nor `blockedUserAgents` flags them.

When none of the signals fire, the hit is recorded as genuine engagement.

## Proposed fix

Add a **list-free** burst signal that runs before the existing score:

> If the same message has already been opened or clicked from `burstMaxDistinctIps` or
> more **distinct** IP addresses within the last `burstWindowSeconds`, treat the hit as
> a bot.

The two tracking paths store their data in different tables, so the check counts each:

| Hit type | Table | Filter |
|---|---|---|
| Clicks | `page_hits` | `email_id`, `lead_id`, `date_hit >= window start` |
| Opens | `email_stats_devices` | `stat_id`, `date_opened >= window start` |

The window start is computed in UTC, matching how both date columns are stored. A
failed query counts as "no burst", so tracking is never interrupted. The signal needs no
maintained data.

Configuration follows the existing `bot_helper_*` parameters and is **opt-in**, so default
behaviour is unchanged:

- `bot_helper_burst_max_distinct_ips` (`MAUTIC_BOT_HELPER_BURST_MAX_DISTINCT_IPS`) —
  default `0` (disabled). Values below `2` disable the check. Suggested value: `3`.
- `bot_helper_burst_window_seconds` (`MAUTIC_BOT_HELPER_BURST_WINDOW_SECONDS`) —
  default `30`.

The new constructor arguments are appended with defaults, so existing positional
construction of `BotRatioHelper` keeps working.

A proposed patch against `app/bundles/EmailBundle/Helper/BotRatioHelper.php` and
`app/bundles/EmailBundle/Config/config.php` (7.x branch) is in `upstream-patch.diff`.
A matching `ConfigType` field and `BotRatioHelperTest` cases covering the open and click
burst paths should accompany it.

## Known limitation

The check runs before the current hit is saved, so it flags a hit only once
`burstMaxDistinctIps` distinct IPs are already stored. The first hits of a burst are
not flagged.
