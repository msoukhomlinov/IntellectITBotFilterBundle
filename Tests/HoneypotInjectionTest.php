<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * HoneypotInjectionSubscriber checks with stubbed router and config (no database).
 *
 * Run (MAUTIC_ROOT defaults to /var/www/html; adjust the plugin path to where the
 * plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/HoneypotInjectionTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use Mautic\EmailBundle\Event\EmailSendEvent;
use Mautic\EmailBundle\Entity\Email;
use MauticPlugin\IntellectITBotFilterBundle\EventListener\HoneypotInjectionSubscriber;
use MauticPlugin\IntellectITBotFilterBundle\Helper\ConfigProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

// Stub ConfigProvider with fixed values (empty ctor avoids IntegrationHelper).
function stubConfig(bool $enabled, string $emailIds): ConfigProvider {
    return new class($enabled, $emailIds) extends ConfigProvider {
        public function __construct(private bool $en, private string $ids) {}
        public function isHoneypotEnabled(): bool { return $this->en; }
        public function getHoneypotEmailIds(): string { return $this->ids; }
        public function getMaxDistinctIps(): int { return 3; }
        public function getWindowSeconds(): int { return 30; }
    };
}

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name, var_export($got, true), var_export($want, true));
}

// Stub router: returns a fixed URL for the honeypot route.
$router = new class implements RouterInterface {
    public function generate(string $name, array $parameters = [], int $referenceType = UrlGeneratorInterface::ABSOLUTE_PATH): string {
        return 'https://engage.example/bf/honeypot?h=' . ($parameters['h'] ?? '');
    }
    public function setContext(\Symfony\Component\Routing\RequestContext $context): void {}
    public function getContext(): \Symfony\Component\Routing\RequestContext { return new \Symfony\Component\Routing\RequestContext(); }
    public function getRouteCollection(): \Symfony\Component\Routing\RouteCollection { return new \Symfony\Component\Routing\RouteCollection(); }
    public function match(string $pathinfo): array { return []; }
};

$sub = new HoneypotInjectionSubscriber($router, stubConfig(true, ''));

// Build an EmailSendEvent in non-send (preview-like) mode with content + idHash.
$event = new EmailSendEvent(null, [
    'content' => '<html><body><p>Hi</p></body></html>',
    'email'   => new Email(),
    'idHash'  => 'abc123',
    'lead'    => ['id' => 1],
    'source'  => ['email', 1],
    'tokens'  => [],
]);

$sub->onEmailSend($event);
$out = $event->getContent();

check('honeypot anchor injected', str_contains($out, '/bf/honeypot?h=abc123'), true);
check('anchor is non-trackable', str_contains($out, 'data-mautic-disable-tracking="true"'), true);
check('anchor before </body>', strpos($out, '/bf/honeypot') < strpos($out, '</body>'), true);

// Disabled -> no injection.
$sub2  = new HoneypotInjectionSubscriber($router, stubConfig(false, ''));
$event2 = new EmailSendEvent(null, ['content' => '<html><body>x</body></html>', 'email' => new Email(), 'idHash' => 'z', 'lead' => ['id' => 1], 'source' => ['email', 1], 'tokens' => []]);
$sub2->onEmailSend($event2);
check('disabled -> no injection', str_contains($event2->getContent(), '/bf/honeypot'), false);

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
