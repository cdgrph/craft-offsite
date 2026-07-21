# Release Notes for Offsite

## Unreleased

### Fixed
- `offsite/diagnose` now warns when Craft's `backupCommand` config setting is disabled (set to `false`), which makes database backups impossible. Previously diagnose reported no issues while `offsite/backup/db` failed — on some platforms (such as Craft Cloud), `backupCommand` resolves to `false` at runtime.

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
