<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Checks for Utf8Sanitiser and HitEncodingSubscriber, plus a database round trip
 * against a TEMPORARY table (skipped loudly when config/local.php is absent or the
 * database is unreachable).
 *
 * Run (MAUTIC_ROOT defaults to /var/www/html; adjust the plugin path to where the
 * plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/HitEncodingTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use Mautic\PageBundle\Entity\Hit;
use MauticPlugin\IntellectITBotFilterBundle\EventListener\HitEncodingSubscriber;
use MauticPlugin\IntellectITBotFilterBundle\Helper\Utf8Sanitiser;

$pass = 0; $fail = 0; $skip = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name,
        var_export(is_string($got) ? substr($got, 0, 60) : $got, true),
        var_export(is_string($want) ? substr($want, 0, 60) : $want, true));
}
function skipLoudly(string $name, string $why): void {
    global $skip; $skip++;
    printf("[SKIP] %s -- %s\n", $name, $why);
}

// Two real-world scanner payloads that caused 1366 INSERT failures, verbatim
// from Mautic error logs.
$javaSerialized = "\xAC\xED\x00\x05sr\x00\x11java.util.HashMap";
$otherBinary    = "\x9C\x0B\xF0ff\xE1";

// ---------------------------------------------------------------- isValid
check('isValid: ascii',            Utf8Sanitiser::isValid('harmless'), true);
check('isValid: multibyte',        Utf8Sanitiser::isValid('Renée'), true);
check('isValid: 4-byte emoji',     Utf8Sanitiser::isValid('a🎯b'), true);
// NUL IS valid UTF-8 — which is exactly why an encoding check alone cannot catch it.
check('isValid: NUL is valid utf8',Utf8Sanitiser::isValid("a\x00b"), true);
check('isValid: java payload',     Utf8Sanitiser::isValid($javaSerialized), false);
check('isValid: other binary',     Utf8Sanitiser::isValid($otherBinary), false);

// ------------------------------------------------------- sanitiseString
check('valid utf8 is untouched',        Utf8Sanitiser::sanitiseString('harmless'), 'harmless');
check('multibyte is untouched',         Utf8Sanitiser::sanitiseString('Renée'), 'Renée');
check('emoji is untouched',             Utf8Sanitiser::sanitiseString('a🎯b'), 'a🎯b');
check('java payload becomes valid',     Utf8Sanitiser::isValid(Utf8Sanitiser::sanitiseString($javaSerialized)), true);
check('other binary becomes valid',     Utf8Sanitiser::isValid(Utf8Sanitiser::sanitiseString($otherBinary)), true);
// The ASCII tail of the payload must survive -- that is the whole point of
// substituting rather than discarding the value.
check('ascii tail survives',            str_contains(Utf8Sanitiser::sanitiseString($javaSerialized), 'java.util.HashMap'), true);

// mb_substitute_character() is global state. Assert the finally block restores it,
// or every later mb_* call in the request inherits our setting.
$before = mb_substitute_character();
Utf8Sanitiser::sanitiseString($javaSerialized);
check('mb_substitute_character restored', mb_substitute_character(), $before);

// -------------------------------------------------------------------- NUL
// A second, independent failure mode, found only by running the real Doctrine
// stack: Mautic\CoreBundle\Doctrine\Type\ArrayType::convertToDatabaseValue()
// throws a ConversionException for any serialized array containing chr(0),
// BEFORE the query reaches MySQL. NUL is valid UTF-8, so the encoding pass alone
// left it in place and the row was still lost — just with a different exception.
check('NUL is removed',              str_contains(Utf8Sanitiser::sanitiseString("a\x00b"), "\x00"), false);
check('NUL becomes U+FFFD',          Utf8Sanitiser::sanitiseString("a\x00b"), "a\u{FFFD}b");
check('NUL-only value survives',     Utf8Sanitiser::sanitiseString("\x00"), "\u{FFFD}");
check('every NUL is removed',        substr_count(Utf8Sanitiser::sanitiseString("\x00a\x00b\x00"), "\x00"), 0);
// The real scanner payload carries BOTH classes at once, which is the case that
// matters: sanitising for only one of them still loses the row.
$both = Utf8Sanitiser::sanitiseString($javaSerialized);
check('payload: no NUL left',        str_contains($both, "\x00"), false);
check('payload: valid utf8',         Utf8Sanitiser::isValid($both), true);
check('payload: ascii tail intact',  str_contains($both, 'java.util.HashMap'), true);
// Recursion and the Hit fields must both carry the NUL handling.
$nested = Utf8Sanitiser::sanitise(['a' => ["deep\x00value"], "k\x00ey" => 'v']);
check('nested value NUL removed',    str_contains($nested['a'][0], "\x00"), false);
check('nested KEY NUL removed',      str_contains(implode('', array_map('strval', array_keys($nested))), "\x00"), false);
// A serialized array must now be acceptable to Mautic's ArrayType guard, which is
// a plain str_contains(chr(0)) over the serialize() output.
check('serialized array is NUL-free',
    str_contains(serialize(Utf8Sanitiser::sanitise(['payload' => $javaSerialized])), chr(0)), false);
