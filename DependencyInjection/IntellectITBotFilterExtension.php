<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

class IntellectITBotFilterExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $loader = new PhpFileLoader($container, new FileLocator(__DIR__ . '/../Config'));
        $loader->load('services.php');
    }
}
