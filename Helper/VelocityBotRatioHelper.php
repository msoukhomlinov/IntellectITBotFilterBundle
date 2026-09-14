<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Helper;

use Doctrine\DBAL\Connection;
use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\EmailBundle\Entity\Stat;
use Mautic\EmailBundle\Helper\BotRatioHelper;
use Mautic\PageBundle\Model\RedirectModel;
use MauticPlugin\IntellectITBotFilterBundle\Helper\ConfigProvider;
use Symfony\Component\HttpFoundation\RequestStack;

class VelocityBotRatioHelper extends BotRatioHelper
{
    // All deps are nullable with null defaults so that during a DI container rebuild
    // window (cache:clear, before the compiled container regenerates) a stale container
    // calling the new ctor signature with fewer args degrades gracefully instead of
    // throwing ArgumentCountError and 500-ing the live email tracker / cron consumers.
    public function __construct(
        private ?BotRatioHelper $inner = null,
        private ?Connection $connection = null,
        private ?RequestStack $requestStack = null,
        private ?RedirectModel $redirectModel = null,
        private ?BlockedHitRecorder $recorder = null,
        private ?ConfigProvider $config = null,
    ) {
        // parent::__construct() is deliberately not called: its signature differs across
        // Mautic 7.x (7.2.0 adds a required DeviceDetectorFactoryInterface), and every
        // detection call is delegated to $inner, so no parent state is used.
    }

    public function isHitByBot(Stat $emailStat, \DateTimeInterface $emailHitDateTime, IpAddress $ipAddress, string $userAgent): bool
    {
        // Rebuild-window safety (stale compiled container, partial arg set) — never fatal
        // the tracker. Without the inner core helper there is nothing to delegate to, so
        // report "not a bot"; with $inner but any other dep missing, use core detection only.
        if (null === $this->inner) {
            return false;
        }
        if (null === $this->config || null === $this->connection || null === $this->requestStack
            || null === $this->redirectModel || null === $this->recorder) {
            return $this->inner->isHitByBot($emailStat, $emailHitDateTime, $ipAddress, $userAgent);
        }

        // Master switch: when the integration is disabled (unpublished) the decorator is
        // transparent — standard Mautic core detection only, no burst signal, no capture.
        if (!$this->config->isEnabled()) {
            return $this->inner->isHitByBot($emailStat, $emailHitDateTime, $ipAddress, $userAgent);
        }

        if ($this->inner->isHitByBot($emailStat, $emailHitDateTime, $ipAddress, $userAgent)) {
            $this->capture($emailStat, $ipAddress, $userAgent, $emailHitDateTime, 'core-3signal', null);

            return true;
        }

        if (null !== ($evidence = $this->clickBurstEvidence($emailStat, $emailHitDateTime))) {
            $this->capture($emailStat, $ipAddress, $userAgent, $emailHitDateTime, 'burst-click', $evidence);

            return true;
        }

        if (null !== ($evidence = $this->openBurstEvidence($emailStat, $emailHitDateTime))) {
            $this->capture($emailStat, $ipAddress, $userAgent, $emailHitDateTime, 'burst-open', $evidence);

            return true;
        }

        return false;
    }

    /**
     * Thin bool wrapper retained so existing reflection tests (Tests/IntegrationTest.php,
     * which invoke isBurstScanner) keep working; isHitByBot uses the evidence methods directly.
     */
    private function isBurstScanner(Stat $emailStat, \DateTimeInterface $emailHitDateTime): bool
    {
        return null !== $this->clickBurstEvidence($emailStat, $emailHitDateTime)
            || null !== $this->openBurstEvidence($emailStat, $emailHitDateTime);
    }

    private function windowSince(\DateTimeInterface $emailHitDateTime): string
    {
        return (new \DateTime('@' . ($emailHitDateTime->getTimestamp() - $this->config->getWindowSeconds())))
            ->format('Y-m-d H:i:s');
    }

