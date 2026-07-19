# Release Notes for Offsite

## Unreleased

### Added
- Operational settings (retention mode, generations to keep, notify on success, minimum free disk space, multipart threshold) are now editable in the control panel.
- Connection and notification settings (endpoint, region, bucket, key prefix, access keys, Slack webhook URL, notification email, heartbeat URL) can now be set in the control panel as environment variable references such as `$OFFSITE_SECRET_KEY`. Raw values are rejected — real values stay in `.env`, and only the reference is stored in project config. `config/offsite.php` keys still override control-panel values.

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
