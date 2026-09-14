<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Pure-logic validation of RecountBotStatsCommand's private algorithms.
 *
 * No database, no kernel — exercises burstRowIds() and pruneOpenDetails() via
 * reflection with crafted inputs. Safe to run anywhere the autoloader resolves.
 *
 * Run (MAUTIC_ROOT defaults to /var/www/html; adjust the plugin path to where the
 * plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/LogicTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use MauticPlugin\IntellectITBotFilterBundle\Command\RecountBotStatsCommand;

$ref  = new ReflectionClass(RecountBotStatsCommand::class);
$inst = $ref->newInstanceWithoutConstructor(); // ctor needs a Connection; these methods do not

$burst = $ref->getMethod('burstRowIds');      $burst->setAccessible(true);
$prune = $ref->getMethod('pruneOpenDetails');  $prune->setAccessible(true);

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s\n", $ok ? 'PASS' : 'FAIL', $name);
    if (!$ok) {
        echo '   got:  ' . json_encode($got) . "\n   want: " . json_encode($want) . "\n";
    }
}

$dev   = fn($id, $ip, $dt) => ['id' => $id, 'ip_id' => $ip, 'date_opened' => $dt];
$entry = fn($dt) => ['datetime' => $dt, 'useragent' => 'x', 'inBrowser' => false];

// ---- burstRowIds (window default 30s, min 3 distinct IPs) ----
check('single-IP 5 rows -> none',
    $burst->invoke($inst, [
        $dev(1,7,'2026-01-01 00:00:00'), $dev(2,7,'2026-01-01 00:00:05'),
        $dev(3,7,'2026-01-01 00:00:10'), $dev(4,7,'2026-01-01 00:00:15'),
        $dev(5,7,'2026-01-01 00:00:20'),
    ], 30, 3), []);

check('3 IPs in 20s -> all',
    $burst->invoke($inst, [
        $dev(10,1,'2026-01-01 00:00:00'), $dev(11,2,'2026-01-01 00:00:10'),
        $dev(12,3,'2026-01-01 00:00:20'),
    ], 30, 3), [10,11,12]);

check('3 IPs over 50s -> none',
    $burst->invoke($inst, [
        $dev(20,1,'2026-01-01 00:00:00'), $dev(21,2,'2026-01-01 00:00:25'),
        $dev(22,3,'2026-01-01 00:00:50'),
    ], 30, 3), []);

check('boundary exactly 30s -> none (strict <)',
    $burst->invoke($inst, [
        $dev(30,1,'2026-01-01 00:00:00'), $dev(31,2,'2026-01-01 00:00:15'),
        $dev(32,3,'2026-01-01 00:00:30'),
    ], 30, 3), []);

check('boundary 29s -> all',
    $burst->invoke($inst, [
        $dev(40,1,'2026-01-01 00:00:00'), $dev(41,2,'2026-01-01 00:00:15'),
        $dev(42,3,'2026-01-01 00:00:29'),
    ], 30, 3), [40,41,42]);

check('4 rows 2 distinct IPs -> none',
    $burst->invoke($inst, [
        $dev(50,1,'2026-01-01 00:00:00'), $dev(51,2,'2026-01-01 00:00:02'),
        $dev(52,1,'2026-01-01 00:00:04'), $dev(53,2,'2026-01-01 00:00:06'),
    ], 30, 3), []);

check('isolated early hits, late 3-IP cluster -> only cluster',
    $burst->invoke($inst, [
        $dev(60,1,'2026-01-01 00:00:00'),
        $dev(61,1,'2026-01-01 00:05:00'),
        $dev(62,2,'2026-01-01 00:05:05'),
        $dev(63,3,'2026-01-01 00:05:10'),
    ], 30, 3), [61,62,63]);

// ---- pruneOpenDetails ----
$mk = fn($entries) => serialize($entries);

[$blob, $rem] = $prune->invoke($inst,
    $mk([$entry('2026-01-01 00:00:00'), $entry('2026-01-01 00:00:10'), $entry('2026-01-01 00:00:20')]),
    ['2026-01-01 00:00:10' => 1]);
check('prune middle: count', $rem['count'], 2);
check('prune middle: first', $rem['first'], '2026-01-01 00:00:00');
check('prune middle: last',  $rem['last'],  '2026-01-01 00:00:20');
check('prune middle: kept datetimes',
    array_map(fn($e) => $e['datetime'], unserialize($blob)),
    ['2026-01-01 00:00:00', '2026-01-01 00:00:20']);

[, $rem2] = $prune->invoke($inst,
    $mk([$entry('2026-01-01 00:00:00'), $entry('2026-01-01 00:00:00')]),
    ['2026-01-01 00:00:00' => 1]);
check('duplicate timestamp: removes only one', $rem2['count'], 1);

[, $rem3] = $prune->invoke($inst, $mk([$entry('2026-01-01 00:00:00')]), ['2026-01-01 00:00:00' => 2]);
check('botTimes overcount: no underflow', $rem3['count'], 0);

[, $rem4] = $prune->invoke($inst, '', []);
check('empty blob: count 0', $rem4['count'], 0);

[, $rem5] = $prune->invoke($inst, 'not-serialized-garbage', ['x' => 1]);
check('unparseable blob: count null (caller skips)', $rem5['count'], null);

[, $rem6] = $prune->invoke($inst,
    $mk([$entry('2026-01-01 00:00:00'), $entry('2026-01-01 00:00:10')]), []);
check('no bot opens: all kept', $rem6['count'], 2);

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
