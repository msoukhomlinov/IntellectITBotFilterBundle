<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Helper;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Writes one row per decorator-classified bot hit into botfilter_blocked_hits.
 * Exception-safe: any failure (missing table, DB error) is logged and swallowed
 * so it can NEVER drop a legitimate hit or break core tracking.
 */
class BlockedHitRecorder
{
    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string,mixed> $data keys: reason (required), channel, email_id,
     *   lead_id, stat_id, tracking_hash, email_domain, ip, user_agent, url,
     *   distinct_ip_count, trigger_ips (array), window_seconds, date_hit (\DateTimeInterface|string)
     */
    public function record(array $data): void
    {
        try {
            $prefix = (string) MAUTIC_TABLE_PREFIX;

            $url = $data['url'] ?? null;
            if (is_string($url) && strlen($url) > 2048) {
                $url = substr($url, 0, 2048);
            }

            $triggerIps = $data['trigger_ips'] ?? null;
            if (is_array($triggerIps)) {
                $triggerIps = json_encode(array_values($triggerIps));
            }

            $dateHit = $data['date_hit'] ?? null;
            if ($dateHit instanceof \DateTimeInterface) {
                $dateHit = $dateHit->format('Y-m-d H:i:s');
            }

            $this->connection->insert($prefix.'botfilter_blocked_hits', [
                'reason'            => $data['reason'],
                'channel'           => $data['channel'] ?? null,
                'email_id'          => $data['email_id'] ?? null,
                'lead_id'           => $data['lead_id'] ?? null,
                'stat_id'           => $data['stat_id'] ?? null,
                'tracking_hash'     => $data['tracking_hash'] ?? null,
                'email_domain'      => $data['email_domain'] ?? null,
                'ip'                => $data['ip'] ?? null,
                'user_agent'        => isset($data['user_agent']) ? substr((string) $data['user_agent'], 0, 255) : null,
                'url'               => $url,
                'distinct_ip_count' => $data['distinct_ip_count'] ?? null,
                'trigger_ips'       => $triggerIps,
                'window_seconds'    => $data['window_seconds'] ?? null,
                'date_hit'          => $dateHit,
                'date_captured'     => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('BotFilter: failed to record blocked hit: '.$e->getMessage());
        }
    }
}
