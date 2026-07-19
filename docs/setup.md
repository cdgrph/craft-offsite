# Setup & Configuration

Secrets and infrastructure settings live in `config/offsite.php`. Secrets are **never** stored in the database or project config — use environment variable references (`$VAR_NAME`) which are resolved at runtime via `App::parseEnv()`.

Five operational settings (`retentionMode`, `retentionKeepCount`, `notifyOnSuccess`, `minFreeDiskMb`, `multipartThresholdMb`) can also be edited in the control panel (**Settings → Plugins → Offsite**). Control-panel values are stored in project config and propagate to other environments on deploy. A key present in `config/offsite.php` always overrides the control-panel value — overridden fields are shown disabled with a warning.

## All settings

| Key | Default | Description |
|---|---|---|
| `endpoint` | `''` | Custom S3-compatible endpoint URL (R2, B2, MinIO). Empty = AWS S3. Must be `https://` unless `allowInsecureHttp` is set |
| `region` | `''` | Bucket region. Required when no custom endpoint is set |
| `bucket` | `''` | **Required.** Bucket name |
| `keyPrefix` | `''` | Key prefix inside the bucket (e.g. `mysite/`). **Give every Craft install its own prefix** when sharing a bucket. Retention only deletes cataloged generations belonging to the same site, but `prune --orphans` scans the entire `db/` area under the prefix and cannot filter by `siteUid`. Cataloged backups from every site sharing the catalog are protected; old uncataloged orphans from another site sharing the prefix remain eligible for deletion. A per-site prefix also keeps listings fast and IAM scoping possible |
| `accessKey` | `''` | Static access key. Empty = SDK default credential provider chain |
| `secretKey` | `''` | Static secret key. Empty = SDK default credential provider chain |
| `retentionMode` | `'plugin'` | `'plugin'` (plugin deletes old generations) or `'lifecycle'` (bucket rules delete; plugin never deletes) |
| `retentionKeepCount` | `30` | Generations to keep in `plugin` mode |
| `slackWebhookUrl` | `''` | Slack incoming webhook for notifications |
| `notifyEmail` | `''` | Email recipient for notifications (uses Craft's mailer) |
| `heartbeatUrl` | `''` | healthchecks.io-compatible ping URL |
| `notifyOnSuccess` | `false` | Also notify on successful runs (failures always notify) |
| `allowInsecureHttp` | `false` | Permit `http://` URLs — note this applies to the S3 endpoint *and* webhook/heartbeat URLs alike, with no host restriction. Intended for local development (e.g. MinIO); leave `false` in production so any non-TLS URL fails validation |
| `multipartThresholdMb` | `100` | Files larger than this use multipart upload (minimum 5) |
| `minFreeDiskMb` | `2048` | Preflight: minimum free disk space in the work directory. Budget roughly 3× your uncompressed dump size (dump + zip + verification read-back) |

> **Backup format**: Offsite v1.0 requires Craft's default plain-SQL backup format. On PostgreSQL, if `backupCommandFormat` is set to `custom`, `tar`, or `directory` (Craft 5.2+), the backup run detects the unsupported setting and fails immediately before the dump, consuming neither dump time nor disk space, and triggers your failure notifications. Run `php craft offsite/diagnose` to see the current format and any warning. Revert to the default format to use Offsite.

> **Single-host locking**: the backup/restore/prune mutual-exclusion lock is host-local. Run Offsite from exactly one host — see [Locking & concurrency](#locking--concurrency).

Example with environment variables:

```php
<?php
return [
    'endpoint' => '$OFFSITE_ENDPOINT',        // e.g. https://<account>.r2.cloudflarestorage.com
    'region' => 'auto',
    'bucket' => '$OFFSITE_BUCKET',
    'accessKey' => '$OFFSITE_ACCESS_KEY',
    'secretKey' => '$OFFSITE_SECRET_KEY',
    'retentionMode' => 'plugin',
    'retentionKeepCount' => 30,
    'heartbeatUrl' => '$OFFSITE_HEARTBEAT_URL',
    'slackWebhookUrl' => '$OFFSITE_SLACK_WEBHOOK',
];
```

## Credentials: the provider chain

If `accessKey` and `secretKey` are both empty, the AWS SDK's **default credential provider chain** is used: environment variables (`AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY`), the shared credentials file (`~/.aws/credentials`), or — recommended on EC2/ECS — the instance profile / task role. On AWS infrastructure, prefer instance profiles over static keys: nothing to rotate, nothing to leak.

## Scheduling the backup

The cron entry point is `craft offsite/backup/db`. It exits `0` when the backup committed and `1` on failure, so it composes with any scheduler.

### Plain crontab

```cron
0 3 * * * php /home/user/site/craft offsite/backup/db >> /var/log/offsite.log 2>&1
```

### Ploi

Server → your server → **Cronjobs** → Add:

- Command: `php /home/ploi/example.com/craft offsite/backup/db`
- User: `ploi`, Frequency: custom `0 3 * * *`

### Laravel Forge

Server → **Scheduler** → New Scheduled Job:

- Command: `php /home/forge/example.com/craft offsite/backup/db`
- User: `forge`, Frequency: Custom (`0 3 * * *`)

## Locking & concurrency

The backup pipeline, an `--execute` restore, and `offsite/prune` all hold the same host-local `flock` throughout the protected pipeline, excluding notifications and heartbeat delivery. A second process retries for at most 250 milliseconds to avoid colliding with a diagnostic probe, then fails instead of waiting for the active run. Two entry points share the backup pipeline and therefore the lock: the `offsite/backup/db` command and the **Run backup now** button in the CP utility, which enqueues that same pipeline as a Craft queue job. A restore *dry-run* takes no lock.

### What the lock guarantees

The guard file is `storage/offsite/lock.guard` by default. Once a process acquires it, the lock cannot be taken over while any process still holds its file descriptor. This includes `mysqldump` and `mysql`: if the parent PHP process dies while either command continues working, the child keeps the lock until it exits. The lock is therefore released when the last process holding the descriptor exits, not necessarily when the original PHP process exits.

`storage/offsite/lock.json` is diagnostic metadata only. It records the last owner, recorded PID, acquisition time, and current phase, but it is not the source of mutual exclusion and may be stale. A failure to update this metadata does not stop a run whose kernel lock remains valid. Use `php craft offsite/diagnose` to inspect the lock and obtain the exact `lsof` command for identifying the real holder.

An interrupted backup can still leave an uncataloged object behind. `offsite/prune --orphans` cleans it up after the age threshold is reached, 24 hours by default.

For the CP **Run backup now** button in production, set Craft's `runQueueAutomatically` general config setting to `false` and run a dedicated CLI queue worker such as `php craft queue/listen`. The listener isolates each job in a child process by default and uses the job's TTR as its process timeout. Offsite explicitly sets a 24-hour TTR so the default 300-second timeout cannot kill the parent while an inherited `mysqldump` descriptor keeps the lock. Do not let a web request own a lock for a multi-hour backup.

### What it does not guarantee in v1.0

**Cross-host exclusion.** The lock file is host-local, and hosts do not coordinate through it. Moving `storage/` onto a shared network filesystem does not make this supported: Offsite does not test or support `flock()` over NFS. Horizontally scaled web nodes or a separate cron host are therefore **not supported in v1.0**. Run Offsite from a single host, with the queue worker on that same host.

**Automatic recovery from a hung process.** There is no timeout or lock-stealing escape hatch. Heartbeat monitoring should alert you to a hung run. Use `php craft offsite/diagnose`, then run the displayed `lsof storage/offsite/lock.guard` command to identify the actual holder. Killing only the PID recorded in `lock.json` may not release the lock if another process still holds the descriptor. Never kill an active `mysql` import merely to clear the lock; follow the phase-specific recovery instructions printed by `offsite/diagnose`.

**Deletion of the guard during a run.** `flock()` belongs to an inode. Never delete or replace `storage/offsite/lock.guard`: deletion does not break the existing lock, it duplicates it. The running holder keeps its lock on the old inode while a new process can lock the replacement, allowing both to run against the same database. RunLock validates the inode during acquisition and at every phase boundary, failing closed when it detects replacement or an unreadable guard. The disposable dump and zip files remain under `storage/runtime/offsite/work/`; clearing those work files does not create a second lock, although deleting files needed by an active run will make that run fail.

## Heartbeat monitoring (recommended)

Notifications alone cannot tell you that cron **stopped running**. Configure a dead-man's-switch:

1. Create a check at [healthchecks.io](https://healthchecks.io) (or any compatible service) with a period of 24 hours and a grace period matching your schedule.
2. Set `heartbeatUrl` to the ping URL.
3. Offsite pings the URL after every committed run, and `<url>/fail` after failures — so you get alerted both on failing backups *and* on silence.

## Retention

### `plugin` mode (default)

Offsite keeps the newest `retentionKeepCount` generations and deletes older ones (plus their catalog entries) after each committed run. The credential needs `s3:DeleteObject` — see [iam-policies.md](iam-policies.md).

### `lifecycle` mode

Offsite never deletes anything; you delegate expiry to bucket lifecycle rules. Use this when you want the backup credential to have **no delete permission at all** (ransomware-resistant). Example S3 lifecycle rule (console: Bucket → Management → Lifecycle rules):

```json
{
    "Rules": [
        {
            "ID": "offsite-expire-old-backups",
            "Status": "Enabled",
            "Filter": { "Prefix": "db/" },
            "Expiration": { "Days": 60 }
        }
    ]
}
```

Note: expired objects' catalog entries are cleaned up opportunistically; `craft offsite/verify` reports catalog entries whose object is gone.

### Orphaned objects

`craft offsite/prune --orphans` deletes `db/` objects that are not listed in the catalog. It cannot distinguish an abandoned object from an in-flight object whose upload has finished but whose catalog entry has not yet been published, so by default it protects uncataloged objects newer than 24 hours. Change this threshold with `--orphan-age-hours`; its default is `24`. In `lifecycle` mode, `offsite/prune` refuses to perform any deletion, including an orphan sweep.

Pass a whole number of hours. Yii2 coerces the option to an integer: `abc` and `0.5` become `0`, `1.5` becomes `1`, and a bare `--orphan-age-hours` without a value becomes `true` and then `1`. Setting `--orphan-age-hours=0` disables the age guard and therefore requires `--force`; without it, the command exits without pruning.

The threshold must exceed the longest upload and verification time, including a full read-back on providers that do not return a native checksum. This remains necessary when separate Craft installs share a bucket and `keyPrefix`: each install has its own host-local lock, so one site's prune cannot see another site's in-flight upload. The command warns when the selected threshold is below the default 24 hours; when the guard is enabled, it also reports how many young uncataloged objects the guard protected.

The orphan scan covers the entire `db/` area under the configured `keyPrefix` and cannot filter by `siteUid`. The full shared catalog is used to protect committed backups from every site, but old orphan objects abandoned by another site sharing the prefix remain eligible for deletion. Give each Craft install its own prefix when sharing a bucket.

### Aborted multipart uploads

Add a lifecycle rule so interrupted uploads don't accumulate storage costs:

```json
{
    "Rules": [
        {
            "ID": "offsite-abort-incomplete-multipart",
            "Status": "Enabled",
            "Filter": { "Prefix": "db/" },
            "AbortIncompleteMultipartUpload": { "DaysAfterInitiation": 2 }
        }
    ]
}
```

## Server-side encryption (recommended)

Enable **default bucket encryption**. SSE-S3 (`AES256`) is sufficient for backups and needs no extra permissions. SSE-KMS gives you audit trails and key rotation control, but the backup credential additionally needs `kms:GenerateDataKey` and `kms:Decrypt` on the key — factor that in before choosing it.
