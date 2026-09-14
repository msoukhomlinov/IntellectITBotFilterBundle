<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mautic:botfilter:prune',
    description: 'Delete bot-hit records older than N days (and orphaned IP enrichment rows).'
)]
class PruneCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'Age in days', '180')
             ->addOption('apply', null, InputOption::VALUE_NONE, 'Actually delete (default: dry run)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $days   = max(1, (int) $input->getOption('older-than'));
        $apply  = (bool) $input->getOption('apply');
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $hits = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM {$prefix}botfilter_blocked_hits WHERE date_captured < :c",
            ['c' => $cutoff]
        );
        $io->writeln(sprintf('%s %d hit(s) older than %d days (cutoff %s UTC).',
            $apply ? 'Deleting' : '[dry run] Would delete', $hits, $days, $cutoff));

        if ($apply) {
            $this->connection->executeStatement(
                "DELETE FROM {$prefix}botfilter_blocked_hits WHERE date_captured < :c", ['c' => $cutoff]
            );
            $orphans = $this->connection->executeStatement(
                "DELETE e FROM {$prefix}botfilter_ip_enrichment e
                 LEFT JOIN {$prefix}botfilter_blocked_hits h ON h.ip = e.ip
                 WHERE h.ip IS NULL"
            );
            $io->success(sprintf('Deleted %d hit(s) and %d orphaned enrichment row(s).', $hits, (int) $orphans));
        } else {
            $io->note('Re-run with --apply to delete.');
        }

        return Command::SUCCESS;
    }
}
