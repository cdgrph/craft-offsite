# Security Policy

## Reporting a vulnerability

Email **security@cdgrph.com**. Do not open public issues for security reports.

Please include: affected version, reproduction steps or proof of concept, and impact assessment if you have one. Reports in English or Japanese are both fine.

## Response SLA

- Acknowledgement: within 2 business days (JST)
- Initial assessment & severity triage: within 5 business days
- Fix for confirmed vulnerabilities: prioritized ahead of all other work; critical issues (credential exposure, unauthenticated restore/backup triggers, integrity bypass) target a patch release within 14 days

## Design notes relevant to security

- Credentials are read from environment variables or the AWS SDK credential chain; they are never persisted to the database or project config, and `craft offsite/diagnose` output is redacted. The settings model serializes only its five operational fields (enforced by a unit test), so control-panel settings saves can never write secrets to project config.
- TLS certificate verification is always on and cannot be disabled by configuration.
- The `lifecycle` retention mode lets you run with a credential that has **no delete permission**, so a compromised web server cannot destroy existing backups.
