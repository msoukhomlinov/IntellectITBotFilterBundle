# Security Policy

## Supported versions

Only the latest minor release receives security fixes.

| Version | Supported |
|---|---|
| 1.10.x | Yes |
| < 1.10 | No |

## Reporting a vulnerability

Do not open a public issue, discussion or pull request for a vulnerability.

Report it privately through GitHub private vulnerability reporting:

1. Open the repository's **Security** tab.
2. Select **Report a vulnerability**.

Direct link: <https://github.com/msoukhomlinov/IntellectITBotFilterBundle/security/advisories/new>

## What to include

- Plugin version (from `Config/config.php` or the plugin list in Mautic).
- Mautic version, PHP version, and database (MySQL or MariaDB) version.
- The affected component, for example the `/bf/honeypot` endpoint, the admin page, a console command, or a dashboard widget.
- Steps to reproduce, and what an attacker can achieve.
- Any proof of concept, with hostnames, email addresses, IP addresses and contact data removed or replaced.

## What to expect

- Reports are acknowledged as soon as practical.
- The report is assessed and you may be asked for more detail through the private advisory.
- Confirmed issues are fixed in a patch release (for example 1.10.x). The CHANGELOG entry for that release notes the fix and states that an upgrade is recommended.
- Credit is given in the advisory if you want it.
