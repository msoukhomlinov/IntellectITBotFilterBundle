<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\EventListener;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\CoreBundle\Twig\Helper\DateHelper;
use Mautic\DashboardBundle\Event\WidgetDetailEvent;
use Mautic\DashboardBundle\Event\WidgetTypeListEvent;
use Mautic\DashboardBundle\EventListener\DashboardSubscriber as BaseDashboardSubscriber;

class DashboardSubscriber extends BaseDashboardSubscriber
{
    protected $bundle = 'botfilter';

    protected $types = [
        'botfilter.blocked.hits'      => [],
        'botfilter.total.blocked'     => [],
        'botfilter.contacts.affected' => [],
        'botfilter.top.contacts'      => [],
    ];

    // Both permissions, matching BotFilterController::hasAccess()'s "viewown OR viewother"
    // contract. Core reads this one array through two gates with OPPOSITE semantics:
    //   - WidgetDetailEvent::hasPermissions()   → in_array(true, ...)   → OR  (render gate)
    //   - WidgetTypeListEvent::hasPermissions() → !in_array(false, ...) → AND ("add widget" picker)
    // Both listed is what the OR render gate needs; onWidgetListGenerate() is overridden below
    // so the AND picker path becomes OR too (with both listed and no override, the picker would
    // hide all four widgets from a viewown-only role — the common non-admin case).
    // Listing only 'viewown' instead would deny a viewother-without-viewown role, which the
    // admin page itself allows. In practice core's AbstractPermissions::analyzePermissions()
    // force-adds viewown whenever viewother is granted, so that role cannot normally be saved —
    // this makes the gate correct by construction rather than by relying on that.
    protected $permissions = ['lead:leads:viewown', 'lead:leads:viewother'];

    // Deps are nullable with null defaults so a stale DI container (mid cache-rebuild)
    // calling the new ctor signature with fewer args degrades gracefully instead of
    // throwing ArgumentCountError on instantiation. Connection usage is already
    // try/catch-guarded (a null call throws \Error, caught → empty widget); DateHelper
    // usage is routed through fmtFull()/fmtDay() which fall back to the raw value;
    // security/userHelper usage is routed through ownerClause() and isOwnerClamped(),
    // which fail CLOSED if either is unavailable: the viewer is treated as owner-clamped
    // and, with no resolvable user id, sees no contact data rather than everyone's.
    // See "DI rebuild safety" in docs/architecture.md.
    public function __construct(
        private ?Connection $connection = null,
        private ?DateHelper $dateHelper = null,
        private ?CorePermissions $security = null,
        private ?UserHelper $userHelper = null,
    ) {
        // Intentionally NO parent::__construct() — core base has no constructor.
    }

    /** Localised full datetime, or the raw value if the DateHelper is unavailable. */
    private function fmtFull(?string $v): string
    {
        if (null === $v || '' === $v) {
            return (string) $v;
        }

        return null !== $this->dateHelper ? $this->dateHelper->toFull($v, 'UTC') : $v;
    }

    /**
     * Formats a `Y-m-d` day bucket in the site's date format, or returns the raw value if the
     * DateHelper is unavailable.
     *
     * The bucket is a UTC calendar day (`DATE(h.date_captured)`, and date_captured is stored in
     * UTC), and it is rendered as that same day — no timezone conversion — so the label always
     * names the day the rows were actually grouped by.
     *
     * That means NOT calling toDate($day, 'UTC', 'Y-m-d'): DateHelper's third argument is the
     * INPUT format, not the output format, and DateTimeHelper::setDateTime() parses with
     * createFromFormat() WITHOUT a leading '!' — so the unspecified time fields are filled from
     * the current wall clock and toLocalString() then shifts UTC to the site timezone. With
     * a site timezone ahead of UTC (e.g. UTC+10) every bucket was labelled one day late for any
     * render between 14:00 and 24:00 UTC — ~10 hours out of every 24.
     *
     * Passing an explicit midnight and asking for 'local' interpretation makes the parse exact
     * and the conversion a genuine no-op, while still using the site's configured date format.
     */
    private function fmtDay(?string $ymd): string
    {
        if (null === $ymd || '' === $ymd) {
            return (string) $ymd;
        }

        return null !== $this->dateHelper ? $this->dateHelper->toDate($ymd.' 00:00:00', 'local') : $ymd;
    }

