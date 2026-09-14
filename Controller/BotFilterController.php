<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Controller;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Controller\AbstractFormController;
use Mautic\CoreBundle\Factory\PageHelperFactoryInterface;
use MauticPlugin\IntellectITBotFilterBundle\Helper\ConfigProvider;
use MauticPlugin\IntellectITBotFilterBundle\Helper\IpEnricher;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class BotFilterController extends AbstractFormController
{
    /**
     * Whitelisted sort keys → ordered list of SQL expressions. Never build ORDER BY from raw
     * request/session input.
     *
     * A LIST, not one comma-joined string: `ORDER BY a, b DESC` applies DESC to `b` only, so a
     * multi-column key silently sorted its leading columns ASC regardless of the chosen
     * direction. buildOrderBy() applies the direction to every expression instead.
     */
    private const SORT_MAP = [
        'contact'  => ['l.lastname', 'l.firstname'],
        'hits'     => ['hits'],
        'ips'      => ['ips'],
        'lastseen' => ['last'],
    ];

    private const DEFAULT_SORT = 'hits';

    /** Unique tie-breaker appended to the list ORDER BY — see buildOrderBy(). */
    private const SORT_TIEBREAK = 'h.lead_id';

    /** Whitelisted sort keys for the contact drill-down. */
    private const CONTACT_SORT_MAP = [
        'date'   => ['COALESCE(date_hit, date_captured)'],
        'ip'     => ['ip'],
        'reason' => ['reason'],
    ];

    private const CONTACT_DEFAULT_SORT = 'date';

    /** Unique tie-breaker for the drill-down ORDER BY (PK of botfilter_blocked_hits). */
    private const CONTACT_SORT_TIEBREAK = 'id';

    /** The only reason codes the plugin ever writes — used to validate the reason filter. */
    private const KNOWN_REASONS = ['burst-open', 'burst-click', 'core-3signal', 'honeypot'];

    /**
     * Health-strip "stale" threshold for the ASN DB, in seconds. Aliased from IpEnricher so the
     * settings Diagnostics tab and this strip can never drift apart — see the constant's own
     * docblock for the bug that caused.
     */
    private const ASN_DB_STALE_SECONDS = IpEnricher::ASN_DB_STALE_SECONDS;

    /**
     * Placeholders passed to the view on an ajax FRAGMENT request (tmpl=list), where the health
     * strip, provider rail and contact identity header are not rendered at all — they live
     * inside the templates' `isIndex` branch. The view parameters still have to exist (Twig runs
     * with strict_variables), but the queries behind them are skipped.
     *
     * These values are never displayed: if one ever shows up in the UI, the guard in the
     * relevant action is wrong, not these defaults.
     */
    private const EMPTY_HEALTH = [
        'protection'     => false,
        'honeypot'       => false,
        'allowlistCount' => 0,
        'asnExists'      => false,
        'asnAgeSeconds'  => null,
        'asnStale'       => true,
        'lastCapture'    => null,
    ];

    private const EMPTY_ROLLUP = ['top' => [], 'unclassified' => 0, 'max' => 0];

    private const EMPTY_CONTACT_STATS = [
        'hits'              => 0,
        'ips'               => 0,
        'lastSeen'          => null,
        'dominantProvider'  => null,
        'honeypotConfirmed' => false,
    ];

    public function indexAction(
        Connection $connection,
        IpEnricher $enricher,
        ConfigProvider $configProvider,
        PageHelperFactoryInterface $pageHelperFactory,
        LoggerInterface $mauticLogger,
        int $page = 1
    ): Response {
        if (!$this->hasAccess()) {
            return $this->accessDenied();
        }

        $request = $this->getCurrentRequest();
        $session = $request->getSession();
        $tmpl    = $request->get('tmpl', 'index');

        // Core's {page} route wiring defaults to 0 (not 1) when the segment is omitted (e.g.
        // navigating to the menu item fresh) — fall back to whichever page the session
        // remembers so the list doesn't silently reset. An explicit page in the URL always wins.
        if ($page > 0) {
            $session->set('mautic.botfilter.page', $page);
        } else {
            $page = (int) $session->get('mautic.botfilter.page', 1);
            $page = $page > 0 ? $page : 1;
        }

        // Core convention: captures orderby/orderbydir (with same-column toggle) and limit
        // from the request into session. See getDefaultOrderDirection() override below for
        // this list's DESC-by-default (core's own default is ASC).
        $this->setListFilters('botfilter');

        $pageHelper = $pageHelperFactory->make('mautic.botfilter', $page);
        $limit      = $pageHelper->getLimit();
        $start      = $pageHelper->getStart();

        $orderBy = (string) $session->get('mautic.botfilter.orderby', self::DEFAULT_SORT);
        if (!array_key_exists($orderBy, self::SORT_MAP)) {
            $orderBy = self::DEFAULT_SORT;
        }
        $orderByDir = strtoupper((string) $session->get('mautic.botfilter.orderbydir', 'DESC'));
        $orderByDir = 'ASC' === $orderByDir ? 'ASC' : 'DESC';

        $search = trim((string) $request->get('search', $session->get('mautic.botfilter.filter', '')));
        $session->set('mautic.botfilter.filter', $search);

        $prefix = (string) MAUTIC_TABLE_PREFIX;

        // Permission clamp: a viewother user sees every affected contact; a viewown-only
        // user sees only contacts they own (matches Mautic contact-list semantics; prevents IDOR).
        // Kept separate from $params so the provider rollup can bind the ownership clamp
        // WITHOUT the search clause (see fetchProviderRollup()). DBAL does tolerate an unused
        // :search value, so this is about intent, not an exception: the rollup's params should
        // name exactly what its SQL references, or the next edit re-introduces the drift.
        $ownerClause = '';
        $ownerParams = [];
        if (!$this->security->isGranted('lead:leads:viewother')) {
            $ownerClause        = ' AND l.owner_id = :uid';
            $ownerParams['uid'] = (int) ($this->user?->getId() ?? 0);
        }

        $params = $ownerParams;
        $types  = [];

        $searchClause = '';
        if ('' !== $search) {
            $searchClause    = ' AND (l.firstname LIKE :search OR l.lastname LIKE :search OR l.email LIKE :search)';
            $params['search'] = '%'.$search.'%';
        }

        $orderSql = self::buildOrderBy(self::SORT_MAP[$orderBy], $orderByDir, self::SORT_TIEBREAK);

        $rows        = [];
        $totalItems  = 0;
        $queryFailed = false;
        try {
            $totalItems = (int) $connection->fetchOne(
                "SELECT COUNT(DISTINCT h.lead_id)
                 FROM {$prefix}botfilter_blocked_hits h
                 LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                 WHERE h.lead_id IS NOT NULL{$ownerClause}{$searchClause}",
                $params,
                $types
            );

            if ($totalItems > 0 && $start >= $totalItems) {
                $lastPage = $pageHelper->countPage($totalItems);
                $pageHelper->rememberPage($lastPage);

                return $this->postActionRedirect([
                    'returnUrl'       => $this->generateUrl('mautic_botfilter_index', ['page' => $lastPage]),
                    'viewParameters'  => ['page' => $lastPage, 'tmpl' => $tmpl],
                    // On an ajax request postActionRedirect() forwards this as a CONTROLLER
                    // reference (not a Twig path) — using the template path here 500s any
                    // ajax page/limit change that lands beyond the new last page.
                    'contentTemplate' => self::class.'::indexAction',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_botfilter_index',
                        'mauticContent' => 'botfilter',
                    ],
                ]);
            }

            $rows = $connection->fetchAllAssociative(
                "SELECT h.lead_id, COUNT(*) hits, COUNT(DISTINCT h.ip) ips,
                        MAX(COALESCE(h.date_hit, h.date_captured)) last,
                        l.firstname, l.lastname, l.email
                 FROM {$prefix}botfilter_blocked_hits h
                 LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                 WHERE h.lead_id IS NOT NULL{$ownerClause}{$searchClause}
                 GROUP BY h.lead_id, l.firstname, l.lastname, l.email
                 ORDER BY {$orderSql}
                 LIMIT {$limit} OFFSET {$start}",
                $params,
                $types
            );
        } catch (\Throwable $e) {
            $mauticLogger->warning('BotFilter: list query failed', ['exception' => $e]);
            $queryFailed = true;
        }

        $leadIds = array_values(array_unique(array_map(static fn ($r) => (int) $r['lead_id'], $rows)));

        $reasonsByLead  = $this->fetchReasonsByLead($connection, $mauticLogger, $leadIds);
        $providerByLead = $this->fetchTopProviderByLead($connection, $mauticLogger, $leadIds);

        foreach ($rows as &$row) {
            $leadId               = (int) $row['lead_id'];
            $row['reasons']       = $reasonsByLead[$leadId] ?? [];
            $row['top_provider']  = $providerByLead[$leadId] ?? null;
        }
        unset($row);

        // Both panels render only inside the template's isIndex branch, so on an ajax fragment
        // request — every live-search keystroke, every sort click, every page/limit change —
        // their queries produced markup that was discarded. fetchProviderRollup() alone is two
        // full scans of botfilter_blocked_hits (EXPLAIN: type=ALL, Using temporary; filesort),
        // and buildHealthStrip() adds an integration-entity load plus a MAX() read.
        $isIndex        = 'index' === $tmpl;
        $health         = $isIndex ? $this->buildHealthStrip($connection, $enricher, $configProvider, $mauticLogger) : self::EMPTY_HEALTH;
        $providerRollup = $isIndex ? $this->fetchProviderRollup($connection, $mauticLogger, $ownerClause, $ownerParams) : self::EMPTY_ROLLUP;

        return $this->delegateView([
            'viewParameters' => [
                'tmpl'           => $tmpl,
                'rows'           => $rows,
                'page'           => $page,
                'limit'          => $limit,
                'totalItems'     => $totalItems,
                'searchValue'    => $search,
                'orderBy'        => $orderBy,
                'orderByDir'     => $orderByDir,
                'queryFailed'    => $queryFailed,
                'health'         => $health,
                'providerRollup' => $providerRollup,
            ],
            'contentTemplate' => '@IntellectITBotFilter/BotFilter/list.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_botfilter_index',
                'mauticContent' => 'botfilter',
                'route'         => $this->generateUrl('mautic_botfilter_index', ['page' => $page]),
            ],
        ]);
    }

    public function contactAction(
        Connection $connection,
        IpEnricher $enricher,
        LoggerInterface $mauticLogger,
        PageHelperFactoryInterface $pageHelperFactory,
        int $leadId,
        int $page = 1
    ): Response {
        if (!$this->hasAccess()) {
            return $this->accessDenied();
        }

        $prefix  = (string) MAUTIC_TABLE_PREFIX;
        $contact = $connection->fetchAssociative(
            "SELECT id, firstname, lastname, email, owner_id FROM {$prefix}leads WHERE id = :id", ['id' => $leadId]
        );

        // No such contact, or the requester lacks ownership access to it (viewown clamp) → deny.
        // Prevents enumerating other users' contacts' filtered-IP history via the leadId.
        if (!$contact || !$this->security->hasEntityAccess(
            'lead:leads:viewown', 'lead:leads:viewother', (int) ($contact['owner_id'] ?? 0)
        )) {
            return $this->accessDenied();
        }

        $request = $this->getCurrentRequest();
        $session = $request->getSession();
        $tmpl    = $request->get('tmpl', 'index');

        // Separate session namespace from the list page (mautic.botfilter.contact vs
        // mautic.botfilter) so paging one contact's hits doesn't reset the list's own page.
        $this->setListFilters('botfilter.contact');

        if ($page > 0) {
            $session->set('mautic.botfilter.contact.page', $page);
        } else {
            $page = (int) $session->get('mautic.botfilter.contact.page', 1);
            $page = $page > 0 ? $page : 1;
        }

        $pageHelper = $pageHelperFactory->make('mautic.botfilter.contact', $page);
        $limit      = $pageHelper->getLimit();
        $start      = $pageHelper->getStart();

        $orderBy = (string) $session->get('mautic.botfilter.contact.orderby', self::CONTACT_DEFAULT_SORT);
        if (!array_key_exists($orderBy, self::CONTACT_SORT_MAP)) {
            $orderBy = self::CONTACT_DEFAULT_SORT;
        }
        $orderByDir = strtoupper((string) $session->get('mautic.botfilter.contact.orderbydir', 'DESC'));
        $orderByDir = 'ASC' === $orderByDir ? 'ASC' : 'DESC';

        $search = trim((string) $request->get('search', $session->get('mautic.botfilter.contact.filter', '')));
        $session->set('mautic.botfilter.contact.filter', $search);

        // Reason filter: validated against the known codes, not just bound — an unrecognised
        // value is treated as "no filter" rather than silently matching nothing.
        $reason = (string) $request->get('reason', $session->get('mautic.botfilter.contact.reason', ''));
        if ('' !== $reason && !in_array($reason, self::KNOWN_REASONS, true)) {
            $reason = '';
        }
        $session->set('mautic.botfilter.contact.reason', $reason);

        $params = ['id' => $leadId];
        $types  = [];

        $searchClause = '';
        if ('' !== $search) {
            $searchClause    = ' AND (ip LIKE :search OR url LIKE :search)';
            $params['search'] = '%'.$search.'%';
        }
        $reasonClause = '';
        if ('' !== $reason) {
            $reasonClause  = ' AND reason = :reason';
            $params['reason'] = $reason;
        }

        $orderSql = self::buildOrderBy(self::CONTACT_SORT_MAP[$orderBy], $orderByDir, self::CONTACT_SORT_TIEBREAK);

        $hits        = [];
        $totalItems  = 0;
        $queryFailed = false;
        try {
            $totalItems = (int) $connection->fetchOne(
                "SELECT COUNT(*) FROM {$prefix}botfilter_blocked_hits
                 WHERE lead_id = :id{$searchClause}{$reasonClause}",
                $params,
                $types
            );

            if ($totalItems > 0 && $start >= $totalItems) {
                $lastPage = $pageHelper->countPage($totalItems);
                $pageHelper->rememberPage($lastPage);

                return $this->postActionRedirect([
                    'returnUrl'       => $this->generateUrl('mautic_botfilter_contact', ['leadId' => $leadId, 'page' => $lastPage]),
                    'viewParameters'  => ['leadId' => $leadId, 'page' => $lastPage, 'tmpl' => $tmpl],
                    'contentTemplate' => self::class.'::contactAction',
                    'passthroughVars' => [
                        'activeLink'    => '#mautic_botfilter_index',
                        'mauticContent' => 'botfilter',
                    ],
                ]);
            }

            // Pagination cuts the row set BEFORE enrichMany() below — with hundreds of hits on
            // one contact, enriching unconditionally would cost one lookup per hit per view instead
            // of one page's worth.
            $hits = $connection->fetchAllAssociative(
                "SELECT date_hit, date_captured, ip, reason, url FROM {$prefix}botfilter_blocked_hits
                 WHERE lead_id = :id{$searchClause}{$reasonClause}
                 ORDER BY {$orderSql}
                 LIMIT {$limit} OFFSET {$start}",
                $params,
                $types
            );
        } catch (\Throwable $e) {
            $mauticLogger->warning('BotFilter: contact hits query failed', ['exception' => $e]);
            $queryFailed = true;
        }

        $enrich = $enricher->enrichMany(array_values(array_filter(array_map(static fn ($h) => (string) $h['ip'], $hits))));
        foreach ($hits as &$h) {
            $h['enrich'] = $enrich[$h['ip']] ?? null;
        }
        unset($h);

        // Identity header is inside the template's isIndex branch too — skip its 3 queries on
        // ajax fragment requests (sort/search/reason-filter/page all re-enter here as tmpl=list).
        $stats = 'index' === $tmpl
            ? $this->buildContactStats($connection, $mauticLogger, $leadId)
            : self::EMPTY_CONTACT_STATS;

        return $this->delegateView([
            'viewParameters' => [
                'tmpl'        => $tmpl,
                'contact'     => $contact,
                'hits'        => $hits,
                'page'        => $page,
                'limit'       => $limit,
                'totalItems'  => $totalItems,
                'searchValue' => $search,
                'reason'      => $reason,
                'orderBy'     => $orderBy,
                'orderByDir'  => $orderByDir,
                'queryFailed' => $queryFailed,
                'stats'       => $stats,
            ],
            'contentTemplate' => '@IntellectITBotFilter/BotFilter/contact.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#mautic_botfilter_index',
                'mauticContent' => 'botfilter',
                'route'         => $this->generateUrl('mautic_botfilter_contact', ['leadId' => $leadId, 'page' => $page]),
            ],
        ]);
    }

    /**
     * Identity-header stats: totals across ALL of this contact's hits (not just the current
     * page), plus the two inference badges (see docs/ui-spec.md). Independent try/catch so a
     * problem here degrades the header, not the whole page.
     *
     * @return array{hits: int, ips: int, lastSeen: ?string, dominantProvider: ?string,
     *               honeypotConfirmed: bool}
     */
    private function buildContactStats(Connection $connection, LoggerInterface $mauticLogger, int $leadId): array
    {
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        $default = self::EMPTY_CONTACT_STATS;

        try {
            $totals = $connection->fetchAssociative(
                "SELECT COUNT(*) hits, COUNT(DISTINCT ip) ips,
                        MAX(COALESCE(date_hit, date_captured)) last
                 FROM {$prefix}botfilter_blocked_hits WHERE lead_id = :id",
                ['id' => $leadId]
            );
            if (!$totals) {
                return $default;
            }
            $totalHits = (int) $totals['hits'];

            $honeypotConfirmed = (bool) $connection->fetchOne(
                "SELECT 1 FROM {$prefix}botfilter_blocked_hits WHERE lead_id = :id AND reason = 'honeypot' LIMIT 1",
                ['id' => $leadId]
            );

            $topProvider = null;
            if ($totalHits > 0) {
                $top = $connection->fetchAssociative(
                    "SELECT COALESCE(e.provider, 'Unclassified') AS provider, COUNT(*) AS cnt
                     FROM {$prefix}botfilter_blocked_hits h
                     LEFT JOIN {$prefix}botfilter_ip_enrichment e ON e.ip = h.ip
                     WHERE h.lead_id = :id
                     GROUP BY COALESCE(e.provider, 'Unclassified')
                     -- Same tie-break as fetchTopProviderByLead()'s window function, so the
                     -- 'Behind X' badge here can never name a different provider than the
                     -- Scanner cell on the list row the user clicked to get here.
                     ORDER BY cnt DESC, provider ASC
                     LIMIT 1",
                    ['id' => $leadId]
                );
                // "Dominates" (see docs/ui-spec.md): this provider alone accounts for at least half
                // of all hits, and it's a real provider (not the Unclassified bucket).
                if ($top && 'Unclassified' !== $top['provider'] && (int) $top['cnt'] >= (int) ceil($totalHits / 2)) {
                    $topProvider = (string) $top['provider'];
                }
            }

            return [
                'hits'              => $totalHits,
                'ips'               => (int) $totals['ips'],
                'lastSeen'          => is_string($totals['last']) && '' !== $totals['last'] ? $totals['last'] : null,
                'dominantProvider'  => $topProvider,
                'honeypotConfirmed' => $honeypotConfirmed,
            ];
        } catch (\Throwable $e) {
            $mauticLogger->warning('BotFilter: contact stats query failed', ['exception' => $e]);

            return $default;
        }
    }

    private function hasAccess(): bool
    {
        $p = $this->security->isGranted(['lead:leads:viewown', 'lead:leads:viewother'], 'RETURN_ARRAY');

        return !empty($p['lead:leads:viewown']) || !empty($p['lead:leads:viewother']);
    }

    /**
     * Builds an ORDER BY fragment from a whitelisted expression list, applying $dir to EVERY
     * expression and appending a unique tie-breaker.
     *
     * The tie-breaker is what makes LIMIT/OFFSET paging stable: hit counts, IP counts and
     * surnames all tie routinely, and MySQL is free to return tied rows in a different order
     * per query, which duplicates rows on one page and drops them from another.
     *
     * @param string[] $expressions whitelisted SQL expressions (never request/session input)
     * @param string   $dir         already normalised to 'ASC' or 'DESC' by the caller
     */
    private static function buildOrderBy(array $expressions, string $dir, string $tieBreak): string
    {
        $parts = array_map(static fn (string $expr): string => $expr.' '.$dir, $expressions);
        // Tie-breaker direction is fixed, not $dir: it only has to be deterministic, and a
        // stable ASC keeps the page boundaries identical however the visible column is sorted.
        $parts[] = $tieBreak.' ASC';

        return implode(', ', $parts);
    }

    /** This list defaults to "most hits first" — core's own default is ASC. */
    protected function getDefaultOrderDirection()
    {
        return 'DESC';
    }

    /**
     * @param int[] $leadIds
     *
     * @return array<int, string[]> lead_id => distinct reason codes
     */
    private function fetchReasonsByLead(Connection $connection, LoggerInterface $mauticLogger, array $leadIds): array
    {
        if ([] === $leadIds) {
            return [];
        }
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        try {
            $rows = $connection->fetchAllAssociative(
                "SELECT lead_id, reason FROM {$prefix}botfilter_blocked_hits
                 WHERE lead_id IN (:leadIds) GROUP BY lead_id, reason",
                ['leadIds' => $leadIds],
                ['leadIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
        } catch (\Throwable $e) {
            $mauticLogger->warning('BotFilter: reasons-by-lead query failed', ['exception' => $e]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['lead_id']][] = (string) $r['reason'];
        }

        return $out;
    }

    /**
     * @param int[] $leadIds
     *
     * @return array<int, string> lead_id => top provider label ("Unclassified" if none)
     */
    private function fetchTopProviderByLead(Connection $connection, LoggerInterface $mauticLogger, array $leadIds): array
    {
        if ([] === $leadIds) {
            return [];
        }
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        try {
            $rows = $connection->fetchAllAssociative(
                "SELECT lead_id, provider FROM (
                    SELECT h.lead_id AS lead_id, COALESCE(e.provider, 'Unclassified') AS provider,
                           ROW_NUMBER() OVER (
                               PARTITION BY h.lead_id
                               -- Provider name breaks ties: without it, a contact whose hits
                               -- split evenly across two providers gets an arbitrary winner
                               -- that can differ per execution, so the Scanner cell flickers
                               -- between refreshes and can disagree with the drill-down's own
                               -- 'Behind X' badge (buildContactStats() resolves the same tie
                               -- independently, and now breaks it the same way).
                               ORDER BY COUNT(*) DESC, COALESCE(e.provider, 'Unclassified') ASC
                           ) AS rn
                    FROM {$prefix}botfilter_blocked_hits h
                    LEFT JOIN {$prefix}botfilter_ip_enrichment e ON e.ip = h.ip
                    WHERE h.lead_id IN (:leadIds)
                    GROUP BY h.lead_id, COALESCE(e.provider, 'Unclassified')
                 ) ranked
                 WHERE rn = 1",
                ['leadIds' => $leadIds],
                ['leadIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
        } catch (\Throwable $e) {
            $mauticLogger->warning('BotFilter: top-provider-by-lead query failed', ['exception' => $e]);

            return [];
        }

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['lead_id']] = (string) $r['provider'];
        }

        return $out;
    }

    /**
     * Health strip: install-level operational status, not scoped to the viewing user's
     * contact ownership (no contact identity is revealed). Each read is independent so one
     * failure never blanks the whole strip.
     *
     * @return array{protection: bool, honeypot: bool, allowlistCount: int, asnExists: bool,
     *               asnAgeSeconds: ?int, asnStale: bool, lastCapture: ?string}
     */
    private function buildHealthStrip(
        Connection $connection,
        IpEnricher $enricher,
        ConfigProvider $configProvider,
        LoggerInterface $mauticLogger
    ): array {
        $protection = $configProvider->isEnabled();
        $honeypot   = $configProvider->isHoneypotEnabled();
        $allowlist  = array_filter(array_map('trim', explode(',', $configProvider->getHoneypotEmailIds())));

        $asn = $enricher->asnDbInfo();
        $asnAgeSeconds = null !== $asn['mtime'] ? (time() - $asn['mtime']) : null;

        $lastCapture = null;
        try {
            $prefix = (string) MAUTIC_TABLE_PREFIX;
            $max    = $connection->fetchOne("SELECT MAX(date_captured) FROM {$prefix}botfilter_blocked_hits");
            $lastCapture = is_string($max) && '' !== $max ? $max : null;
        } catch (\Throwable $e) {
            $mauticLogger->warning('BotFilter: health-strip last-capture query failed', ['exception' => $e]);
        }

        return [
            'protection'     => $protection,
            'honeypot'       => $honeypot,
            'allowlistCount' => count($allowlist),
            'asnExists'      => $asn['exists'],
            'asnAgeSeconds'  => $asnAgeSeconds,
            'asnStale'       => null === $asnAgeSeconds || $asnAgeSeconds > self::ASN_DB_STALE_SECONDS,
            'lastCapture'    => $lastCapture,
        ];
    }

    /**
     * Provider rollup: install-wide aggregate (provider name + count only, no contact
     * identity). See "Admin list and drill-down request flow" in docs/architecture.md.
     *
     * Deliberately NOT scoped by the list's search term. The rail renders outside the
     * `.page-list` ajax-swap target (like the health strip), so a search-scoped rollup could
     * never be refreshed by a live search — it would keep showing the PREVIOUS filter's counts
     * beside the new result set. Install-wide matches the rail's own heading ("Scanners
     * hitting you"), and makes every panel outside the swap target consistently unfiltered.
     *
     * The ownership clamp IS still applied, even though the rollup reveals no contact
     * identity, so a viewown-only user's rail never counts hits on contacts they cannot see.
     *
     * @param array<string, int> $ownerParams bound params for $ownerClause only — no :search
     *
     * @return array{top: array<int, array{provider: string, count: int}>, unclassified: int, max: int}
     */
    private function fetchProviderRollup(
        Connection $connection,
        LoggerInterface $mauticLogger,
        string $ownerClause,
        array $ownerParams
    ): array {
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        $top    = [];
        $unclassified = 0;

        try {
            $top = $connection->fetchAllAssociative(
                "SELECT e.provider AS provider, COUNT(*) AS cnt
                 FROM {$prefix}botfilter_blocked_hits h
                 LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                 LEFT JOIN {$prefix}botfilter_ip_enrichment e ON e.ip = h.ip
                 WHERE h.lead_id IS NOT NULL{$ownerClause} AND e.provider IS NOT NULL
                 GROUP BY e.provider
                 ORDER BY cnt DESC, e.provider ASC
                 LIMIT 5",
                $ownerParams
            );

            $unclassified = (int) $connection->fetchOne(
                "SELECT COUNT(*)
                 FROM {$prefix}botfilter_blocked_hits h
                 LEFT JOIN {$prefix}leads l ON l.id = h.lead_id
                 LEFT JOIN {$prefix}botfilter_ip_enrichment e ON e.ip = h.ip
                 WHERE h.lead_id IS NOT NULL{$ownerClause} AND (e.provider IS NULL OR e.ip IS NULL)",
                $ownerParams
            );
        } catch (\Throwable $e) {
            $mauticLogger->warning('BotFilter: provider-rollup query failed', ['exception' => $e]);

            return self::EMPTY_ROLLUP;
        }

        $rows = array_map(static fn ($r) => ['provider' => (string) $r['provider'], 'count' => (int) $r['cnt']], $top);
        $max  = 0;
        foreach ($rows as $r) {
            $max = max($max, $r['count']);
        }
        $max = max($max, $unclassified);

        return ['top' => $rows, 'unclassified' => $unclassified, 'max' => $max];
    }
}
