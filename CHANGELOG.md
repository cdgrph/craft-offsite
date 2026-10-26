# Release Notes for Offsite

## Unreleased

### Changed
- Plain SQL dumps must now end with the dump tool's completion marker (`-- Dump completed` for mysqldump / mariadb-dump, `-- PostgreSQL database dump complete` for pg_dump); a dump without it fails the backup run. The check also covers the pre-restore rollback dump taken by `offsite/restore/db`.
- A plain SQL dump made only of comment lines is now rejected, for backup runs, restores and the pre-restore rollback dump alike.
- Failure notifications now name dump content problems ("Database dump incomplete" / "Database dump has an unexpected format") instead of reporting an unexpected error.

### Compatibility
- If you override `backupCommand`, keep comments in the dump output: `--skip-comments` and `--compact` remove the completion marker, so every backup run fails after updating.
- Restoring a downloaded backup does not require the completion marker, so backups taken before this release (including those from a `backupCommand` override without comments) still restore.

## 1.1.0 - 2026-08-23

### Added
- PostgreSQL support for `backupCommandFormat = 'custom'`: dumps are stored as pg_dump custom archives (already compressed), verified before the run commits, recorded as `dumpFormat` in catalog manifests, and restored through `pg_restore` — including the automatic rollback path. Requires Craft 5.2+; `tar`/`directory` remain unsupported.
- `offsite/restore/db` now preflights the restore: it refuses to start when the configured `backupCommandFormat` would leave the automatic rollback unrestorable (`tar`/`directory`, or `custom` on Craft < 5.2), when restores cannot run at all (`restoreCommand = false`), or when a custom-format backup or rollback dump would bypass the pg_restore dispatch (via a `restoreCommand` override) — and it verifies the rollback dump's content before the destructive import.
- Control panel backup jobs now show phase-based progress (dump, archive, upload, catalog, retention).
- A queued backup interrupted by a worker death can no longer turn into two backups: the job carries a fixed run ID, and a re-delivered attempt whose backup already committed just finishes the bookkeeping. Craft only re-delivers it after the 24-hour reservation expires, so the next scheduled run usually gets there first.

### Changed
- `DatabaseDumper::dump()` now returns a `DumpResult` value object (dump path + format) instead of a bare path.
- The queue TTR (24 hours) is now declared by the backup job itself.
- The local run cache never demotes a committed run when a re-delivered attempt fails afterwards; a resumed run is recorded under the original backup's timestamp and size.

### Compatibility
- Backups taken before this release (no `dumpFormat` in the manifest) are still read as plain dumps and restored via `backup.sql`; the new restore preflight rejections apply to them as well.
- Custom-format backups (`backup.dump`) require this release or later to restore; plain backups restore on both.

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
