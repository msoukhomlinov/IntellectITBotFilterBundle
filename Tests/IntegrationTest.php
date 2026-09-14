<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Runtime integration test of the LIVE decorated BotRatioHelper.
 *
 * Boots the Mautic kernel, resolves the decorated service from the container,
 * inserts a synthetic burst inside a transaction, asserts behaviour, then ROLLS
 * BACK — it persists NOTHING (verified by comparing page_hits COUNT before/after).
 *
 * It does write+rollback against the configured database's page_hits table, so run it
 * deliberately and against a non-production instance (MAUTIC_ROOT defaults to
 * /var/www/html; adjust the plugin path to where the plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/IntegrationTest.php
 *
 * Fixtures come from env (skipped when unset): BOTFILTER_TEST_STAT_ID (a Stat with
 * email+lead), BOTFILTER_TEST_EMAIL_ID, BOTFILTER_TEST_LEAD_ID, and
 * BOTFILTER_TEST_IP_IDS (three comma-separated ip_addresses ids).
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use Mautic\CoreBundle\Entity\IpAddress;
use Mautic\EmailBundle\Entity\Stat;

$kernel = new AppKernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();

/** @var \Doctrine\DBAL\Connection $conn */
$conn = $container->get('doctrine.dbal.default_connection');
/** @var \Doctrine\ORM\EntityManagerInterface $em */
$em = $container->get('doctrine')->getManager();

// Public alias resolves to the decorator (VelocityBotRatioHelper).
$helper = $container->get('Mautic\EmailBundle\Helper\BotRatioHelper');
echo 'Resolved service class: ' . get_class($helper) . "\n";

// Fixtures from env; checked before the integration is touched so a skip writes nothing.
$EMAIL = (int) getenv('BOTFILTER_TEST_EMAIL_ID');
$LEAD  = (int) getenv('BOTFILTER_TEST_LEAD_ID');
$STAT  = (int) getenv('BOTFILTER_TEST_STAT_ID');
$ips   = array_values(array_filter(array_map('intval', explode(',', (string) getenv('BOTFILTER_TEST_IP_IDS')))));
if ($EMAIL <= 0 || $LEAD <= 0 || $STAT <= 0 || count($ips) < 3) {
    echo "[SKIP] set BOTFILTER_TEST_EMAIL_ID, BOTFILTER_TEST_LEAD_ID, BOTFILTER_TEST_STAT_ID, BOTFILTER_TEST_IP_IDS (3 ids)\n";
    exit(0);
}

// Master switch: the decorator only runs the burst signal when the integration
// is PUBLISHED. Ensure it is published for this test (save original, restore at the end),
// otherwise isHitByBot short-circuits to core and the burst assertions can't fire. Done
// before any helper call so ConfigProvider memoises the published state.
$intgObj    = $container->get('mautic.helper.integration')->getIntegrationObject('IntellectITBotFilter');
$intgEntity = $intgObj ? $intgObj->getIntegrationSettings() : null;
$origPublished = $intgEntity ? $intgEntity->isPublished() : null;
$origFeatures  = $intgEntity ? $intgEntity->getFeatureSettings() : null;
if ($intgEntity) {
    // Published + KNOWN thresholds (3/30) so the 3-IP fixtures below trip deterministically,
    // regardless of whatever the live UI config currently holds. Restored at the end.
    $intgEntity->setIsPublished(true);
    $intgEntity->setFeatureSettings(['honeypot_enabled' => false, 'honeypot_email_ids' => '', 'max_distinct_ips' => 3, 'window_seconds' => 30]);
    $em->persist($intgEntity); $em->flush();
}

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name, var_export($got, true), var_export($want, true));
}

$stat = $em->getRepository(Stat::class)->find($STAT);
if (!$stat || !$stat->getEmail() || !$stat->getLead()) {
    fwrite(STDERR, "fixture stat unusable — adjust \$STAT\n"); exit(2);
}

$refHelper = new ReflectionObject($helper);
$isBurst   = $refHelper->getMethod('isBurstScanner'); $isBurst->setAccessible(true);

$ua  = 'IntegrationTest-UA';
$ip  = new IpAddress('203.0.113.9');
$now = new DateTime(); // epoch is timezone-independent; decorator derives a UTC bound

$before = (int) $conn->fetchOne('SELECT COUNT(*) FROM page_hits');

