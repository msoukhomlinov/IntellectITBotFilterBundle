<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Helper;

use Doctrine\DBAL\Connection;
use GeoIp2\Database\Reader;

/**
 * Enriches an IP with geo (from the already-populated ip_addresses.ip_details, MaxMind
 * GeoLite2-City) + ASN/org (from an offline GeoLite2-ASN.mmdb read with the bundled geoip2
 * reader) + a datacenter/provider flag. Results are cached in botfilter_ip_enrichment.
 * Exception-safe: any failure yields a 'none'/partial record, never throws to the caller.
 */
class IpEnricher
{
    /** Curated major-cloud ASN → friendly provider. Extend over time. */
    private const DATACENTER_ASNS = [
        16509 => 'Amazon AWS', 14618 => 'Amazon AWS', 39111 => 'Amazon AWS',
        8075 => 'Microsoft Azure', 8068 => 'Microsoft', 8069 => 'Microsoft',
        15169 => 'Google Cloud', 396982 => 'Google Cloud', 19527 => 'Google Cloud',
        13335 => 'Cloudflare', 14061 => 'DigitalOcean', 20473 => 'Vultr/Choopa',
        16276 => 'OVH', 24940 => 'Hetzner', 14080 => 'Proofpoint', 26211 => 'Proofpoint',
    ];

    private ?Reader $asnReader = null;
    private bool $asnReaderTried = false;

    public function __construct(
        private Connection $connection,
        private string $dataDir,
    ) {
    }

    /**
     * @return array{ip:string,country:?string,city:?string,org:?string,asn:?int,is_datacenter:bool,provider:?string,source:string}
     */
    public function enrich(string $ip): array
    {
        $prefix = (string) MAUTIC_TABLE_PREFIX;

        try {
            $row = $this->connection->fetchAssociative(
                "SELECT ip, country, city, org, asn, is_datacenter, provider, source
                 FROM {$prefix}botfilter_ip_enrichment WHERE ip = :ip",
                ['ip' => $ip]
            );
            if ($row) {
                $row['asn']           = null !== $row['asn'] ? (int) $row['asn'] : null;
                $row['is_datacenter'] = (bool) $row['is_datacenter'];

                return $row;
            }
        } catch (\Throwable $e) {
            // fall through to compute
        }

        $result = $this->compute($ip);

        try {
            $this->connection->executeStatement(
                "INSERT INTO {$prefix}botfilter_ip_enrichment
                    (ip, country, city, org, asn, is_datacenter, provider, source, enriched_at)
                 VALUES (:ip,:country,:city,:org,:asn,:dc,:provider,:source,:at)
                 ON DUPLICATE KEY UPDATE country=:country, city=:city, org=:org, asn=:asn,
                    is_datacenter=:dc, provider=:provider, source=:source, enriched_at=:at",
                [
                    'ip' => $ip, 'country' => $result['country'], 'city' => $result['city'],
                    'org' => $result['org'], 'asn' => $result['asn'],
                    'dc' => $result['is_datacenter'] ? 1 : 0, 'provider' => $result['provider'],
                    'source' => $result['source'], 'at' => gmdate('Y-m-d H:i:s'),
                ]
            );
        } catch (\Throwable $e) {
            // cache write best-effort
        }

        return $result;
    }

    /**
     * @param string[] $ips
     * @return array<string,array> keyed by ip
     */
    public function enrichMany(array $ips): array
    {
        $out = [];
        foreach (array_unique($ips) as $ip) {
            if ('' !== (string) $ip) {
                $out[$ip] = $this->enrich((string) $ip);
            }
        }

        return $out;
    }

    /**
     * @return array{ip:string,country:?string,city:?string,org:?string,asn:?int,is_datacenter:bool,provider:?string,source:string}
     */
    private function compute(string $ip): array
    {
        $country = $city = $org = $provider = null;
        $asn = null;
        $isDc = false;
        $source = 'none';

        try {
            $prefix  = (string) MAUTIC_TABLE_PREFIX;
            $details = $this->connection->fetchOne(
                "SELECT ip_details FROM {$prefix}ip_addresses WHERE ip_address = :ip ORDER BY id DESC LIMIT 1",
                ['ip' => $ip]
            );
            if (is_string($details) && '' !== $details) {
                $g = @unserialize($details, ['allowed_classes' => false]);
                if (is_array($g)) {
                    $country = ($g['country'] ?? '') !== '' ? (string) $g['country'] : null;
                    $city    = ($g['city'] ?? '') !== '' ? (string) $g['city'] : null;
                    if (null !== $country || null !== $city) {
                        $source = 'ipdetails';
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore geo
        }

        $reader = $this->asnReader();
        if (null !== $reader) {
            try {
                $a   = $reader->asn($ip);
                $asn = $a->autonomousSystemNumber;
                $org = $a->autonomousSystemOrganization ?: null;
                if (null !== $asn) {
                    $source   = 'maxmind';
                    $provider = self::DATACENTER_ASNS[$asn] ?? null;
                    $isDc     = null !== $provider;
                }
            } catch (\Throwable $e) {
                // address not found / bad db
            }
        }

        if (null === $provider && null !== $org) {
            $provider = $org;
        }

        return [
            'ip' => $ip, 'country' => $country, 'city' => $city, 'org' => $org,
            'asn' => $asn, 'is_datacenter' => $isDc, 'provider' => $provider, 'source' => $source,
        ];
    }

    /**
     * Single source of truth for "the ASN database is stale", in seconds.
     *
     * Both status surfaces (the admin list health strip and the settings Diagnostics tab) MUST
     * compare against this same unrounded threshold. They previously used separate rules — the
     * strip compared raw seconds while Diagnostics compared a floored day count with `> 7` — so
     * anything between exactly 7 and 8 days old showed stale on one surface and healthy on the
     * other for almost a full day.
     */
    public const ASN_DB_STALE_SECONDS = 7 * 86400;

    /**
     * Filesystem stat only — no query. Used by the admin list page's health strip and the
     * settings diagnostics tab to show whether the offline ASN database is present and how
     * stale it is, without needing a lookup to succeed.
     *
     * @return array{exists: bool, mtime: ?int}
     */
    public function asnDbInfo(): array
    {
        $path = rtrim($this->dataDir, '/').'/GeoLite2-ASN.mmdb';
        if (!is_file($path)) {
            return ['exists' => false, 'mtime' => null];
        }
        $mtime = @filemtime($path);

        return ['exists' => true, 'mtime' => false !== $mtime ? $mtime : null];
    }

    private function asnReader(): ?Reader
    {
        if ($this->asnReaderTried) {
            return $this->asnReader;
        }
        $this->asnReaderTried = true;
        $path = rtrim($this->dataDir, '/').'/GeoLite2-ASN.mmdb';
        if (is_file($path)) {
            try {
                $this->asnReader = new Reader($path);
            } catch (\Throwable $e) {
                $this->asnReader = null;
            }
        }

        return $this->asnReader;
    }
}
