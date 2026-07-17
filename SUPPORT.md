# Support

## Scope

**In scope** — defects in the plugin itself:

- Backup/restore pipeline bugs, integrity verification issues
- Incorrect retention behavior, notification/heartbeat delivery bugs
- Documentation errors
- Compatibility issues with supported providers (AWS S3, Cloudflare R2, Backblaze B2)

**Out of scope**:

- Investigating or recovering individual server environments (broken cron daemons, PHP misconfiguration, network/firewall issues)
- Hands-on disaster recovery for your site (the runbook in [docs/restore.md](docs/restore.md) is the supported path)
- Multi-host installs — the lock is host-local, so Offsite must run from a single host (see [Locking & concurrency](docs/setup.md#locking--concurrency))
- Custom feature development, other S3-compatible providers beyond best effort

## How to file an issue

Open an issue on this repository and **attach the output of**:

```bash
php craft offsite/diagnose
```

The output is redacted (never contains credentials) and answers most environment questions up front. Issues without it will get a first reply asking for it, so include it to save a round-trip.

## Response targets

- First response: within 2 business days (JST)
- Confirmed data-integrity bugs are prioritized above everything else

## Version support / EOL

- The latest minor release of the plugin is supported on Craft 5 (LTS until 2030-12).
- Security fixes land on the latest release only; upgrade to receive them.
- Craft 6 support will be announced separately; see the roadmap in the Plugin Store listing.
