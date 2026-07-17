# Release Notes for Offsite

## Unreleased

### Added
- Initial release: scheduled off-site database backups for Craft CMS 5.
- Integrity-verified uploads (SHA-256, provider checksum API with automatic fallback).
- Remote catalog as the single source of truth — restorable from the bucket alone.
- Restore CLI with dry-run compatibility checks and pre-restore safety dump.
- Generation-based retention pruning with orphan detection.
- Slack notifications and heartbeat monitoring (healthchecks.io-compatible).
- `offsite/diagnose` console command for settings and connectivity checks.
- Control panel utility with run history.
