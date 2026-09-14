<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Command;

use Doctrine\DBAL\Connection;
use MauticPlugin\IntellectITBotFilterBundle\Helper\IpEnricher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mautic:botfilter:enrich-ips',
    description: 'Pre-warm IP enrichment for all distinct IPs seen in botfilter_blocked_hits.'
)]
class EnrichIpsCommand extends Command
{
    public function __construct(private Connection $connection, private IpEnricher $enricher)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        $ips    = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT ip FROM {$prefix}botfilter_blocked_hits WHERE ip IS NOT NULL AND ip <> ''"
        );
        $n = 0;
        foreach ($ips as $ip) {
            $this->enricher->enrich((string) $ip);
            ++$n;
        }
        $io->success(sprintf('Enriched %d distinct IP(s).', $n));

        return Command::SUCCESS;
    }
}
