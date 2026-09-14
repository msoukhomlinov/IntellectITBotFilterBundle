<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Integration;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use Mautic\PluginBundle\Integration\AbstractIntegration;
use MauticPlugin\IntellectITBotFilterBundle\Helper\IpEnricher;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;

/**
 * Settings-only integration: renders the BotFilter card on Settings -> Plugins
 * with a config form. No authentication. Feature settings are read at runtime by
 * ConfigProvider. Publishing the integration activates these settings.
 */
class IntellectITBotFilterIntegration extends AbstractIntegration
{
    public function getName(): string
    {
        return 'IntellectITBotFilter';
    }

    public function getDisplayName(): string
    {
        return 'IIT Bot Filter';
    }

    public function getDescription(): string
    {
        return 'Published = adds the list-free burst/velocity signal to bot detection on email '
            .'opens AND clicks (catching cloud link scanners core misses) plus the optional honeypot. '
            .'Unpublished = standard Mautic only (its built-in signals still run on both opens and '
            .'clicks, but they miss modern scanners). Publish to activate; configure the burst '
            .'thresholds and honeypot below.';
    }

    public function getAuthenticationType(): string
    {
        return 'none';
    }

    /**
     * Custom template gives us named tabs (Detection/Honeypot/Diagnostics) — core's default
     * @MauticPlugin/Integration/form.html.twig only supports its own fixed tab set
     * (Details/Features/Field Mapping/Company Field Mapping), with no mechanism for
     * arbitrary tab names. docs/ui-spec.md calls for named tabs, so a template replacement is
     * the only lever that achieves it (getFormNotes()/getFormTheme() alone cannot rename or add
     * tabs to the core template).
     */
    public function getFormTemplate(): string
    {
        return '@IntellectITBotFilter/Integration/form.html.twig';
    }

    /**
     * Core's PluginController::editAction() calls this for
     * 'authorization'/'features'/'feature_settings'/'custom' regardless of which template is
     * in use, so this lever works unchanged even with a custom getFormTemplate(). Used for
     * two things here:
     * - 'features': the Detection-tab alert explaining the published/unpublished master
     *   switch (see docs/ui-spec.md).
     * - 'custom': the Diagnostics tab content — core decomposes 'features' into
     *   {note,type} but passes 'custom' through as-is, so a plain HTML string here is
     *   rendered directly (this plugin's own form.html.twig checks `formNotes.custom is
     *   string`, matching the convention core's own default template uses).
     *
     * @return array{0: string, 1: string}|string
     */
    public function getFormNotes($section)
    {
        if ('custom' === $section) {
            return $this->renderDiagnosticsHtml();
        }

        if ('features' !== $section) {
            return parent::getFormNotes($section);
        }

        // isset(), not null !==: $settings is a non-nullable typed property, so reading it
        // before setIntegrationSettings() has run would throw rather than return null.
        $published = isset($this->settings) && $this->settings->isPublished();

        $note = $this->translator->trans($published
            ? 'mautic.botfilter.config.published.note.on'
            : 'mautic.botfilter.config.published.note.off');

        // 'default' is not a real alert modifier in core's built CSS — the Twig template
        // maps it to 'secondary' at render time (see form.html.twig).
        return [$note, $published ? 'success' : 'default'];
    }

