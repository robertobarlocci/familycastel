# Changelog

All notable changes to Family Castel are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: [SemVer](https://semver.org/).

## [0.1.0] - 2026-08-10

First complete release.

### Added
- Installer wizard: system checks (PHP/extensions/writability/HTTPS and a real
  HTTP probe that proves `storage/` is web-inaccessible), database setup, admin
  account, optional demo family, install lock.
- Economy: append-only Coin/XP transaction ledger, derived balances, atomic
  reward reservations, idempotent approvals, permanent XP with a configurable
  level curve (up to level 500).
- Sidequests: one-time/daily/weekly/repeating, family-wide first-come or
  assigned, race-free claim slots, child accept/complete, parent
  approve/adjust/reject, lazy expiry (no cron).
- Suggestions (child quest ideas), rewards catalog with custom wishes,
  milestones/savings goals, achievements with celebration queue, in-app
  notifications, full Journal with filters.
- Two themes with five world tiers each: Fantasy (camp → epic kingdom) and
  Football (street pitch → championship arena); per-child theme allowlist,
  sounds, self-hosted Fredoka/Nunito.
- PWA: dynamic manifest (subdirectory-safe), static-shell service worker with
  per-installation cache keys, offline page, QR child login.
- Ops: consistent-snapshot backups (DB + uploads + config) with retention and
  never-pruned emergency backups; disaster-recovery restore (typed
  confirmation, emergency backup, fail-closed revert); GitHub-releases
  self-updater with SHA256 verification, pre-update backup, journaled steps,
  resume, and full file+database rollback; manual-FTP update chain; system
  status page; redacted diagnostics download.
- i18n: German (default) and English.
- Release pipeline: allowlist-based ZIP builder with per-file SHA256 manifest.

[0.1.0]: https://github.com/robertobarlocci/familycastel/releases/tag/v0.1.0
