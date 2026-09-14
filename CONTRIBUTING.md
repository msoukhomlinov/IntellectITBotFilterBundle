# Contributing

Contributions are welcome through GitHub issues and pull requests. Please follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Issues

- Search existing issues, open and closed, before opening a new one.
- Use the bug report or feature request template.
- Report security vulnerabilities privately, as described in [SECURITY.md](SECURITY.md). Do not open public issues for them.
- Remove hostnames, email addresses, IP addresses and contact data from logs and screenshots.

## Development setup

1. Use a non-production Mautic 7.x install. See [Requirements](README.md#requirements).
2. Place the repository at `plugins/IntellectITBotFilterBundle` in the Mautic root. The directory name must match the bundle name.
3. From the Mautic root, as the user that runs Mautic:

   ```bash
   php bin/console cache:clear
   php bin/console mautic:plugins:reload
   php bin/console mautic:botfilter:install
   ```

4. Publish the integration on **Settings > Plugins > IIT Bot Filter**.

After changing services or constructor signatures, run `php bin/console cache:clear` again so the container is rebuilt.

## Tests

Tests are standalone PHP scripts, not PHPUnit. See [Tests/README.md](Tests/README.md) for each script, how to run it, and its expected result.

- Several tests connect to the configured Mautic database. Run them only against a non-production instance.
- Tests that need existing records read IDs from the `BOTFILTER_TEST_*` environment variables (`BOTFILTER_TEST_STAT_ID`, `BOTFILTER_TEST_EMAIL_ID`, `BOTFILTER_TEST_LEAD_ID`, `BOTFILTER_TEST_IP_IDS`) and print `[SKIP]` when they are unset.
- Add or update tests for behaviour changes. A test should fail when the behaviour it covers is broken.
- Run `php -l` on every changed PHP file.

## Coding conventions

- Every PHP file starts with `<?php`, then `// SPDX-License-Identifier: GPL-3.0-or-later`, then `declare(strict_types=1);` where the file declares classes.
- Namespace: `MauticPlugin\IntellectITBotFilterBundle\...`.
- Services that sit on live tracking paths (for example `Helper/VelocityBotRatioHelper.php`) take nullable constructor arguments with `null` defaults. During a container rebuild, a stale compiled container may call the constructor with fewer arguments; the class must then fall back to core behaviour instead of throwing. Keep this pattern when adding constructor arguments to such services.
- Failures on tracking paths (detection queries, hit recording) must not interrupt tracking. Log the error and continue.
- Respect `MAUTIC_TABLE_PREFIX` in SQL.
- Do not modify Mautic core files. Extend through service decoration, event subscribers and plugin configuration.
- Do not commit instance-specific data: hostnames, email addresses, IP addresses, IDs from a real database, licence keys or credentials.

## Changelog

[CHANGELOG.md](CHANGELOG.md) follows [Keep a Changelog](https://keepachangelog.com/) and [Semantic Versioning](https://semver.org/). Add an entry under an `## [Unreleased]` heading (create it at the top if it is missing), in the matching section: `Added`, `Changed`, `Fixed`, `Removed` or `Security`. Note any upgrade steps, such as a required `cache:clear` or re-running `mautic:botfilter:install`.

## Pull requests

1. Fork the repository and create a branch from `main`.
2. Keep each pull request to one change or fix.
3. Update the CHANGELOG, and the README or `Tests/README.md` if behaviour, settings or tests change.
4. Complete the pull request template checklist.
5. The maintainer reviews the change and may ask for updates before merging.

## License

This project is licensed under GPL-3.0-or-later. By submitting a contribution, you agree that it is licensed under the same terms. See [LICENSE](LICENSE).