    /**
     * @return array{count:int, ips:string[]}|null evidence if a click burst is present, else null
     */
    private function clickBurstEvidence(Stat $emailStat, \DateTimeInterface $emailHitDateTime): ?array
    {
        $email = $emailStat->getEmail();
        $lead  = $emailStat->getLead();
        if (null === $email || null === $lead || null === $email->getId() || null === $lead->getId()) {
            return null;
        }

        $prefix = (string) MAUTIC_TABLE_PREFIX;
        // Runs on the live open/click path — a transient DB error must degrade to
        // "not a burst", never 500 the tracker.
        try {
            $ips = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT i.ip_address
                 FROM {$prefix}page_hits ph
                 JOIN {$prefix}ip_addresses i ON i.id = ph.ip_id
                 WHERE ph.email_id = :emailId AND ph.lead_id = :leadId AND ph.date_hit >= :since",
                ['emailId' => $email->getId(), 'leadId' => $lead->getId(), 'since' => $this->windowSince($emailHitDateTime)]
            );
        } catch (\Throwable $e) {
            return null;
        }

        $max = $this->config->getMaxDistinctIps();

        return count($ips) >= $max ? ['count' => count($ips), 'ips' => $ips] : null;
    }

    /**
     * @return array{count:int, ips:string[]}|null evidence if an open burst is present, else null
     */
    private function openBurstEvidence(Stat $emailStat, \DateTimeInterface $emailHitDateTime): ?array
    {
        $statId = $emailStat->getId();
        if (null === $statId) {
            return null;
        }

        $prefix = (string) MAUTIC_TABLE_PREFIX;
        // Runs on the live open/click path — a transient DB error must degrade to
        // "not a burst", never 500 the tracker.
        try {
            $ips = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT i.ip_address
                 FROM {$prefix}email_stats_devices d
                 JOIN {$prefix}ip_addresses i ON i.id = d.ip_id
                 WHERE d.stat_id = :statId AND d.date_opened >= :since",
                ['statId' => $statId, 'since' => $this->windowSince($emailHitDateTime)]
            );
        } catch (\Throwable $e) {
            return null;
        }

        $max = $this->config->getMaxDistinctIps();

        return count($ips) >= $max ? ['count' => count($ips), 'ips' => $ips] : null;
    }

    /**
     * @param array{count:int, ips:string[]}|null $evidence
     */
    private function capture(Stat $emailStat, IpAddress $ipAddress, string $userAgent, \DateTimeInterface $hit, string $reason, ?array $evidence): void
    {
        // Capture is a pure side-effect on the live open/click tracking path. Any failure
        // here (channel/redirect lookup, domain parsing) must NEVER propagate and break
        // core tracking — the recorder already swallows its own DB errors; this guards the
        // detection/parsing that runs before it.
        try {
            [$channel, $url] = $this->detectChannelAndUrl();

            $domain = null;
            $address = $emailStat->getEmailAddress();
            if (null === $address || '' === $address) {
                $address = $emailStat->getLead()?->getEmail();
            }
            if (is_string($address) && false !== ($at = strpos($address, '@'))) {
                $domain = substr($address, $at + 1) ?: null;
            }

            $this->recorder->record([
                'reason'            => $reason,
                'channel'           => $channel,
                'email_id'          => $emailStat->getEmail()?->getId(),
                'lead_id'           => $emailStat->getLead()?->getId(),
                'stat_id'           => $emailStat->getId(),
                'tracking_hash'     => $emailStat->getTrackingHash(),
                'email_domain'      => $domain,
                'ip'                => $ipAddress->getIpAddress(),
                'user_agent'        => $userAgent,
                'url'               => $url,
                'distinct_ip_count' => $evidence['count'] ?? null,
                'trigger_ips'       => $evidence['ips'] ?? null,
                'window_seconds'    => null !== $evidence ? $this->config->getWindowSeconds() : null,
                'date_hit'          => $hit,
            ]);
        } catch (\Throwable $e) {
            // Swallow — never let capture break a real open/click classification.
        }
    }

    /**
     * @return array{0:?string,1:?string} [channel, url]
     */
    private function detectChannelAndUrl(): array
    {
        $request = $this->requestStack->getCurrentRequest();
        if (null === $request) {
            return [null, null];
        }

        $route = $request->attributes->get('_route');
        if ('mautic_email_tracker' === $route) {
            return ['open', null];
        }
        if (in_array($route, ['mautic_url_redirect', 'mautic_page_redirect'], true)) {
            $url        = null;
            $redirectId = $request->attributes->get('redirectId');
            if (null !== $redirectId) {
                $redirect = $this->redirectModel->getRedirectById((string) $redirectId);
                $url      = $redirect?->getUrl();
            }

            return ['click', $url];
        }

        return [null, null];
    }
}
