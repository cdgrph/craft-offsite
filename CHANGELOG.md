# Release Notes for Offsite

## Unreleased

### Added
- PostgreSQL `backupCommandFormat = 'custom'` support: dumps are verified to carry the pg_dump custom-archive magic before the run commits, stored uncompressed inside the archive (custom archives are already compressed), recorded as `dumpFormat` in catalog manifests, and restored through `pg_restore` — including the automatic rollback path, which sniffs the rollback dump's actual content to pick the restore binary. The pre-dump rejection still applies to `tar`/`directory` and to `custom` on Craft versions below 5.2 (which lack the per-run restore-format control needed to dispatch pg_restore).
- `offsite/restore/db` refuses to start when the currently configured `backupCommandFormat` is one the rollback path cannot dispatch (`tar`/`directory`, or `custom` on Craft < 5.2), since the pre-restore rollback dump would be taken in a format the automatic rollback could not restore. It also refuses when restores cannot run at all (`restoreCommand = false`) or when a custom backup — or a custom rollback dump — would bypass the pg_restore dispatch (a `restoreCommand` override, string or Closure), and runs the rollback dump through the same content sanity check as the backup path before the destructive import — output that fails the expected format's check (e.g. an empty file) aborts the restore with the database untouched.
- Control panel backup jobs now show phase-based progress for database dumping, archive creation, upload, catalog publishing, retention, and resumed-job finalization.
- A queued backup that is interrupted by a worker death (server reboot, deploy, queue TTR kill) can no longer turn into two backups. The job now carries a fixed run ID, and a re-delivered attempt whose backup was already committed just finishes the bookkeeping (notifications, heartbeat) instead of taking a second backup. Such a job is retried once; note that Craft only re-delivers it after its reservation expires, which takes up to the 24-hour TTR, so the next scheduled run usually gets there first.

### Changed
- The engine-layer `DatabaseDumper::dump()` contract now returns a `DumpResult` value object (dump path + `DumpFormat`) instead of a bare path.
- The queue TTR (24 hours) is now declared by the backup job itself.
- The local run cache never demotes a committed run when a re-delivered attempt fails afterwards, and a resumed run is now recorded under the original backup's timestamp and size instead of the retry's.

### Compatibility
- Backups taken before this release (plain dumps whose manifest has no `dumpFormat` field) are still read as plain and restored through the same `backup.sql` path; the new restore preflight rejections above apply to those restores as well.
- The reverse direction does not hold: custom-format backups (`backup.dump` entry) can only be restored by this release or later — an older Offsite 1.0.x install looks for `backup.sql` and refuses the archive. Plain backups restore on both.

## 1.0.1 - 2026-07-22

### Fixed
- `offsite/diagnose` now warns when Craft's `backupCommand` config setting is disabled (set to `false`), which makes database backups impossible. Previously diagnose reported no issues while `offsite/backup/db` failed — on some platforms (such as Craft Cloud), `backupCommand` resolves to `false` at runtime.

### Changed
- Replaced generic composer.json keywords with search-relevant terms to improve Plugin Store discoverability.

## 1.0.0 - 2026-07-19

### Added
- Operational settings (retention mode, generations to keep, notify on success, minimum free disk space, multipart threshold) are now editable in the control panel.
- Connection and notification settings (endpoint, region, bucket, key prefix, access keys, Slack webhook URL, notification email, heartbeat URL) can now be set in the control panel as environment variable references such as `$OFFSITE_SECRET_KEY`. Raw values are rejected — real values stay in `.env`, and only the reference is stored in project config. `config/offsite.php` keys still override control-panel values.

### Changed
- The control panel now requires the bucket setting, and the region setting when no custom endpoint is set — matching the runtime validator so an incomplete setup fails at save time instead of at the first backup run. Keys overridden in `config/offsite.php` are exempt, so a config-file override can never block saving.
- The utility status summary now states in text when the last successful backup is overdue (older than 48 hours), including the threshold in the warning, instead of relying on the status dot color alone.

## 1.0.0-beta.1 - 2026-07-18

### Added
- Initial release: scheduled off-site database backups for Craft CMS 5.
- Integrity-verified uploads (SHA-256, provider checksum API with automatic fallback).
- Remote catalog as the single source of truth — restorable from the bucket alone.
- Restore CLI with dry-run compatibility checks and pre-restore safety dump.
- Generation-based retention pruning with orphan detection.
- Slack notifications and heartbeat monitoring (healthchecks.io-compatible).
- `offsite/diagnose` console command for settings and connectivity checks.
- Control panel utility with run history.
