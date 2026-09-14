<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

use MauticPlugin\IntellectITBotFilterBundle\Controller\HoneypotController;
use MauticPlugin\IntellectITBotFilterBundle\Helper\ConfigProvider;
use MauticPlugin\IntellectITBotFilterBundle\Helper\IpEnricher;
use MauticPlugin\IntellectITBotFilterBundle\Helper\VelocityBotRatioHelper;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()
        ->defaults()
        ->autowire()
        ->autoconfigure();

    // Autoregister bundle services (commands, subscribers, helpers). Only
    // Helper/VelocityBotRatioHelper.php is excluded from Helper/, because the
    // decorator below is wired explicitly; Tests/ is excluded so the standalone
    // test scripts are never executed during container compilation.
    $services->load('MauticPlugin\\IntellectITBotFilterBundle\\', '../')
        ->exclude('../{Config,DependencyInjection,Helper/VelocityBotRatioHelper.php,Tests,IntellectITBotFilterBundle.php}');

    // Decorate the core BotRatioHelper. Consumers (EmailModel::hitEmail for
    // opens, PageModel::hitPage for clicks) typehint the concrete class, so
    // the decorator replaces it in both tracking paths automatically.
    // Only $inner is set explicitly; the other constructor deps autowire. The
    // burst thresholds are not constructor args: they are read at runtime from
    // ConfigProvider (integration feature settings, defaults 3 IPs / 30 seconds).
    $services->set(VelocityBotRatioHelper::class)
        ->decorate(\Mautic\EmailBundle\Helper\BotRatioHelper::class)
        ->arg('$inner', service('.inner'));

    // The honeypot controller is a plain class (does not extend AbstractController),
    // so autoconfigure does NOT tag it controller.service_arguments. Without the tag
    // the route resolver cannot fetch it from the container (private-service error →
    // 500). Register it explicitly with the tag so /bf/honeypot resolves.
    $services->set(HoneypotController::class)
        ->autowire()
        ->public()
        ->tag('controller.service_arguments');

    // ConfigProvider reads the integration's feature settings. Bind IntegrationHelper
    // by service id (mautic.helper.integration) rather than relying on an autowire alias.
    $services->set(ConfigProvider::class)
        ->arg('$integrationHelper', service('mautic.helper.integration'));

    $services->set(IpEnricher::class)
        ->arg('$dataDir', '%kernel.cache_dir%/../ip_data');

    // The dashboard subscriber preformats UTC datetimes into the user/default
    // timezone for display (table widgets render raw strings). Bind the core
    // DateHelper by service id rather than relying on an autowire alias.
    $services->set(\MauticPlugin\IntellectITBotFilterBundle\EventListener\DashboardSubscriber::class)
        ->arg('$dateHelper', service('mautic.helper.twig.date'));

    $services->set(\MauticPlugin\IntellectITBotFilterBundle\Command\UpdateAsnDbCommand::class)
        ->arg('$dataDir', '%kernel.cache_dir%/../ip_data')
        ->tag('console.command');
};
