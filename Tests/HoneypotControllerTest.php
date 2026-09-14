<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * HoneypotController checks: valid hash records one row, invalid hash records none,
 * both return 204. Writes inside a rolled-back transaction; use a non-production
 * instance. Skipped unless BOTFILTER_TEST_STAT_ID is set.
 *
 * Run (MAUTIC_ROOT defaults to /var/www/html; adjust the plugin path to where the
 * plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/HoneypotControllerTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use Mautic\EmailBundle\Entity\Stat;
use MauticPlugin\IntellectITBotFilterBundle\Controller\HoneypotController;
use MauticPlugin\IntellectITBotFilterBundle\Helper\BlockedHitRecorder;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

$kernel = new AppKernel('prod', false);
$kernel->boot();
$c = $kernel->getContainer();

$conn      = $c->get('doctrine.dbal.default_connection');
$statRepo  = $c->get('doctrine')->getManager()->getRepository(Stat::class);
// Prod container does not expose private services by FQCN; instantiate directly (same pattern as RecorderTest.php).
$recorder  = new BlockedHitRecorder($conn, new NullLogger());

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name, var_export($got, true), var_export($want, true));
}

// A real stat hash from the DB (same BOTFILTER_TEST_STAT_ID as the rest of the suite).
$statId = (int) getenv('BOTFILTER_TEST_STAT_ID');
if ($statId <= 0) { echo "[SKIP] set BOTFILTER_TEST_STAT_ID\n"; exit(0); }
$hash = (string) $conn->fetchOne('SELECT tracking_hash FROM email_stats WHERE id = ?', [$statId]);
if ('' === $hash) { fwrite(STDERR, "no tracking_hash for stat $statId\n"); exit(2); }

$controller = new HoneypotController($statRepo, $recorder);

$conn->beginTransaction();
try {
    $before = (int) $conn->fetchOne("SELECT COUNT(*) FROM botfilter_blocked_hits WHERE reason='honeypot'");

    $resp = $controller->hitAction(Request::create('/bf/honeypot', 'GET', ['h' => $hash]));
    check('valid hash -> 204', $resp->getStatusCode(), 204);
    $afterValid = (int) $conn->fetchOne("SELECT COUNT(*) FROM botfilter_blocked_hits WHERE reason='honeypot'");
    check('valid hash recorded a honeypot row', $afterValid - $before, 1);

    $resp2 = $controller->hitAction(Request::create('/bf/honeypot', 'GET', ['h' => 'definitely-not-a-real-hash']));
    check('invalid hash -> 204', $resp2->getStatusCode(), 204);
    $afterInvalid = (int) $conn->fetchOne("SELECT COUNT(*) FROM botfilter_blocked_hits WHERE reason='honeypot'");
    check('invalid hash wrote no row', $afterInvalid - $afterValid, 0);
} finally {
    $conn->rollBack();
}

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