$conn->beginTransaction();
try {
    // POSITIVE: 3 distinct IPs within ~10s (UTC date_hit to match the decorator).
    foreach ([0, 5, 10] as $i => $off) {
        $conn->executeStatement(
            'INSERT INTO page_hits (redirect_id, email_id, lead_id, ip_id, date_hit, code, tracking_id, source)
             VALUES (NULL, :e, :l, :ip, UTC_TIMESTAMP() - INTERVAL :off SECOND, 200, :tid, :src)',
            ['e' => $EMAIL, 'l' => $LEAD, 'ip' => $ips[$i], 'off' => $off, 'tid' => 'bftest-' . $i, 'src' => 'email']
        );
    }
    check('burst (3 distinct IPs/10s) -> isBurstScanner true', $isBurst->invoke($helper, $stat, $now), true);
    check('burst -> isHitByBot true', $helper->isHitByBot($stat, $now, $ip, $ua), true);

    // NEGATIVE A: single IP remaining.
    $conn->executeStatement("DELETE FROM page_hits WHERE tracking_id IN ('bftest-1','bftest-2')");
    check('single IP -> isBurstScanner false', $isBurst->invoke($helper, $stat, $now), false);
    check('single IP -> isHitByBot false', $helper->isHitByBot($stat, $now, $ip, $ua), false);

    // NEGATIVE B: 3 rows, same IP -> distinct count 1.
    $conn->executeStatement("DELETE FROM page_hits WHERE tracking_id LIKE 'bftest-%'");
    foreach ([0, 3, 6] as $k => $off) {
        $conn->executeStatement(
            'INSERT INTO page_hits (email_id, lead_id, ip_id, date_hit, code, tracking_id, source)
             VALUES (:e,:l,:ip, UTC_TIMESTAMP() - INTERVAL :off SECOND, 200, :tid, :src)',
            ['e' => $EMAIL, 'l' => $LEAD, 'ip' => $ips[0], 'off' => $off, 'tid' => 'bfsame-' . $k, 'src' => 'email']
        );
    }
    check('3 rows same IP -> isBurstScanner false', $isBurst->invoke($helper, $stat, $now), false);

    // NEGATIVE C: 3 distinct IPs spread beyond the window.
    $conn->executeStatement("DELETE FROM page_hits WHERE tracking_id LIKE 'bfsame-%'");
    foreach ($ips as $i => $ipId) {
        $conn->executeStatement(
            'INSERT INTO page_hits (email_id, lead_id, ip_id, date_hit, code, tracking_id, source)
             VALUES (:e,:l,:ip, UTC_TIMESTAMP() - INTERVAL :off SECOND, 200, :tid, :src)',
            ['e' => $EMAIL, 'l' => $LEAD, 'ip' => $ipId, 'off' => $i * 60, 'tid' => 'bfspread-' . $i, 'src' => 'email']
        );
    }
    check('3 IPs spread 60s apart -> isBurstScanner false', $isBurst->invoke($helper, $stat, $now), false);

    // ---- OPEN BURST: opens are stored in email_stats_devices, not page_hits ----
    // 3 distinct IPs opening the same stat within ~10s = scanner open burst.
    foreach ([0, 5, 10] as $k => $off) {
        $conn->executeStatement(
            'INSERT INTO email_stats_devices (device_id, stat_id, ip_id, date_opened)
             VALUES (NULL, :stat, :ip, UTC_TIMESTAMP() - INTERVAL :off SECOND)',
            ['stat' => $STAT, 'ip' => $ips[$k], 'off' => $off]
        );
    }
    check('open burst (3 distinct IPs/10s) -> isBurstScanner true', $isBurst->invoke($helper, $stat, $now), true);

    // Negative: 3 opens, same IP -> distinct count 1 -> no burst.
    $conn->executeStatement("DELETE FROM email_stats_devices WHERE stat_id = :stat AND ip_id IN (:a,:b)",
        ['stat' => $STAT, 'a' => $ips[1], 'b' => $ips[2]]);
    check('opens same IP -> isBurstScanner false', $isBurst->invoke($helper, $stat, $now), false);
    $conn->executeStatement("DELETE FROM email_stats_devices WHERE stat_id = :stat AND ip_id = :a",
        ['stat' => $STAT, 'a' => $ips[0]]);

    // Negative (opens): 3 distinct IPs spread beyond the window -> no burst.
    foreach ($ips as $k => $ipId) {
        $conn->executeStatement(
            'INSERT INTO email_stats_devices (device_id, stat_id, ip_id, date_opened)
             VALUES (NULL, :stat, :ip, UTC_TIMESTAMP() - INTERVAL :off SECOND)',
            ['stat' => $STAT, 'ip' => $ipId, 'off' => $k * 60]
        );
    }
    check('opens spread 60s apart -> isBurstScanner false', $isBurst->invoke($helper, $stat, $now), false);
    $conn->executeStatement("DELETE FROM email_stats_devices WHERE stat_id = :stat AND ip_id IN (:a,:b,:c)",
        ['stat' => $STAT, 'a' => $ips[0], 'b' => $ips[1], 'c' => $ips[2]]);

    // ---- CAPTURE: a burst classification writes a botfilter_blocked_hits row ----
    $capBefore = (int) $conn->fetchOne('SELECT COUNT(*) FROM botfilter_blocked_hits');
    foreach ([0, 5, 10] as $k => $off) {
        $conn->executeStatement(
            'INSERT INTO email_stats_devices (device_id, stat_id, ip_id, date_opened)
             VALUES (NULL, :stat, :ip, UTC_TIMESTAMP() - INTERVAL :off SECOND)',
            ['stat' => $STAT, 'ip' => $ips[$k], 'off' => $off]
        );
    }
    $helper->isHitByBot($stat, $now, $ip, $ua); // open burst -> should record reason=burst-open
    $capAfter = (int) $conn->fetchOne('SELECT COUNT(*) FROM botfilter_blocked_hits');
    check('capture wrote a blocked_hits row', $capAfter - $capBefore, 1);
    $capRow = $conn->fetchAssociative('SELECT reason, distinct_ip_count FROM botfilter_blocked_hits ORDER BY id DESC LIMIT 1');
    check('capture reason is burst-open', $capRow['reason'], 'burst-open');
    check('capture has distinct_ip_count >= 3', $capRow['distinct_ip_count'] >= 3, true);
    $conn->executeStatement("DELETE FROM email_stats_devices WHERE stat_id = :stat AND ip_id IN (:a,:b,:c)",
        ['stat' => $STAT, 'a' => $ips[0], 'b' => $ips[1], 'c' => $ips[2]]);
} finally {
    $conn->rollBack();
}

check('rollback left page_hits unchanged', (int) $conn->fetchOne('SELECT COUNT(*) FROM page_hits'), $before);

// Restore the integration's original published state + feature settings (non-destructive).
if ($intgEntity) {
    $intgEntity->setIsPublished((bool) $origPublished);
    $intgEntity->setFeatureSettings(is_array($origFeatures) ? $origFeatures : []);
    $em->persist($intgEntity); $em->flush();
}

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
