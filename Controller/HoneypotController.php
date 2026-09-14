<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Controller;

use Mautic\EmailBundle\Entity\StatRepository;
use MauticPlugin\IntellectITBotFilterBundle\Helper\BlockedHitRecorder;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class HoneypotController
{
    public function __construct(
        private StatRepository $statRepository,
        private BlockedHitRecorder $recorder,
    ) {
    }

    /**
     * Public, unauthenticated. A scanner that fetched the hidden link lands here.
     * Always returns 204 (no body, no hash-enumeration leak).
     */
    public function hitAction(Request $request): Response
    {
        // Read via all() — both get() and getString() throw BadRequestException (→400) on
        // an array param like ?h[]=x. all() never throws; a non-string value becomes ''.
        // A public endpoint must ALWAYS return 204, never leak a distinguishable status.
        $raw  = $request->query->all()['h'] ?? '';
        $hash = is_string($raw) ? $raw : '';

        if ('' !== $hash) {
            $stat = $this->statRepository->getEmailStatus($hash);
            if (null !== $stat) {
                $address = $stat->getEmailAddress();
                if (null === $address || '' === $address) {
                    $address = $stat->getLead()?->getEmail();
                }
                $domain = (is_string($address) && false !== ($at = strpos($address, '@')))
                    ? (substr($address, $at + 1) ?: null)
                    : null;

                $this->recorder->record([
                    'reason'        => 'honeypot',
                    'channel'       => null,
                    'email_id'      => $stat->getEmail()?->getId(),
                    'lead_id'       => $stat->getLead()?->getId(),
                    'stat_id'       => $stat->getId(),
                    'tracking_hash' => $stat->getTrackingHash(),
                    'email_domain'  => $domain,
                    'ip'            => $request->getClientIp(),
                    'user_agent'    => (string) $request->headers->get('User-Agent', ''),
                    'date_hit'      => new \DateTime(),
                ]);
            }
        }

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
