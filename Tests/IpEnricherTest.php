<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * IpEnricher checks: enrich() returns the expected shape and caches one row; a second
 * lookup is a cache hit. Writes inside a rolled-back transaction; use a non-production
 * instance.
 *
 * Run (MAUTIC_ROOT defaults to /var/www/html; adjust the plugin path to where the
 * plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/IpEnricherTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use MauticPlugin\IntellectITBotFilterBundle\Helper\IpEnricher;

$kernel = new AppKernel('prod', false);
$kernel->boot();
$c    = $kernel->getContainer();
$conn = $c->get('doctrine.dbal.default_connection');

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name, var_export($got, true), var_export($want, true));
}

$enricher = new IpEnricher($conn, $mauticRoot.'/var/cache/ip_data');

$conn->beginTransaction();
try {
    $before = (int) $conn->fetchOne('SELECT COUNT(*) FROM botfilter_ip_enrichment');
    $r = $enricher->enrich('8.8.8.8');
    check('returns ip', $r['ip'], '8.8.8.8');
    check('has source key', array_key_exists('source', $r), true);
    check('is_datacenter is bool', is_bool($r['is_datacenter']), true);
    $after = (int) $conn->fetchOne('SELECT COUNT(*) FROM botfilter_ip_enrichment');
    check('cached one row', $after - $before, 1);

    $enricher2 = new IpEnricher($conn, $mauticRoot.'/var/cache/ip_data');
    $enricher2->enrich('8.8.8.8');
    $after2 = (int) $conn->fetchOne('SELECT COUNT(*) FROM botfilter_ip_enrichment');
    check('cache hit writes no extra row', $after2 - $after, 0);
} finally {
    $conn->rollBack();
}

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
