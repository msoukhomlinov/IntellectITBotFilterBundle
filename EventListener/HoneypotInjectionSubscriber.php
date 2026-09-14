<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\EventListener;

use Mautic\EmailBundle\EmailEvents;
use Mautic\EmailBundle\Event\EmailSendEvent;
use MauticPlugin\IntellectITBotFilterBundle\Helper\ConfigProvider;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * Injects a hidden, NON-trackable honeypot link into outgoing email HTML when the
 * integration is published and honeypot injection is enabled. The
 * data-mautic-disable-tracking attribute is mandatory — without it core rewrites the
 * link into a tracked redirect and the honeypot route is never hit.
 */
class HoneypotInjectionSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RouterInterface $router,
        private ConfigProvider $config,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [EmailEvents::EMAIL_ON_SEND => ['onEmailSend', 0]];
    }

    public function onEmailSend(EmailSendEvent $event): void
    {
        if (!$this->config->isHoneypotEnabled()) {
            return;
        }

        $email = $event->getEmail();
        if (!$this->emailAllowed($email?->getId())) {
            return;
        }

        $idHash = $event->getIdHash();
        if (empty($idHash)) {
            return;
        }

        $content = $event->getContent();
        if (!str_contains($content, '</body>')) {
            return; // only inject into full HTML emails
        }

        $url    = $this->router->generate('mautic_botfilter_honeypot', ['h' => $idHash], UrlGeneratorInterface::ABSOLUTE_URL);
        $anchor = sprintf(
            '<a href="%s" data-mautic-disable-tracking="true" style="display:none;font-size:1px;color:#ffffff" aria-hidden="true">.</a>',
            htmlspecialchars($url, ENT_QUOTES)
        );

        $event->setContent(str_replace('</body>', $anchor.'</body>', $content));
    }

    private function emailAllowed(?int $emailId): bool
    {
        $csv = trim($this->config->getHoneypotEmailIds());
        if ('' === $csv) {
            return true; // empty allowlist = all emails
        }
        if (null === $emailId) {
            return false;
        }
        $ids = array_map('intval', array_filter(array_map('trim', explode(',', $csv)), 'strlen'));

        return in_array($emailId, $ids, true);
    }
}