// Negative control: the UNsanitised array must still trip that guard, or the
// assertion above proves nothing.
check('control: raw array trips guard',
    str_contains(serialize(['payload' => $javaSerialized]), chr(0)), true);

// ------------------------------------------------------------- truncation
check('no cap given: not truncated', mb_strlen(Utf8Sanitiser::sanitiseString(str_repeat('a', 400))), 400);
check('cap truncates to chars',      mb_strlen(Utf8Sanitiser::sanitiseString(str_repeat('a', 400), 191)), 191);
// Truncation must count characters, not bytes: 191 multibyte chars is 382 bytes,
// and a byte-wise cut could also land mid-sequence and produce invalid UTF-8.
$multibyte = str_repeat('é', 400);
$capped    = Utf8Sanitiser::sanitiseString($multibyte, 191);
check('cap counts chars not bytes',  mb_strlen($capped), 191);
check('capped value still valid',    Utf8Sanitiser::isValid($capped), true);
// Substitution can LENGTHEN a value, which is why the cap exists.
$grown = Utf8Sanitiser::sanitiseString(str_repeat("\xAC", 100));
check('substitution grows length',   mb_strlen($grown) === 100 && strlen($grown) > 100, true);
check('cap applied after substitute', mb_strlen(Utf8Sanitiser::sanitiseString(str_repeat("\xAC", 300), 191)), 191);

// ------------------------------------------------------- recursive sanitise
check('scalar int passes through',   Utf8Sanitiser::sanitise(42), 42);
check('null passes through',         Utf8Sanitiser::sanitise(null), null);
check('bool passes through',         Utf8Sanitiser::sanitise(false), false);
check('float passes through',        Utf8Sanitiser::sanitise(1.5), 1.5);

// Mirrors the real shape of Hit::$query: merged GET+POST plus page_url.
$query = [
    'page_url' => 'https://example.test/repro',
    'payload'  => $javaSerialized,
    'nested'   => ['deep' => ['deeper' => $otherBinary], 'clean' => 'ok'],
    'count'    => 7,
];
$clean = Utf8Sanitiser::sanitise($query);
check('nested: clean value kept',    $clean['nested']['clean'], 'ok');
check('nested: int kept',            $clean['count'], 7);
check('nested: url kept',            $clean['page_url'], 'https://example.test/repro');
check('nested: top-level fixed',     Utf8Sanitiser::isValid($clean['payload']), true);
check('nested: depth-3 fixed',       Utf8Sanitiser::isValid($clean['nested']['deep']['deeper']), true);
check('nested: shape preserved',     array_keys($clean), array_keys($query));

// A parameter NAME is as client-controlled as its value.
$badKey = Utf8Sanitiser::sanitise(["bad\xACkey" => 'value']);
check('binary array key fixed',      Utf8Sanitiser::isValid(array_key_first($badKey)), true);
check('binary array key keeps value',array_values($badKey)[0], 'value');
// Documented consequence: two keys differing only in invalid bytes collapse.
$collide = Utf8Sanitiser::sanitise(["k\xACa" => 'first', "k\xEDa" => 'second']);
check('colliding keys collapse',     count($collide), 1);
check('colliding keys: later wins',  array_values($collide)[0], 'second');
// $maxChars must never truncate a key.
$longKey = Utf8Sanitiser::sanitise([str_repeat('k', 400) => 'v'], 10);
check('cap does not truncate keys',  strlen((string) array_key_first($longKey)), 400);
check('cap truncates the value',     array_values($longKey)[0], 'v');

// --------------------------------------------------------- the subscriber
$hit = new Hit();
$hit->setQuery(['payload' => $javaSerialized, 'ok' => 'kept']);
$hit->setBrowserLanguages(['en-AU', "b\xACd"]);
$hit->setUrl("https://example.test/\xAC");
$hit->setReferer("https://ref.test/\xED");
$hit->setUserAgent("Mozilla/5.0 \xAC\xED");
$hit->setUrlTitle(str_repeat("\xAC", 300));
$hit->setRemoteHost("host\xAC");
$hit->setPageLanguage("en\xAC");
$hit->setTrackingId("tid\xAC");
$hit->setPageLanguage("en\xAC");
$hit->setRemoteHost("host\x00nul");

(new HitEncodingSubscriber())->sanitiseHit($hit);