    /**
     * Ownership clamp matching BotFilterController::indexAction's existing pattern: a
     * viewother user sees data across every contact; a viewown-only user sees only their
     * own. All four widgets query through this so the dashboard never shows a viewown-only
     * user data attributable to another user's contacts (names, emails, per-contact counts).
     *
     * @return array{0: string, 1: array<string, int>} [SQL clause fragment, bound params]
     */
    private function ownerClause(string $alias): array
    {
        // Fail CLOSED, not open: if $security is unavailable (stale DI container mid
        // cache-rebuild — see "DI rebuild safety" in docs/architecture.md), clamp to a uid
        // nothing will legitimately own rather than showing everyone's data.
        // Unlike $connection/$dateHelper being null (which degrades to an empty/unformatted
        // widget), $security being null must never degrade to "unclamped" — that would
        // expose other users' contacts to a viewown-only viewer.
        if (null !== $this->security && $this->security->isGranted('lead:leads:viewother')) {
            return ['', []];
        }
        $uid = null !== $this->userHelper ? (int) ($this->userHelper->getUser()?->getId() ?? 0) : 0;

        return [" AND {$alias}.owner_id = :uid", ['uid' => $uid]];
    }

    /** True when the viewer is clamped to their own contacts (drives the "you own" sub-line). */
    private function isOwnerClamped(): bool
    {
        return null === $this->security || !$this->security->isGranted('lead:leads:viewother');
    }

    /**
     * Same as core's base implementation except the permission test is OR, not AND.
     *
     * WidgetTypeListEvent::hasPermissions() returns `!in_array(false, $perm)`, so passing both
     * lead permissions at once would hide all four widgets from the "add widget" picker for
     * anyone holding just one of them. Calling it once per permission makes each call a
     * single-permission check, and OR-ing the results restores the same "viewown OR viewother"
     * contract BotFilterController::hasAccess() and the render-time gate
     * (WidgetDetailEvent::hasPermissions(), already OR) both use.
     */
    public function onWidgetListGenerate(WidgetTypeListEvent $event): void
    {
        $granted = false;
        foreach ($this->permissions as $permission) {
            if ($event->hasPermissions([$permission])) {
                $granted = true;
                break;
            }
        }

        if ($this->permissions && !$granted) {
            return;
        }

        foreach (array_keys($this->types) as $type) {
            $event->addType($type, $this->bundle);
        }
    }

