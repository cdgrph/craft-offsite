# Restore & Disaster Recovery

## Everyday restore (same environment)

1. Find the run you want:

   ```bash
   php craft offsite/list
   ```

2. **Dry-run first** (default — nothing is touched):

   ```bash
   php craft offsite/restore/db 20260712-030000-Ab3dEf
   ```

   This prints the restore plan and the preflight checks:

   | Check | Meaning of a MISMATCH |
   |---|---|
   | site UID matches | The backup came from a *different Craft install* |
   | Craft version matches | Version drift since the backup was taken |
   | schema version matches | Migrations ran since the backup — plan to run `craft migrate/all` after restoring |
   | DB driver matches | Backup is mysql but this env is pgsql (or vice versa) — do not force this one |

3. Execute:

   ```bash
   php craft offsite/restore/db 20260712-030000-Ab3dEf --execute
   ```

   What happens, in order: the archive is downloaded and its **SHA-256 verified against the catalog**, the zip's CRC is checked, the SQL file is sanity-checked — only after all of that does anything destructive begin. Maintenance mode is enabled, a **pre-restore dump** of the current database is taken as a rollback point, the import runs, caches are flushed, and the schema version is compared against the catalog entry.

   > **Required**: block external traffic at the web-server/load-balancer level while restoring. Craft stores the maintenance flag in the database itself, so the flag is briefly overwritten by the imported data during the import (the same limitation applies to Craft's own `db/restore`). Offsite re-enables the flag immediately after the import, but external blocking is the only complete guarantee against serving a partially-imported database.

   Flags: `--force` skips the confirmation and preflight-mismatch stop (non-interactive use); `--skip-queue-check` proceeds even when queue jobs are reserved (stop your queue workers instead, if you can).

## Rollback

If the import itself fails, Offsite automatically restores the pre-restore dump. If you need to roll back *after* a successful restore, the pre-restore dump path was printed at the end of the run (kept under `storage/runtime/offsite/work/`):

```bash
php craft db/restore storage/runtime/offsite/work/pre-restore-20260712143000.sql
```

## Disaster recovery runbook (new server, nothing local)

Offsite backs up your **database**. A full recovery also needs your codebase (Git), `composer.lock`, project config (`config/project/`), and `.env` values — keep those in your repo / secret manager.

1. Provision the server (PHP, DB engine, web server) and deploy the codebase from Git.
2. `composer install` — this installs Craft *and* Offsite at the locked versions.
3. Restore your `.env` (DB credentials, `OFFSITE_*` variables) from your secret store.
4. Create the (empty) database and run `php craft install` **or** import any local seed — the restore will overwrite it entirely.
5. Install the plugin so the console commands exist: `php craft plugin/install offsite`.
6. Point Offsite at the same bucket with the same `config/offsite.php`. The remote catalog is the source of truth, so:

   ```bash
   php craft offsite/list                      # reads the remote catalog — your backups are all here
   php craft offsite/restore/db <runId>        # dry-run: expect a site-UID MISMATCH (new install)
   php craft offsite/restore/db <runId> --execute --force
   php craft migrate/all && php craft up       # if schema versions differed
   ```

7. Verify the site, then run one fresh backup to confirm the pipeline works end-to-end: `php craft offsite/backup/db`.

Practice this runbook before you need it. A backup you have never restored is a hope, not a backup.
