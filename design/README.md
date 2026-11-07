# BotFilter UI overhaul — design handoff (issue #4)

Deliverables for the Claude Design pass described in
`docs/superpowers/specs/2026-08-31-botfilter-ui-overhaul-design.md` §6 step 2.

## Contents

| File | Put it in the repo at | What it is |
|---|---|---|
| `docs/ui-spec.md` | `docs/ui-spec.md` | The implementation reference for #5-#8: tokens, shared components, per-surface layout, states, translation keys, per-issue checklist. |
| `design/Bot Filter UI.dc.html` | `docs/design/Bot Filter UI.dc.html` | The design canvas (source). Needs `support.js` beside it to render. |
| `design/support.js` | `docs/design/support.js` | Runtime for the canvas. |
| `design/botfilter-ui-canvas.html` | (optional) `docs/design/botfilter-ui-canvas.html` | Self-contained single-file copy of the canvas — opens offline in any browser, no siblings needed. ~2 MB. |

If you only want one file in the repo, take `docs/ui-spec.md` plus the self-contained canvas.

## The canvas — six frames

1. **/s/botfilter/{page}** — affected-contacts list: health strip, core list_toolbar search, sortable
   table headers, core pagination, provider-rollup rail, capture-caveat note.
2. **/s/botfilter/contact/{leadId}/{page}** — drill-down: identity header with inference badges,
   hits table (date / IP / reason / location / org+ASN+datacenter / URL), pagination.
3. **Dashboard widgets** — inner content only for all four (core draws the tile chrome).
4. **Settings modal** — three frames, one per tab state (Detection / Honeypot / Diagnostics).
5. **Empty states** — no-captures panel vs core `noresults` for empty search.
6. **Plugin icon** — flat brand square + blocked-circle mark at 128 / 64 / 32 px.

## Design decisions that need your sign-off before #5

- **Empty states split.** Core `noresults.html.twig` is `alert-warning` styling — wrong tone for
  "no scanners caught yet" on a healthy install. Spec'd: custom reassuring panel for no-data,
  core `noresults` for search-returned-nothing. See ui-spec §7.
- **Menu icon stays off** (spec §5.9 re-decided deliberately, reasoning in ui-spec §1); label is
  "Bot Filter", no "IIT" prefix.
- **Number tiles drop the inner label** rather than reword it. The sub-line carries window + scope
  ("last 30 days · contacts you own"), which is also how the ownership clamp becomes visible instead
  of silently changing the number. ui-spec §2.3 / §5.
- **Provider rollup keeps an "Unclassified" row** for unenriched hits — dropping them overstates
  enrichment coverage. ui-spec §3.
- **Plugin icon** is flat geometry, no text/gradient. Still needs a PNG export at 128 and 256 px for
  `Assets/img/icon.png` (issue #8) — ask and I'll produce it.

## Still open

- The canvas mocks the two burst thresholds as static values; the "flag when N IPs within W seconds"
  echo sentence in the Detection tab is built from them (ui-spec §6).
- `Assets/css/bot-filter.css` is specified but not written — it lands in #5 per ui-spec §9.
