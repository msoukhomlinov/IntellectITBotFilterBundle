<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Integration test for BlockedHitRecorder: inserts inside a rolled-back transaction;
 * use a non-production instance. Skipped unless the BOTFILTER_TEST_* ids are set.
 *
 * Run (MAUTIC_ROOT defaults to /var/www/html; adjust the plugin path to where the
 * plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/RecorderTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use MauticPlugin\IntellectITBotFilterBundle\Helper\BlockedHitRecorder;
use Psr\Log\NullLogger;

$kernel = new AppKernel('prod', false);
$kernel->boot();
$container = $kernel->getContainer();

$conn = $container->get('doctrine.dbal.default_connection');
// Prod container does not expose monolog.logger/logger publicly; NullLogger is sufficient
// for this integration test (exception-safety check uses a real bad call, not a log assertion).
$logger = new NullLogger();

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name, var_export($got, true), var_export($want, true));
}

$recorder = new BlockedHitRecorder($conn, $logger);

$EMAIL = (int) getenv('BOTFILTER_TEST_EMAIL_ID');
$LEAD  = (int) getenv('BOTFILTER_TEST_LEAD_ID');
$STAT  = (int) getenv('BOTFILTER_TEST_STAT_ID');
if ($EMAIL <= 0 || $LEAD <= 0 || $STAT <= 0) {
    echo "[SKIP] set BOTFILTER_TEST_EMAIL_ID, BOTFILTER_TEST_LEAD_ID, BOTFILTER_TEST_STAT_ID\n";
    exit(0);
}

$conn->beginTransaction();
try {
    $before = (int) $conn->fetchOne('SELECT COUNT(*) FROM botfilter_blocked_hits');
    $recorder->record([
        'reason' => 'burst-open', 'channel' => 'open', 'email_id' => $EMAIL, 'lead_id' => $LEAD,
        'stat_id' => $STAT, 'tracking_hash' => 'testhash', 'email_domain' => 'example.com',
        'ip' => '203.0.113.9', 'user_agent' => 'UA', 'url' => null,
        'distinct_ip_count' => 3, 'trigger_ips' => ['1.1.1.1', '2.2.2.2', '3.3.3.3'],
        'window_seconds' => 30, 'date_hit' => new DateTime(),
    ]);
    $after = (int) $conn->fetchOne('SELECT COUNT(*) FROM botfilter_blocked_hits');
    check('recorder inserts one row', $after - $before, 1);

    $row = $conn->fetchAssociative('SELECT reason, distinct_ip_count, trigger_ips FROM botfilter_blocked_hits ORDER BY id DESC LIMIT 1');
    check('reason stored', $row['reason'], 'burst-open');
    check('trigger_ips is json', $row['trigger_ips'], '["1.1.1.1","2.2.2.2","3.3.3.3"]');
} finally {
    $conn->rollBack();
}

// Exception-safety: record() must NEVER throw. record() succeeds on this input and
// would INSERT, so wrap in its own rolled-back transaction — otherwise this persists
// a junk row into the real table (it runs outside the block above).
$threw = false;
$conn->beginTransaction();
try {
    $recorder->record(['reason' => 'x', 'bogus_key_not_a_column' => 1]); // unknown keys ignored; asserts no throw
} catch (\Throwable $e) {
    $threw = true;
} finally {
    $conn->rollBack();
}
check('record never throws on normal input', $threw, false);

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