    public function onWidgetDetailGenerate(WidgetDetailEvent $event): void
    {
        // Bail before touching the event at all if this widget is not one of ours. Core
        // dispatches DETAIL_GENERATE to every subscriber, and not every earlier subscriber
        // stopPropagation()s for the type it handled — observed at runtime: without this guard,
        // `email.sent.read.count` and `campaign.leads.added` both reached the bf_scope
        // mutation below. Widget params are part of core's cache key, so that partitioned
        // unrelated widgets' shared cache per ownership scope (defeating it for every
        // viewer) and handed their handlers a parameter they never declared.
        // checkPermissions() already no-ops for foreign types; this just makes the whole
        // method inert for them.
        if (!isset($this->types[$event->getType()])) {
            return;
        }

        $this->checkPermissions($event);
        if (null !== $event->getErrorMessage()) {
            // Permission denied: never run the (unclamped-by-default-path) query, and never
            // call setTemplateData() below — it writes to the shared cache pool regardless
            // of whether the caller was authorised, which would poison it with unclamped
            // data for the next viewer who IS denied but still reaches this far.
            return;
        }

        // Core's dashboard widget cache (WidgetDetailEvent::getUniqueWidgetId()) is a single
        // GLOBAL pool keyed only on widget params/width/height/locale — never on user or
        // permission scope. Without this, the very first isCached() check below would use a
        // cache key identical for every viewer regardless of ownership: once any
        // viewother/admin user populates it, a viewown-only user would be served that cached
        // UNCLAMPED result verbatim (other users' contact names/emails included) with the
        // owner-clamp query never executing at all — exposing other users' contacts one
        // cache hit away. Folding the effective scope into the
        // widget's params BEFORE the first isCached()/getCacheKey() call (which memoises
        // internally) forces a distinct cache entry per ownership scope instead.
        $widget = $event->getWidget();
        $widget->setParams($widget->getParams() + [
            'bf_scope' => $this->isOwnerClamped() ? 'u'.(int) ($this->userHelper?->getUser()?->getId() ?? 0) : 'all',
        ]);

        if ('botfilter.blocked.hits' === $event->getType()) {
            if (!$event->isCached()) {
                $prefix              = (string) MAUTIC_TABLE_PREFIX;
                [$ownerSql, $ownerParams] = $this->ownerClause('l');
                $rows = [];
                // Defensive: if the table is absent (install command not yet run) show an
                // empty widget rather than a raw Doctrine error on the dashboard.
                try {
                    $rows = $this->connection->fetchAllAssociative(
                        "SELECT DATE(h.date_captured) AS day, h.reason, COUNT(*) AS total
                         FROM {$prefix}botfilter_blocked_hits h
                         LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                         WHERE h.date_captured >= :since{$ownerSql}
                         GROUP BY DATE(h.date_captured), h.reason
                         ORDER BY day DESC, h.reason",
                        // date_captured is stored UTC (gmdate); build the bound in UTC too
                        // so the 30-day window isn't skewed by the app timezone.
                        ['since' => gmdate('Y-m-d 00:00:00', time() - 30 * 86400)] + $ownerParams
                    );
                } catch (\Throwable $e) {
                    $rows = [];
                }

                $event->setTemplateData([
                    'headItems' => [
                        'mautic.botfilter.widget.thead.date',
                        'mautic.botfilter.widget.thead.reason',
                        'mautic.botfilter.widget.thead.count',
                    ],
                    'bodyItems' => array_map(
                        // 'day' is a date-only UTC bucket (DATE(date_captured)); fmtDay() renders
                        // it in the configured date format WITHOUT a timezone shift, so the label
                        // names the same calendar day the rows were grouped by. See fmtDay() —
                        // the obvious-looking toDate($day, 'UTC', 'Y-m-d') silently shifted every
                        // bucket forward a day for ~10 hours out of every 24.
                        //
                        // Reason is rendered as translated plain text, not the shared badge
                        // component: core's table.html.twig has no raw-HTML cell type (only
                        // 'link' or an escaped scalar value), so a coloured badge here would
                        // require a bespoke widget template — more than "apply tokens to the
                        // existing pattern" calls for.
                        fn ($r) => [
                            $this->fmtDay($r['day']),
                            $this->reasonLabel($r['reason'], $event->getTranslator()),
                            (int) $r['total'],
                        ],
                        $rows
                    ),
                ]);
            }

            $event->setTemplate('@MauticCore/Helper/table.html.twig');
            $event->stopPropagation();

            return;
        }

        $since = gmdate('Y-m-d 00:00:00', time() - 30 * 86400);

        if ('botfilter.total.blocked' === $event->getType()) {
            if (!$event->isCached()) {
                [$ownerSql, $ownerParams] = $this->ownerClause('l');
                $n = 0;
                try {
                    $prefix = (string) MAUTIC_TABLE_PREFIX;
                    $n      = (int) $this->connection->fetchOne(
                        "SELECT COUNT(*) FROM {$prefix}botfilter_blocked_hits h
                         LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                         WHERE h.date_captured >= :s{$ownerSql}",
                        ['s' => $since] + $ownerParams
                    );
                } catch (\Throwable $e) {
                }
                $event->setTemplateData([
                    'value' => $n,
                    // Same clamp-disclosure as botfilter.contacts.affected below: this query IS
                    // owner-clamped, so hardcoding the unclamped sub-line left a viewown-only
                    // user reading "total, last 30 days" beside an admin's much larger "total"
                    // with nothing on the tile accounting for the difference.
                    'subline' => $this->isOwnerClamped()
                        ? 'mautic.botfilter.widget.subline.30days_owned'
                        : 'mautic.botfilter.widget.subline.30days',
                ]);
            }
            $event->setTemplate('@IntellectITBotFilter/Widgets/number.html.twig');
            $event->stopPropagation();

            return;
        }

        if ('botfilter.contacts.affected' === $event->getType()) {
            if (!$event->isCached()) {
                [$ownerSql, $ownerParams] = $this->ownerClause('l');
                $n = 0;
                try {
                    $prefix = (string) MAUTIC_TABLE_PREFIX;
                    $n      = (int) $this->connection->fetchOne(
                        "SELECT COUNT(DISTINCT h.lead_id) FROM {$prefix}botfilter_blocked_hits h
                         LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                         WHERE h.lead_id IS NOT NULL AND h.date_captured >= :s{$ownerSql}",
                        ['s' => $since] + $ownerParams
                    );
                } catch (\Throwable $e) {
                }
                $event->setTemplateData([
                    'value'   => $n,
                    // The sub-line is how the ownership clamp becomes visible to the viewer,
                    // rather than the number silently changing with no explanation.
                    'subline' => $this->isOwnerClamped()
                        ? 'mautic.botfilter.widget.subline.30days_owned'
                        : 'mautic.botfilter.widget.subline.30days',
                ]);
            }
            $event->setTemplate('@IntellectITBotFilter/Widgets/number.html.twig');
            $event->stopPropagation();

            return;
        }

        if ('botfilter.top.contacts' === $event->getType()) {
            if (!$event->isCached()) {
                [$ownerSql, $ownerParams] = $this->ownerClause('l');
                $rows = [];
                try {
                    $prefix = (string) MAUTIC_TABLE_PREFIX;
                    $rows   = $this->connection->fetchAllAssociative(
                        "SELECT h.lead_id, COUNT(*) hits, MAX(h.date_captured) last,
                                l.firstname, l.lastname, l.email
                         FROM {$prefix}botfilter_blocked_hits h
                         LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                         WHERE h.lead_id IS NOT NULL AND h.date_captured >= :s{$ownerSql}
                         GROUP BY h.lead_id, l.firstname, l.lastname, l.email
                         ORDER BY hits DESC LIMIT 10",
                        ['s' => $since] + $ownerParams
                    );
                } catch (\Throwable $e) {
                }
                $event->setTemplateData([
                    'headItems' => [
                        'mautic.botfilter.list.thead.contact',
                        'mautic.botfilter.list.thead.hits',
                        'mautic.botfilter.list.thead.lastseen',
                    ],
                    'bodyItems' => array_map(function ($r) {
                        $name = trim(($r['firstname'] ?? '').' '.($r['lastname'] ?? ''));
                        if ('' === $name) {
                            $name = $r['email'] ?: ('Contact #'.$r['lead_id']);
                        }

                        return [
                            ['type' => 'link', 'link' => '/s/botfilter/contact/'.(int) $r['lead_id'], 'value' => $name],
                            (int) $r['hits'],
                            // 'last' is a UTC datetime (MAX(date_captured)); render in the
                            // user/default timezone to match the rest of the Mautic UI.
                            $this->fmtFull($r['last']),
                        ];
                    }, $rows),
                ]);
            }
            $event->setTemplate('@MauticCore/Helper/table.html.twig');
            $event->stopPropagation();

            return;
        }
    }

    /**
     * Translated reason label; unknown codes fall through to the raw value (forward-safe).
     * table.html.twig only runs headItems through |trans, not body cell values, so this
     * translates explicitly rather than returning a raw key for the template to miss.
     */
    private function reasonLabel(string $reason, \Symfony\Contracts\Translation\TranslatorInterface $translator): string
    {
        static $known = ['burst-open', 'burst-click', 'core-3signal', 'honeypot'];
        if (!in_array($reason, $known, true)) {
            return $reason;
        }

        return $translator->trans('mautic.botfilter.reason.'.str_replace('-', '_', $reason));
    }
}
