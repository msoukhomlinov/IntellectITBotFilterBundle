<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * End-to-end check that the real Doctrine stack now stores a page hit whose
 * request carried non-UTF-8 bytes.
 *
 * Everything happens inside a transaction that is ALWAYS rolled back, so no
 * page_hits row survives. The INSERT is genuinely executed, so a regression
 * raises 1366 here exactly as an unsanitised hit does under STRICT_TRANS_TABLES.
 *
 * Writes (then rolls back) against the configured Mautic database — use a
 * non-production instance.
 *
 * Run as the web server user (MAUTIC_ROOT defaults to /var/www/html; adjust the
 * plugin path to where the plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/HitEncodingLiveTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\IntellectITBotFilterBundle\Helper\Utf8Sanitiser;

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name,
        var_export(is_string($got) ? substr($got, 0, 50) : $got, true),
        var_export(is_string($want) ? substr($want, 0, 50) : $want, true));
}

$kernel = new AppKernel('prod', false);
$kernel->boot();
$c  = $kernel->getContainer();
$em = $c->get('doctrine')->getManager();

// The listener must be wired into the REAL entity manager, not merely exist.
$listeners = $em->getEventManager()->getListeners('prePersist');
$found     = false;
foreach ($listeners as $l) {
    if ($l instanceof \MauticPlugin\IntellectITBotFilterBundle\EventListener\HitEncodingSubscriber) {
        $found = true;
    }
}
check('listener attached to prePersist', $found, true);

// Real-world scanner payload (serialized Java object), verbatim from a Mautic error log.
$javaSerialized = "\xAC\xED\x00\x05sr\x00\x11java.util.HashMap";

$conn = $em->getConnection();
$conn->beginTransaction();

// The write and the read-back are tracked SEPARATELY and each keeps its own
// error. Folding them into one try/catch let a read failure land after
// $inserted was already true, so "INSERT succeeded" still passed while $stored
// stayed null — and the round-trip block below, being conditional on $stored,
// then silently did not run. Three checks would report success where nine were
// expected, and the process would still exit 0: a value ("no row") doubling as
// "I could not tell".
$hitId = null; $stored = null;
$inserted = false; $insertError = '';
$readOk   = false; $readError   = '';

try {
    $hit = new Hit();
    $hit->setDateHit(new \DateTime());
    $hit->setQuery(['payload' => $javaSerialized, 'page_url' => 'https://example.test/bf-live-check']);
    $hit->setUrl('https://example.test/bf-live-check');
    $hit->setUserAgent("bf-live-check \xAC\xED");
    $hit->setCode(200);
    $hit->setTrackingId('bf-live-check');

    $em->persist($hit);
    $em->flush();
    $inserted = true;
    $hitId    = $hit->getId();
} catch (\Throwable $e) {
    $insertError = substr($e->getMessage(), 0, 140);
}

if ($inserted) {
    try {
        // A read error and "no such row" must not collapse into one value:
        // $readOk records that the query RAN, $stored what it returned.
        $row    = $conn->fetchAssociative('SELECT query, user_agent FROM page_hits WHERE id = ?', [$hitId]);
        $readOk = true;
        $stored = false === $row ? null : $row;
    } catch (\Throwable $e) {
        $readError = substr($e->getMessage(), 0, 140);
    }
}

// Roll back before asserting, so a failing assertion cannot leave the row behind.
if ($conn->isTransactionActive()) {
    $conn->rollBack();
}

check('INSERT succeeded', $inserted, true);
if (!$inserted) { printf("       reason: %s\n", $insertError); }

// Unconditional, all three of them: without these, every assertion below lives
// inside `if (null !== $stored)` and a null simply skips them.
check('flush assigned an id',        is_int($hitId) && $hitId > 0, true);
check('read-back query executed',    $readOk, true);
if (!$readOk && '' !== $readError) { printf("       reason: %s\n", $readError); }
check('row was readable after flush', null !== $stored, true);

if (null !== $stored) {
    $query = unserialize((string) $stored['query']);
    check('query stored as an array',   is_array($query), true);
    check('clean key round trips',      $query['page_url'] ?? null, 'https://example.test/bf-live-check');
    check('payload is valid utf8',      Utf8Sanitiser::isValid($query['payload'] ?? ''), true);
    check('ascii tail survived',        str_contains($query['payload'] ?? '', 'java.util.HashMap'), true);
    check('user agent is valid utf8',   Utf8Sanitiser::isValid((string) $stored['user_agent']), true);
    check('user agent tail survived',   str_contains((string) $stored['user_agent'], 'bf-live-check'), true);
} else {
    // Belt and braces: name the six that did not run, so a reader of the output
    // cannot mistake a short green run for a complete one.
    printf("       NOTE: 6 round-trip assertions did not run (no row to read)\n");
}


// Prove the rollback actually happened — nothing may remain.
$leftover = (int) $conn->fetchOne("SELECT COUNT(*) FROM page_hits WHERE tracking_id = 'bf-live-check'");
check('no row left behind', $leftover, 0);

// Assert the check COUNT as a value. Every assertion about the stored row lives
// inside `if (null !== $stored)`, so losing that block is otherwise a shorter
// green run rather than a failure. Evaluated before incrementing, so it cannot
// count itself.
$expected = 12;
$ran      = $pass + $fail;
if ($ran !== $expected) {
    ++$fail;
    printf("[FAIL] check count (%d ran, %d expected — a block of assertions was skipped)\n", $ran, $expected);
} else {
    ++$pass;
    printf("[PASS] check count (all %d expected checks ran)\n", $ran);
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
