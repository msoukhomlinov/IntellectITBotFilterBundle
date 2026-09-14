<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

/**
 * Pure-unit checks of ConfigProvider (no database, no kernel).
 *
 * Run (MAUTIC_ROOT defaults to /var/www/html; adjust the plugin path to where the
 * plugin is installed):
 *   MAUTIC_ROOT=/path/to/mautic php plugins/IntellectITBotFilterBundle/Tests/ConfigProviderTest.php
 */

$mauticRoot = rtrim(getenv('MAUTIC_ROOT') ?: '/var/www/html', '/');
require $mauticRoot.'/vendor/autoload.php';

use Mautic\PluginBundle\Entity\Integration;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use MauticPlugin\IntellectITBotFilterBundle\Helper\ConfigProvider;

$pass = 0; $fail = 0;
function check(string $name, $got, $want): void {
    global $pass, $fail;
    $ok = $got === $want; $ok ? $pass++ : $fail++;
    printf("[%s] %s (got=%s want=%s)\n", $ok ? 'PASS' : 'FAIL', $name, var_export($got, true), var_export($want, true));
}

// Build a ConfigProvider whose IntegrationHelper returns a controllable integration object.
// IntegrationHelper has a heavy constructor, so subclass it with an empty constructor and
// override getIntegrationObject().
function providerWith($integrationObject): ConfigProvider {
    $helper = new class($integrationObject) extends IntegrationHelper {
        private $obj;
        public function __construct($obj) { $this->obj = $obj; }
        public function getIntegrationObject($name = false, $alreadySorted = false, $returnError = false) { return $this->obj; }
    };
    return new ConfigProvider($helper);
}

// Minimal stand-in for an integration object exposing getIntegrationSettings().
function integrationObjectWith(?Integration $entity) {
    return new class($entity) {
        private $entity;
        public function __construct($entity) { $this->entity = $entity; }
        public function getIntegrationSettings() { return $this->entity; }
    };
}

// 1) No integration -> defaults, honeypot off.
$p = providerWith(false);
check('no integration -> honeypot off', $p->isHoneypotEnabled(), false);
check('no integration -> default max ips', $p->getMaxDistinctIps(), 3);
check('no integration -> default window', $p->getWindowSeconds(), 30);
check('no integration -> not enabled', $p->isEnabled(), false);

// 2) Integration present but UNPUBLISHED -> defaults (no regression).
$unpub = new Integration();
$unpub->setIsPublished(false);
$unpub->setFeatureSettings(['honeypot_enabled' => true, 'max_distinct_ips' => 9, 'window_seconds' => 99]);
$p = providerWith(integrationObjectWith($unpub));
check('unpublished -> not enabled', $p->isEnabled(), false);
check('unpublished -> honeypot off', $p->isHoneypotEnabled(), false);
check('unpublished -> default max ips', $p->getMaxDistinctIps(), 3);

// 3) Published with settings -> values applied.
$pub = new Integration();
$pub->setIsPublished(true);
$pub->setFeatureSettings(['honeypot_enabled' => true, 'honeypot_email_ids' => '12,34', 'max_distinct_ips' => 5, 'window_seconds' => 20]);
$p = providerWith(integrationObjectWith($pub));
check('published -> enabled', $p->isEnabled(), true);
check('published -> honeypot on', $p->isHoneypotEnabled(), true);
check('published -> allowlist', $p->getHoneypotEmailIds(), '12,34');
check('published -> custom max ips', $p->getMaxDistinctIps(), 5);
check('published -> custom window', $p->getWindowSeconds(), 20);

// 4) Published but absurd thresholds -> clamped to defaults.
$bad = new Integration();
$bad->setIsPublished(true);
$bad->setFeatureSettings(['max_distinct_ips' => 1, 'window_seconds' => 0]);
$p = providerWith(integrationObjectWith($bad));
check('clamp tiny max ips -> default', $p->getMaxDistinctIps(), 3);
check('clamp zero window -> default', $p->getWindowSeconds(), 30);

echo "\n==== $pass passed, $fail failed ====\n";
exit($fail > 0 ? 1 : 0);
