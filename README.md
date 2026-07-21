# Offsite

Scheduled, generation-managed off-site database backups for Craft CMS — with integrity-verified uploads, a remote catalog as the single source of truth, and failure notifications you can actually rely on.

## Requirements

- Craft CMS `^5.0`
- PHP `^8.2` with `ext-zip` and `ext-curl`
- `mysqldump` or `pg_dump` on the server — *the same requirement as Craft's own Database Backup utility* (Offsite delegates to Craft core's native backup)
- The default plain-SQL backup format. Custom `backupCommandFormat` values (`custom`/`tar`/`directory`, Craft 5.2+) are not supported in v1.0
- An S3-compatible object storage bucket
- Craft's `backupCommand` config setting must not be disabled (`false`) — Offsite delegates database dumps to Craft's native backup

## Quick start

```bash
composer require cdgrph/craft-offsite
php craft plugin/install offsite
```

Then either configure everything in the control panel (**Settings → Plugins → Offsite**) — connection and notification fields take environment variable references like `$OFFSITE_BUCKET`, with the real values in `.env` — or create `config/offsite.php`:

```php
<?php
return [
    'region' => 'ap-northeast-1',
    'bucket' => '$OFFSITE_BUCKET',
    'accessKey' => '$OFFSITE_ACCESS_KEY',
    'secretKey' => '$OFFSITE_SECRET_KEY',
    'heartbeatUrl' => '$OFFSITE_HEARTBEAT_URL',
    'slackWebhookUrl' => '$OFFSITE_SLACK_WEBHOOK',
];
```

Add a cron entry:

```cron
0 3 * * * php /path/to/craft offsite/backup/db
```

See [docs/setup.md](docs/setup.md) for all settings, provider-specific scheduling (Ploi, Forge, plain crontab), heartbeat monitoring, and retention configuration.

## Commands

| Command | Description |
|---|---|
| `craft offsite/backup/db` | Run a database backup (cron entry point; exit 0 = committed) |
| `craft offsite/list` | List backups from the remote catalog (source of truth) |
| `craft offsite/restore/db <runId>` | Restore a backup — **dry-run by default**, `--execute` to apply |
| `craft offsite/verify` | Verify every catalog entry has its object with the expected size |
| `craft offsite/prune` | Apply retention now; `--orphans` removes uncataloged objects after the `--orphan-age-hours` guard (24h by default; 0 requires `--force`) |
| `craft offsite/notify/resend <runId>` | Re-send the outcome notification for a past run |
| `craft offsite/diagnose` | Print a redacted diagnostic bundle for support requests |

## What Offsite is (and is not)

Offsite is a Craft *data* backup. Full disaster recovery also needs your codebase (Git), `composer.lock`, project config, and `.env` — see [docs/restore.md](docs/restore.md) for the complete recovery runbook.

Offsite also assumes a **single host**. Its backup/restore/prune lock is host-local, so horizontally scaled web nodes and separate cron hosts are not supported in v1.0 — run Offsite, including its queue worker, from exactly one host. See [Locking & concurrency](docs/setup.md#locking--concurrency) for the full contract.

## Craft Cloud

Offsite is designed for self-hosted and traditional hosting environments. [Craft's documentation](https://craftcms.com/docs/cloud/databases.html) states that the Database Backup utility is not supported on Craft Cloud, and in practice `backupCommand` resolves to `false` there at runtime — so `offsite/backup/db` cannot create database dumps on Craft Cloud, and `offsite/diagnose` reports the condition. Use Craft Cloud's built-in nightly backups and the Backups screen in Craft Console instead.

## Supported providers

| Provider | Status |
|---|---|
| AWS S3 | Supported (S3 API covered by the CI integration suite) |
| Cloudflare R2 | Supported, smoke-tested against the live service before each release |
| Backblaze B2 (S3-compatible API) | Supported, smoke-tested against the live service before each release |
| Other S3-compatible services | Best effort |

## How integrity is guaranteed

Every upload is verified before the run is committed: provider-native SHA-256 checksums where available, with a full read-back SHA-256 fallback otherwise. Multipart ETags are never used as an integrity proof. A backup only appears in `offsite/list` after its catalog entry is published — the remote catalog is the only source of truth, so a restore never trusts local state.

## Documentation

- [Setup & configuration](docs/setup.md)
- [IAM policies](docs/iam-policies.md)
- [Restore & disaster recovery](docs/restore.md)
- [Support policy](SUPPORT.md) / [Security policy](SECURITY.md)

## License

Licensed under the [Craft License](LICENSE.md) — one license per production environment. Purchase via the Craft Plugin Store.