check('hit: query fixed',            Utf8Sanitiser::isValid($hit->getQuery()['payload']), true);
check('hit: query clean key kept',   $hit->getQuery()['ok'], 'kept');
check('hit: languages fixed',        Utf8Sanitiser::isValid($hit->getBrowserLanguages()[1]), true);
check('hit: languages clean kept',   $hit->getBrowserLanguages()[0], 'en-AU');
check('hit: url fixed',              Utf8Sanitiser::isValid($hit->getUrl()), true);
check('hit: referer fixed',          Utf8Sanitiser::isValid($hit->getReferer()), true);
check('hit: user agent fixed',       Utf8Sanitiser::isValid($hit->getUserAgent()), true);
check('hit: url title fixed',        Utf8Sanitiser::isValid($hit->getUrlTitle()), true);
check('hit: url title capped',       mb_strlen($hit->getUrlTitle()) <= 191, true);
check('hit: remote host fixed',      Utf8Sanitiser::isValid($hit->getRemoteHost()), true);
check('hit: page language fixed',    Utf8Sanitiser::isValid($hit->getPageLanguage()), true);
check('hit: tracking id fixed',      Utf8Sanitiser::isValid($hit->getTrackingId()), true);
check('hit: NUL removed from host',  str_contains($hit->getRemoteHost(), "\x00"), false);
check('hit: query serializes clean', str_contains(serialize($hit->getQuery()), chr(0)), false);
check('hit: languages serialize clean', str_contains(serialize($hit->getBrowserLanguages()), chr(0)), false);

// A hit that was already clean must come out byte-identical.
$cleanHit = new Hit();
$cleanHit->setQuery(['a' => 'b']);
$cleanHit->setUrl('https://example.test/page');
$cleanHit->setUserAgent('Mozilla/5.0');
(new HitEncodingSubscriber())->sanitiseHit($cleanHit);
check('hit: clean query untouched',  $cleanHit->getQuery(), ['a' => 'b']);
check('hit: clean url untouched',    $cleanHit->getUrl(), 'https://example.test/page');
check('hit: clean ua untouched',     $cleanHit->getUserAgent(), 'Mozilla/5.0');
// Nulls must stay null rather than becoming ''.
$nullHit = new Hit();
(new HitEncodingSubscriber())->sanitiseHit($nullHit);
check('hit: null referer stays null', $nullHit->getReferer(), null);
check('hit: null url stays null',     $nullHit->getUrl(), null);

// ------------------------------------------------- DB round trip (the point)
// Everything above proves the helper returns valid UTF-8. Only this proves the
// INSERT that failed with 1366 under STRICT_TRANS_TABLES now succeeds -- and the negative
// control proves this test could fail, by showing the unsanitised value is
// still rejected.
$localConfig = $mauticRoot.'/config/local.php';
if (!file_exists($localConfig)) {
    skipLoudly('db round trip', 'no '.$localConfig.' -- cannot reach the database');
} else {
    $parameters = [];
    require $localConfig;

    try {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $parameters['db_host'], $parameters['db_port'] ?? 3306, $parameters['db_name']),
            $parameters['db_user'],
            $parameters['db_password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (PDOException $e) {
        $pdo = null;
        skipLoudly('db round trip', 'connection failed: '.substr($e->getMessage(), 0, 80));
    }

    if (null !== $pdo) {
        // STRICT_TRANS_TABLES is what makes 1366 an error instead of a warning.
        // If it is ever absent, the negative control below cannot fail, so assert it.
        $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();
        check('db: STRICT_TRANS_TABLES on', str_contains($mode, 'STRICT_TRANS_TABLES'), true);

        // TEMPORARY table, same type and collation as page_hits.query. No real table touched.
        $pdo->exec('CREATE TEMPORARY TABLE bf_hit_encoding_probe (q LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci)');
        $insert = $pdo->prepare('INSERT INTO bf_hit_encoding_probe (q) VALUES (?)');

        $rawQuery = ['payload' => $javaSerialized, 'page_url' => 'https://example.test/repro'];

        // Negative control: the scanner payload, unsanitised, must still be rejected.
        $rejected = false; $sqlState = '';
        try {
            $insert->execute([serialize($rawQuery)]);
        } catch (PDOException $e) {
            $rejected = true;
            $sqlState = (string) ($e->errorInfo[1] ?? '');
        }
        check('db: unsanitised is rejected', $rejected, true);
        check('db: rejected with 1366',      $sqlState, '1366');

        // The fix: the same array, sanitised, inserts.
        $accepted = true; $why = '';
        try {
            $insert->execute([serialize(Utf8Sanitiser::sanitise($rawQuery))]);
        } catch (PDOException $e) {
            $accepted = false;
            $why = substr($e->getMessage(), 0, 90);
        }
        check('db: sanitised is accepted', $accepted, true);
        if (!$accepted) { printf("       reason: %s\n", $why); }

        check('db: exactly one row stored',
            (int) $pdo->query('SELECT COUNT(*) FROM bf_hit_encoding_probe')->fetchColumn(), 1);

        // And it must come back as a usable array, not mangled.
        $stored = unserialize((string) $pdo->query('SELECT q FROM bf_hit_encoding_probe')->fetchColumn());
        check('db: round trips to an array',   is_array($stored), true);
        check('db: clean key round trips',     $stored['page_url'] ?? null, 'https://example.test/repro');
        check('db: payload key round trips',   isset($stored['payload']), true);
        check('db: stored payload is valid',   Utf8Sanitiser::isValid($stored['payload'] ?? ''), true);
        check('db: ascii tail round trips',    str_contains($stored['payload'] ?? '', 'java.util.HashMap'), true);
    }
}

printf("\n%d passed, %d failed, %d skipped\n", $pass, $fail, $skip);
exit($fail > 0 ? 1 : 0);
