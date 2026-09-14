<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Helper;

use Mautic\PluginBundle\Helper\IntegrationHelper;

/**
 * Single source of truth for BotFilter runtime settings, read from the
 * IntellectITBotFilter integration's feature settings (Settings -> Plugins).
 *
 * Rules:
 *  - isEnabled() is the master switch: true only when the integration exists AND is
 *    published. While it is false, VelocityBotRatioHelper delegates every call to core
 *    untouched (no burst signal, no capture rows), honeypot injection is off, and the
 *    getters below return the code defaults (honeypot OFF, thresholds 3 / 30).
 *  - Exception-safe and memoised: it runs on the live open/click path, so any
 *    failure degrades to defaults and never throws.
 */
class ConfigProvider
{
    private const INTEGRATION     = 'IntellectITBotFilter';
    private const DEFAULT_MAX_IPS = 3;
    private const DEFAULT_WINDOW  = 30;

    private bool $loaded = false;
    /** @var array<string,mixed>|null feature settings when published+active, else null */
    private ?array $settings = null;

    public function __construct(private IntegrationHelper $integrationHelper)
    {
    }

    /**
     * Master switch: true only when the integration exists AND is published. When false,
     * the decorator delegates to core untouched (standard Mautic — no burst signal, no
     * honeypot). This is the user-facing on/off of the whole plugin.
     */
    public function isEnabled(): bool
    {
        return null !== $this->settings();
    }

    public function isHoneypotEnabled(): bool
    {
        $s = $this->settings();

        return null !== $s && (bool) ($s['honeypot_enabled'] ?? false);
    }

    public function getHoneypotEmailIds(): string
    {
        $s = $this->settings();

        return null === $s ? '' : (string) ($s['honeypot_email_ids'] ?? '');
    }

    public function getMaxDistinctIps(): int
    {
        $s = $this->settings();
        if (null === $s) {
            return self::DEFAULT_MAX_IPS;
        }
        $v = (int) ($s['max_distinct_ips'] ?? self::DEFAULT_MAX_IPS);

        return $v >= 2 ? $v : self::DEFAULT_MAX_IPS;
    }

    public function getWindowSeconds(): int
    {
        $s = $this->settings();
        if (null === $s) {
            return self::DEFAULT_WINDOW;
        }
        $v = (int) ($s['window_seconds'] ?? self::DEFAULT_WINDOW);

        return $v >= 1 ? $v : self::DEFAULT_WINDOW;
    }

    /**
     * @return array<string,mixed>|null feature settings if the integration exists and is
     *                                   published, else null (→ callers use defaults). Memoised.
     */
    private function settings(): ?array
    {
        if ($this->loaded) {
            return $this->settings;
        }
        $this->loaded = true;

        try {
            $obj = $this->integrationHelper->getIntegrationObject(self::INTEGRATION);
            if (false === $obj || null === $obj) {
                return $this->settings = null;
            }
            $entity = $obj->getIntegrationSettings();
            if (null === $entity || !$entity->isPublished()) {
                return $this->settings = null;
            }
            $fs = $entity->getFeatureSettings();

            return $this->settings = is_array($fs) ? $fs : [];
        } catch (\Throwable $e) {
            return $this->settings = null;
        }
    }
}
