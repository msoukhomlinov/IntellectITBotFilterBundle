<?php
// SPDX-License-Identifier: GPL-3.0-or-later

declare(strict_types=1);

namespace MauticPlugin\IntellectITBotFilterBundle\Command;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mautic:botfilter:update-asn-db',
    description: 'Download/refresh the MaxMind GeoLite2-ASN database used for IP enrichment.'
)]
class UpdateAsnDbCommand extends Command
{
    public function __construct(
        private CoreParametersHelper $coreParameters,
        private string $dataDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io   = new SymfonyStyle($input, $output);
        $auth = (string) $this->coreParameters->get('ip_lookup_auth');
        $key  = '';
        if (str_contains($auth, ':')) {
            $key = explode(':', $auth, 2)[1] ?? '';
        } elseif ('' !== $auth) {
            $key = $auth;
        }
        if ('' === $key) {
            $io->error('No MaxMind license key found in ip_lookup_auth.');

            return Command::FAILURE;
        }

        if (!is_dir($this->dataDir) && !mkdir($this->dataDir, 0775, true) && !is_dir($this->dataDir)) {
            $io->error('Cannot create data dir: '.$this->dataDir);

            return Command::FAILURE;
        }
        if (!is_writable($this->dataDir)) {
            $io->error('Data dir is not writable by '.(get_current_user() ?: 'this user').': '.$this->dataDir);

            return Command::FAILURE;
        }

        // NOTE: this is MaxMind's legacy `/app/geoip_download` endpoint, which authenticates
        // with the license key alone. It is deliberately NOT the newer
        // `/geoip/databases/<edition>/download` endpoint that core Mautic's
        // MaxmindDownloadLookup uses: that one needs HTTP Basic `accountId:licenseKey`, and an
        // `ip_lookup_auth` without a valid numeric account ID would return 401. Revisit if
        // MaxMind retires the legacy endpoint.
        $url = 'https://download.maxmind.com/app/geoip_download?edition_id=GeoLite2-ASN&license_key='
            .urlencode($key).'&suffix=tar.gz';
        // PharData infers the compression format from the filename, so the download must land
        // on a path ending in .tar.gz. tempnam() actually creates its file, so keep the
        // reservation ($tmpBase) and unlink it too — the previous code appended '.tar.gz' to
        // the tempnam() result and only ever removed the suffixed copy, leaking one empty
        // /tmp file on every single run.
        $tmpBase = tempnam(sys_get_temp_dir(), 'asn');
        if (false === $tmpBase) {
            $io->error('Cannot create a temporary file in '.sys_get_temp_dir().'.');

            return Command::FAILURE;
        }
        $tmp = $tmpBase.'.tar.gz';

        $io->writeln('Downloading GeoLite2-ASN…');
        // ignore_errors keeps the response body on a 4xx/5xx so MaxMind's own plain-text
        // reason ("Your account ID or license key could not be authenticated.") can be
        // surfaced instead of a bare "download failed". Previously this was an
        // error-suppressed file_get_contents() with no status and no detail, so a failing
        // run said nothing useful to cron output or the logs.
        $context = stream_context_create([
            'http' => [
                'method'        => 'GET',
                'timeout'       => 120,
                'ignore_errors' => true,
                'user_agent'    => 'Mautic IntellectITBotFilterBundle update-asn-db',
            ],
        ]);
        $data    = file_get_contents($url, false, $context);

        // MaxMind 302-redirects the download to a signed URL, so $http_response_header holds
        // the headers of every hop. Take the LAST status line, not the first, or a perfectly
        // good download would be reported as an HTTP 302 failure.
        $status = 0;
        foreach ((array) ($http_response_header ?? []) as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $header, $m)) {
                $status = (int) $m[1];
            }
        }

        if (false === $data) {
            $io->error('Download failed: no response from download.maxmind.com (network/egress or DNS).');

            @unlink($tmp);
            @unlink($tmpBase);

            return Command::FAILURE;
        }
        if ($status < 200 || $status > 299) {
            $reason = trim(substr(preg_replace('/\s+/', ' ', strip_tags($data)) ?? '', 0, 200));
            $io->error(sprintf(
                'Download failed: HTTP %d from download.maxmind.com%s. Check the MaxMind license key in ip_lookup_auth and the GeoLite2 entitlement.',
                $status,
                '' !== $reason ? ' — '.$reason : ''
            ));

            @unlink($tmp);
            @unlink($tmpBase);

            return Command::FAILURE;
        }
        if ('' === $data) {
            $io->error('Download failed: HTTP '.$status.' but an empty body.');

            @unlink($tmp);
            @unlink($tmpBase);

            return Command::FAILURE;
        }
        file_put_contents($tmp, $data);

        try {
            $phar = new \PharData($tmp);
            $extractTo = sys_get_temp_dir().'/asn_'.bin2hex(random_bytes(4));
            $phar->extractTo($extractTo, null, true);
            $found = null;
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractTo, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ('GeoLite2-ASN.mmdb' === $f->getFilename()) {
                    $found = $f->getPathname();
                    break;
                }
            }
            if (null === $found) {
                $io->error('GeoLite2-ASN.mmdb not found in the archive.');

                return Command::FAILURE;
            }
            $dest = rtrim($this->dataDir, '/').'/GeoLite2-ASN.mmdb';
            // An unchecked copy() previously reported success even when the write failed
            // (e.g. the destination owned by another user), which is exactly the kind of
            // silent no-op that let this database go stale unnoticed.
            if (!copy($found, $dest)) {
                $io->error('Could not write '.$dest.' — check ownership/permissions on '.$this->dataDir.'.');

                return Command::FAILURE;
            }
            clearstatcache(true, $dest);
            $io->success('GeoLite2-ASN.mmdb updated at '.$dest.' ('.number_format((int) filesize($dest)).' bytes).');
        } catch (\Throwable $e) {
            $io->error('Extraction failed: '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            @unlink($tmp);
            @unlink($tmpBase);
            if (isset($extractTo) && is_dir($extractTo)) {
                $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($extractTo, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
                foreach ($rii as $f) {
                    $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
                }
                @rmdir($extractTo);
            }
        }

        return Command::SUCCESS;
    }
}