    /**
     * Diagnostics tab data (see docs/ui-spec.md): capture tables installed, GeoLite2-ASN database
     * age, IP enrichment cache size. Computed here (not passed a fresh service via
     * config.php) by reusing what AbstractIntegration's constructor already injects —
     * $this->em (Doctrine) and $this->pathsHelper — so no new wiring is needed.
     *
     * @return array{tablesInstalled: bool, asnExists: bool, asnAgeDays: ?int,
     *               asnAgeSeconds: ?int, asnStale: bool, enrichmentCacheCount: ?int,
     *               enrichmentAvailable: bool}
     */
    public function getDiagnostics(): array
    {
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        $conn   = $this->em->getConnection();

        $tablesInstalled      = false;
        $enrichmentCacheCount = null;
        // Distinct from "count is 0": tracks whether the count could be READ at all. A missing
        // table or a failed query leaves the count null, and the row must then show a warning
        // rather than a green dot beside "Unknown" — that combination reported success for
        // precisely the broken state this tab exists to reveal.
        $enrichmentAvailable = false;
        try {
            // (int), not (bool): `(bool) $n >= 2` casts FIRST, so `true >= 2` compares
            // true >= true and reports "installed" when only ONE of the two tables exists —
            // exactly the partially-installed state this row is meant to surface.
            $tablesInstalled = (int) $conn->fetchOne(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE table_schema = DATABASE() AND table_name IN (:t1, :t2)',
                ['t1' => $prefix.'botfilter_blocked_hits', 't2' => $prefix.'botfilter_ip_enrichment'],
            ) >= 2;
            if ($tablesInstalled) {
                $enrichmentCacheCount = (int) $conn->fetchOne(
                    "SELECT COUNT(*) FROM {$prefix}botfilter_ip_enrichment"
                );
                $enrichmentAvailable = true;
            }
        } catch (\Throwable $e) {
            // Defensive: diagnostics must never break the settings form — but the failure
            // is still logged, not silently swallowed (see "Error handling" in
            // docs/architecture.md).
            $this->logger->error('BotFilter: diagnostics query failed', ['exception' => $e]);
        }

        // Matches IpEnricher's own $dataDir binding (%kernel.cache_dir%/../ip_data) without
        // needing that exact DI parameter string here too.
        $dataDir  = rtrim(dirname($this->pathsHelper->getCachePath()), '/').'/ip_data';
        $asnPath  = $dataDir.'/GeoLite2-ASN.mmdb';
        $asnExists     = is_file($asnPath);
        $asnAgeDays    = null;
        $asnAgeSeconds = null;
        if ($asnExists) {
            $mtime = @filemtime($asnPath);
            if (false !== $mtime) {
                $asnAgeSeconds = time() - $mtime;
                // Days are for DISPLAY only. The stale verdict below uses the unrounded
                // seconds against the shared threshold, because flooring to days and testing
                // `> 7` left anything between exactly 7 and 8 days old green here while the
                // list page's health strip already showed it stale.
                $asnAgeDays = (int) floor($asnAgeSeconds / 86400);
            }
        }

        return [
            'tablesInstalled'       => $tablesInstalled,
            'asnExists'             => $asnExists,
            'asnAgeDays'            => $asnAgeDays,
            'asnAgeSeconds'         => $asnAgeSeconds,
            // Same rule as BotFilterController::buildHealthStrip(): an unreadable mtime counts
            // as stale, not as fresh.
            'asnStale'              => null === $asnAgeSeconds || $asnAgeSeconds > IpEnricher::ASN_DB_STALE_SECONDS,
            'enrichmentCacheCount'  => $enrichmentCacheCount,
            'enrichmentAvailable'   => $enrichmentAvailable,
        ];
    }

