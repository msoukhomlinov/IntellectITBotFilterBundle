<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\EventListener;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Events;
use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\IntellectITBotFilterBundle\Helper\Utf8Sanitiser;

/**
 * Makes a page hit storable when the request that produced it carried bytes that
 * are not valid UTF-8.
 *
 * The failure this prevents, seen in the wild: a vulnerability scanner sends
 * `POST /com.example.TestService` whose body is a serialized Java object
 * (`\xAC\xED\x00\x05...`). PageModel::getHitQuery() merges GET and POST
 * wholesale into Hit::$query, Doctrine serializes that array into the utf8mb4
 * `page_hits.query` column, and MySQL in STRICT_TRANS_TABLES mode rejects the
 * INSERT with `1366 Incorrect string value`. Doctrine then closes the
 * EntityManager, so the rest of the request dies too and the caller gets a 500.
 * When `MAUTIC_MESSENGER_DSN_HIT` is `sync://default` the damage is confined to
 * the one request: nothing is queued and nothing retries.
 *
 * Every field sanitised below is client-controlled. The geo columns (country,
 * region, city, isp, organization) are not — they come from the MaxMind
 * databases — so they are deliberately left alone.
 *
 * prePersist only. A hit is INSERTed once and the later UPDATEs touch dateLeft
 * and source, neither of which is client-supplied; preUpdate would also require
 * the changeset API to make a modification stick, which is a sharper edge than
 * the problem warrants.
 */
#[AsDoctrineListener(event: Events::prePersist)]
final class HitEncodingSubscriber
{
    /**
     * Width of the page_hits varchar columns sanitised below, as reported by
     * information_schema.
     * Sanitising can lengthen a value (U+FFFD is three bytes), and an over-long
     * value is error 1406 under STRICT_TRANS_TABLES — one failure class swapped
     * for another. The longtext fields (query, url, referer, user_agent,
     * browser_languages) need no cap.
     */
    private const VARCHAR_LIMIT = 191;

    public function prePersist(PrePersistEventArgs $args): void
    {
        $hit = $args->getObject();

        if (!$hit instanceof Hit) {
            return;
        }

        $this->sanitiseHit($hit);
    }

    /**
     * Split out from prePersist so it can be exercised against a real Hit without
     * constructing Doctrine event arguments (which need an EntityManager).
     */
    public function sanitiseHit(Hit $hit): void
    {
        // longtext columns: sanitise, no length cap.
        $hit->setQuery(Utf8Sanitiser::sanitise($hit->getQuery()));
        $hit->setBrowserLanguages(Utf8Sanitiser::sanitise($hit->getBrowserLanguages()));

        if (null !== ($url = $hit->getUrl())) {
            $hit->setUrl(Utf8Sanitiser::sanitiseString($url));
        }

        if (null !== ($referer = $hit->getReferer())) {
            $hit->setReferer(Utf8Sanitiser::sanitiseString($referer));
        }

        if (null !== ($userAgent = $hit->getUserAgent())) {
            $hit->setUserAgent(Utf8Sanitiser::sanitiseString($userAgent));
        }

        // varchar(191) columns: sanitise and cap.
        if (null !== ($urlTitle = $hit->getUrlTitle())) {
            $hit->setUrlTitle(Utf8Sanitiser::sanitiseString($urlTitle, self::VARCHAR_LIMIT));
        }

        if (null !== ($remoteHost = $hit->getRemoteHost())) {
            $hit->setRemoteHost(Utf8Sanitiser::sanitiseString($remoteHost, self::VARCHAR_LIMIT));
        }

        if (null !== ($pageLanguage = $hit->getPageLanguage())) {
            $hit->setPageLanguage(Utf8Sanitiser::sanitiseString($pageLanguage, self::VARCHAR_LIMIT));
        }

        if (null !== ($trackingId = $hit->getTrackingId())) {
            $hit->setTrackingId(Utf8Sanitiser::sanitiseString($trackingId, self::VARCHAR_LIMIT));
        }
    }
}
