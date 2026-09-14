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

/**
 * Retroactively strips scanner-inflated stats that pre-date this plugin (or that
 * slipped through before the IP burst crossed the live threshold).
 *
 * Applies the SAME burst signature the live decorator uses — same email + contact
 * hit from >= --min-ips distinct IPs within --window seconds — to historical data:
 *
 *   1. OPENS      email_stats: removes bot opens (burst rows in email_stats_devices),
 *                 recomputes open_count / is_read / date_read / last_opened, rewrites
 *                 the serialized open_details blob, deletes the bot device rows.
 *   2. READ COUNT emails.read_count: re-derived as COUNT(is_read=1) for touched emails.
 *   3. TRACKABLES channel_url_trackables: hits / unique_hits re-derived from the
 *                 surviving page_hits using Mautic's own semantics
 *                 (hits = COUNT(*), unique_hits = COUNT(DISTINCT tracking_id)).
 *
 * This command does not delete bot click rows from page_hits. If you remove them, run
 * that DELETE first, then this command, so step 3 re-derives from the cleaned page_hits.
 *
 * Defaults to a DRY RUN. Pass --apply to write. Always back up email_stats,
 * email_stats_devices, emails and channel_url_trackables before --apply.
 */
#[AsCommand(
    name: 'mautic:botfilter:recount',
    description: 'Recompute email open/read/click aggregates after removing cloud-scanner burst hits.'
)]
class RecountBotStatsCommand extends Command
{
    public function __construct(private Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('window', null, InputOption::VALUE_REQUIRED, 'Burst window in seconds.', '30')
            ->addOption('min-ips', null, InputOption::VALUE_REQUIRED, 'Distinct IPs within the window to count as a burst.', '3')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Write changes. Without this flag the command is a dry run.')
            ->addOption('skip-trackables', null, InputOption::VALUE_NONE, 'Skip the channel_url_trackables re-derivation (step 3).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $window   = max(1, (int) $input->getOption('window'));
        $minIps   = max(2, (int) $input->getOption('min-ips'));
        $apply    = (bool) $input->getOption('apply');
        $doTrack  = !$input->getOption('skip-trackables');
        $prefix   = (string) MAUTIC_TABLE_PREFIX;

        $io->title('Bot Filter — historical stat recount');
        $io->writeln(sprintf('Signature: >= %d distinct IPs within %d s · mode: <comment>%s</comment>', $minIps, $window, $apply ? 'APPLY' : 'DRY RUN'));

        if ($apply) {
            $this->connection->beginTransaction();
        }

        try {
            $openSummary  = $this->recountOpens($io, $prefix, $window, $minIps, $apply);
            $readSummary  = $this->recountReadCounts($io, $prefix, $openSummary['emailIds'], $apply);
            $trackSummary = $doTrack
                ? $this->recountTrackables($io, $prefix, $apply)
                : ['rows' => 0, 'changed' => 0];

            if ($apply) {
                $this->connection->commit();
                $io->success('Changes committed.');
            } else {
                $io->note('Dry run — no changes written. Re-run with --apply (after backups) to commit.');
            }
        } catch (\Throwable $e) {
            if ($apply && $this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
            $io->error('Aborted, rolled back: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->section('Summary');
        $io->table(
            ['Phase', 'Affected', 'Detail'],
            [
                ['Opens', (string) $openSummary['statsChanged'], $openSummary['botOpens'].' bot opens removed across '.$openSummary['statsChanged'].' stats'],
                ['Read counts', (string) count($readSummary['emails']), 'emails.read_count re-derived'],
                ['Trackables', (string) $trackSummary['changed'], $trackSummary['changed'].' of '.$trackSummary['rows'].' rows adjusted'],
            ]
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{statsChanged:int, botOpens:int, emailIds:int[]}
     */
    private function recountOpens(SymfonyStyle $io, string $prefix, int $window, int $minIps, bool $apply): array
    {
        // Candidate stats: more than the threshold of distinct device IPs in total.
        // The sliding-window check below confirms the burst before touching anything.
        $candidateIds = $this->connection->fetchFirstColumn(
            "SELECT stat_id
             FROM {$prefix}email_stats_devices
             WHERE stat_id IS NOT NULL AND ip_id IS NOT NULL
             GROUP BY stat_id
             HAVING COUNT(DISTINCT ip_id) >= :minIps",
            ['minIps' => $minIps],
            ['minIps' => \PDO::PARAM_INT]
        );

        $statsChanged = 0;
        $botOpens     = 0;
        $emailIds     = [];

        foreach ($candidateIds as $statId) {
            $devices = $this->connection->fetchAllAssociative(
                "SELECT id, ip_id, date_opened
                 FROM {$prefix}email_stats_devices
                 WHERE stat_id = :statId
                 ORDER BY date_opened ASC",
                ['statId' => $statId]
            );

            $botDeviceIds   = $this->burstRowIds($devices, $window, $minIps);
            if (!$botDeviceIds) {
                continue;
            }

            $stat = $this->connection->fetchAssociative(
                "SELECT id, email_id, open_count, open_details
                 FROM {$prefix}email_stats WHERE id = :statId",
                ['statId' => $statId]
            );
            if (!$stat) {
                continue;
            }

            // Datetimes of the bot opens, used to prune the open_details blob.
            $botTimes = [];
            foreach ($devices as $d) {
                if (in_array((int) $d['id'], $botDeviceIds, true)) {
                    $botTimes[$d['date_opened']] = ($botTimes[$d['date_opened']] ?? 0) + 1;
                }
            }

            [$newDetails, $remaining] = $this->pruneOpenDetails((string) $stat['open_details'], $botTimes);

            // Unparseable blob: skip the stat entirely rather than risk zeroing a
            // populated open_details / corrupting counts. (Not seen in practice.)
            if (null === $remaining['count']) {
                $io->warning(sprintf('stat %d: open_details could not be parsed — skipped.', (int) $statId));
                continue;
            }

            // Surviving blob entries are the source of truth for the new count.
            $newOpenCount = $remaining['count'];

            $isRead     = $newOpenCount > 0 ? 1 : 0;
            $dateRead   = $remaining['first'];
            $lastOpened = $remaining['last'];

            $botOpens += count($botDeviceIds);
            ++$statsChanged;
            $emailIds[(int) $stat['email_id']] = true;

            $io->writeln(sprintf(
                '  stat %d (email %d): open_count %d → %d, removing %d device rows',
                $statId, (int) $stat['email_id'], (int) $stat['open_count'], $newOpenCount, count($botDeviceIds)
            ), OutputInterface::VERBOSITY_VERBOSE);

            if (!$apply) {
                continue;
            }

            $this->connection->update(
                $prefix.'email_stats',
                [
                    'open_count'   => $newOpenCount,
                    'is_read'      => $isRead,
                    'date_read'    => $dateRead,
                    'last_opened'  => $lastOpened,
                    'open_details' => $newDetails,
                ],
                ['id' => $statId]
            );

            $this->connection->executeStatement(
                "DELETE FROM {$prefix}email_stats_devices WHERE id IN (:ids)",
                ['ids' => $botDeviceIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
        }

        $io->writeln(sprintf('Opens: %d stats with bursts, %d bot opens.', $statsChanged, $botOpens));

        return [
            'statsChanged' => $statsChanged,
            'botOpens'     => $botOpens,
            'emailIds'     => array_keys($emailIds),
        ];
    }

    /**
     * Sliding 30s (default) window: a device row is a bot row if it falls in any
     * window of $window seconds that contains >= $minIps distinct IPs.
     *
     * @param array<int,array{id:int|string,ip_id:int|string,date_opened:string}> $devices
     *
     * @return int[] bot device row ids
     */
    private function burstRowIds(array $devices, int $window, int $minIps): array
    {
        $n   = count($devices);
        $bot = [];
        if ($n < $minIps) {
            return [];
        }

        $ts = [];
        foreach ($devices as $i => $d) {
            $ts[$i] = strtotime($d['date_opened']);
        }

        for ($i = 0; $i < $n; ++$i) {
            $start = $ts[$i];
            $ips   = [];
            $idx   = [];
            for ($j = $i; $j < $n && ($ts[$j] - $start) < $window; ++$j) {
                $ips[(int) $devices[$j]['ip_id']] = true;
                $idx[] = $j;
            }
            if (count($ips) >= $minIps) {
                foreach ($idx as $k) {
                    $bot[(int) $devices[$k]['id']] = true;
                }
            }
        }

        return array_keys($bot);
    }

    /**
     * Remove bot opens from a serialized open_details blob.
     *
     * @param array<string,int> $botTimes datetime => count of bot opens at that time
     *
     * @return array{0:string,1:array{count:?int,first:?string,last:?string}} count is null when the blob is unparseable
     */
    private function pruneOpenDetails(string $blob, array $botTimes): array
    {
        if ('' === $blob) {
            return ['', ['count' => 0, 'first' => null, 'last' => null]];
        }

        $details = @unserialize($blob, ['allowed_classes' => false]);
        if (!is_array($details)) {
            // Unparseable — signal with a null count so the caller skips the stat.
            return [$blob, ['count' => null, 'first' => null, 'last' => null]];
        }

        $kept = [];
        foreach ($details as $entry) {
            $dt = is_array($entry) ? ($entry['datetime'] ?? null) : null;
            if (null !== $dt && isset($botTimes[$dt]) && $botTimes[$dt] > 0) {
                --$botTimes[$dt]; // consume one bot open at this timestamp
                continue;
            }
            $kept[] = $entry;
        }

        $kept  = array_values($kept);
        $first = null;
        $last  = null;
        foreach ($kept as $entry) {
            $dt = is_array($entry) ? ($entry['datetime'] ?? null) : null;
            if (null === $dt) {
                continue;
            }
            $first = (null === $first || $dt < $first) ? $dt : $first;
            $last  = (null === $last || $dt > $last) ? $dt : $last;
        }

        return [serialize($kept), ['count' => count($kept), 'first' => $first, 'last' => $last]];
    }

    /**
     * @param int[] $emailIds
     *
     * @return array{emails:int[]}
     */
    private function recountReadCounts(SymfonyStyle $io, string $prefix, array $emailIds, bool $apply): array
    {
        if (!$emailIds) {
            return ['emails' => []];
        }

        if ($apply) {
            $this->connection->executeStatement(
                "UPDATE {$prefix}emails e
                 SET e.read_count = (
                     SELECT COUNT(*) FROM {$prefix}email_stats s
                     WHERE s.email_id = e.id AND s.is_read = 1
                 )
                 WHERE e.id IN (:ids)",
                ['ids' => $emailIds],
                ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
            );
        }

        $io->writeln(sprintf('Read counts: %d emails re-derived.', count($emailIds)));

        return ['emails' => $emailIds];
    }

    /**
     * @return array{rows:int, changed:int}
     */
    private function recountTrackables(SymfonyStyle $io, string $prefix, bool $apply): array
    {
        // Re-derive every email trackable from the surviving page_hits, using
        // Mautic's stored semantics: hits = COUNT(*), unique = COUNT(DISTINCT tracking_id).
        $rows = $this->connection->fetchAllAssociative(
            "SELECT t.channel_id, t.redirect_id, t.hits, t.unique_hits
             FROM {$prefix}channel_url_trackables t
             WHERE t.channel = 'email'"
        );

        $changed = 0;
        foreach ($rows as $r) {
            $live = $this->connection->fetchAssociative(
                "SELECT COUNT(*) AS hits, COUNT(DISTINCT tracking_id) AS unique_hits
                 FROM {$prefix}page_hits
                 WHERE email_id = :emailId AND redirect_id = :redirectId",
                ['emailId' => (int) $r['channel_id'], 'redirectId' => (int) $r['redirect_id']]
            );

            $newHits   = (int) ($live['hits'] ?? 0);
            $newUnique = (int) ($live['unique_hits'] ?? 0);

            if ($newHits === (int) $r['hits'] && $newUnique === (int) $r['unique_hits']) {
                continue;
            }

            ++$changed;
            if ($apply) {
                $this->connection->update(
                    $prefix.'channel_url_trackables',
                    ['hits' => $newHits, 'unique_hits' => $newUnique],
                    ['channel' => 'email', 'channel_id' => (int) $r['channel_id'], 'redirect_id' => (int) $r['redirect_id']]
                );
            }
        }

        $io->writeln(sprintf('Trackables: %d of %d email rows differ from live page_hits.', $changed, count($rows)));

        return ['rows' => count($rows), 'changed' => $changed];
    }
}