    /**
     * Builds the Diagnostics tab's HTML (see docs/ui-spec.md: status dot + label + the console
     * command in mono as its sub-line + a value on the right). All interpolated values are
     * server-computed booleans/ints, never user input, but still escaped defensively.
     */
    private function renderDiagnosticsHtml(): string
    {
        $d = $this->getDiagnostics();
        $t = fn (string $key) => htmlspecialchars($this->translator->trans($key), ENT_QUOTES);

        $rows = [];

        $rows[] = $this->diagnosticRow(
            $d['tablesInstalled'] ? 'ok' : 'warn',
            $t('mautic.botfilter.config.diagnostics.tables'),
            'mautic:botfilter:install',
            $d['tablesInstalled'] ? $t('mautic.botfilter.health.value.active') : $t('mautic.botfilter.health.value.missing')
        );

        if (!$d['asnExists']) {
            $asnValue = $t('mautic.botfilter.health.value.missing');
            $asnDot   = 'off';
        } elseif (null === $d['asnAgeDays']) {
            $asnValue = $t('mautic.botfilter.health.value.unknown');
            $asnDot   = 'off';
        } else {
            // asnStale is computed from unrounded seconds against the shared threshold — NOT
            // from the floored day count, which would disagree with the list page's health
            // strip for the whole day between 7 and 8 days old.
            $asnValue = htmlspecialchars($this->translator->trans('mautic.botfilter.health.asndb.age', ['%count%' => $d['asnAgeDays']]), ENT_QUOTES);
            $asnDot   = $d['asnStale'] ? 'warn' : 'ok';
        }
        $rows[] = $this->diagnosticRow($asnDot, $t('mautic.botfilter.config.diagnostics.asndb'), 'mautic:botfilter:update-asn-db', $asnValue);

        $rows[] = $this->diagnosticRow(
            // Not hardcoded 'ok': a missing table or a failed query yields a null count, and a
            // green dot beside "Unknown" reported success for exactly the partially-installed
            // or erroring state this tab is meant to diagnose.
            $d['enrichmentAvailable'] ? 'ok' : 'warn',
            $t('mautic.botfilter.config.diagnostics.enrichment'),
            'mautic:botfilter:enrich-ips',
            null === $d['enrichmentCacheCount'] ? $t('mautic.botfilter.health.value.unknown') : (string) $d['enrichmentCacheCount']
        );

        return '<div class="botfilter-diagnostics">'.implode('', $rows).'</div>';
    }

    private function diagnosticRow(string $dot, string $label, string $command, string $value): string
    {
        return '<div class="botfilter-diag-row">'
            .'<span class="botfilter-health__dot botfilter-health__dot--'.htmlspecialchars($dot, ENT_QUOTES).'"></span>'
            .'<div class="botfilter-diag-row__text">'
            .'<div class="type-body-compact-01">'.$label.'</div>'
            .'<div class="botfilter-mono type-label-01">'.htmlspecialchars($command, ENT_QUOTES).'</div>'
            .'</div>'
            .'<div class="botfilter-diag-row__value type-body-compact-01">'.$value.'</div>'
            .'</div>';
    }

    /**
     * @param \Mautic\PluginBundle\Form\Type\FeatureSettingsType|\Symfony\Component\Form\FormBuilderInterface $builder
     * @param array<string,mixed>                                                                            $data
     * @param string                                                                                         $formArea
     */
    public function appendToForm(&$builder, $data, $formArea): void
    {
        if ('features' !== $formArea) {
            return;
        }

        $builder->add('honeypot_enabled', YesNoButtonGroupType::class, [
            'label' => 'mautic.botfilter.config.honeypot.label',
            'data'  => (bool) ($data['honeypot_enabled'] ?? false),
            'attr'  => [
                'tooltip' => $this->translator->trans('mautic.botfilter.config.honeypot.help'),
            ],
        ]);

        $builder->add('honeypot_email_ids', TextType::class, [
            'label'      => 'mautic.botfilter.config.allowlist.label',
            'required'   => false,
            'data'       => (string) ($data['honeypot_email_ids'] ?? ''),
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'e.g. 12,34 — blank = all emails',
                'tooltip'     => $this->translator->trans('mautic.botfilter.config.allowlist.help'),
            ],
        ]);

        $builder->add('max_distinct_ips', NumberType::class, [
            'label'      => 'mautic.botfilter.config.burst.ips.label',
            'required'   => false,
            'data'       => (int) ($data['max_distinct_ips'] ?? 3),
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'   => 'form-control',
                'min'     => 2,
                'tooltip' => $this->translator->trans('mautic.botfilter.config.burst.ips.help'),
            ],
        ]);

        $builder->add('window_seconds', NumberType::class, [
            'label'      => 'mautic.botfilter.config.burst.window.label',
            'required'   => false,
            'data'       => (int) ($data['window_seconds'] ?? 30),
            'label_attr' => ['class' => 'control-label'],
            'attr'       => [
                'class'   => 'form-control',
                'min'     => 1,
                'tooltip' => $this->translator->trans('mautic.botfilter.config.burst.window.help'),
            ],
        ]);
    }
}
