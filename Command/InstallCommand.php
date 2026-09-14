<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mautic:botfilter:install',
    description: 'Create the botfilter_blocked_hits and botfilter_ip_enrichment tables and their indexes (idempotent).'
)]
class InstallCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io     = new SymfonyStyle($input, $output);
        $prefix = (string) MAUTIC_TABLE_PREFIX;
        $table  = $prefix.'botfilter_blocked_hits';

        $this->connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `reason` VARCHAR(20) NOT NULL,
                `channel` VARCHAR(16) NULL,
                `email_id` INT UNSIGNED NULL,
                `lead_id` BIGINT UNSIGNED NULL,
                `stat_id` BIGINT UNSIGNED NULL,
                `tracking_hash` VARCHAR(191) NULL,
                `email_domain` VARCHAR(255) NULL,
                `ip` VARCHAR(45) NULL,
                `user_agent` VARCHAR(255) NULL,
                `url` VARCHAR(2048) NULL,
                `distinct_ip_count` INT NULL,
                `trigger_ips` TEXT NULL,
                `window_seconds` INT NULL,
                `date_hit` DATETIME NULL,
                `date_captured` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `bf_email_lead_date` (`email_id`, `lead_id`, `date_hit`),
                KEY `bf_date` (`date_hit`),
                KEY `bf_reason` (`reason`),
                KEY `bf_domain` (`email_domain`),
                KEY `bf_stat` (`stat_id`)
            ) DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
        );

        // --- IP enrichment cache table (idempotent) ---
        $enrich = $prefix.'botfilter_ip_enrichment';
        $this->connection->executeStatement(
            "CREATE TABLE IF NOT EXISTS `{$enrich}` (
                `ip` VARCHAR(45) NOT NULL,
                `country` VARCHAR(128) NULL,
                `city` VARCHAR(128) NULL,
                `org` VARCHAR(255) NULL,
                `asn` INT UNSIGNED NULL,
                `is_datacenter` TINYINT(1) NOT NULL DEFAULT 0,
                `provider` VARCHAR(64) NULL,
                `source` VARCHAR(16) NOT NULL,
                `enriched_at` DATETIME NOT NULL,
                PRIMARY KEY (`ip`),
                KEY `bf_ipenr_dc` (`is_datacenter`)
            ) DEFAULT CHARSET=utf8mb4 ROW_FORMAT=DYNAMIC"
        );

        // --- guarded index adds on botfilter_blocked_hits (CREATE IF NOT EXISTS
        //     won't alter an existing table, so check information_schema first) ---
        foreach (['bf_lead' => 'lead_id', 'bf_captured' => 'date_captured'] as $idx => $col) {
            $exists = (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i',
                ['t' => $prefix.'botfilter_blocked_hits', 'i' => $idx]
            );
            if (0 === $exists) {
                try {
                    $this->connection->executeStatement(
                        sprintf('ALTER TABLE `%sbotfilter_blocked_hits` ADD KEY `%s` (`%s`)', $prefix, $idx, $col)
                    );
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Could not add index %s: %s', $idx, $e->getMessage()));
                }
            }
        }

        $io->success(sprintf('Tables %s and %s are present.', $table, $enrich));

        return Command::SUCCESS;
    }
}
