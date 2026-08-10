# Changelog

All notable changes to Family Castel are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: [SemVer](https://semver.org/).

## [Unreleased]

### Fixed
- The in-app updater now installs the new release with explicit, web-servable file
  modes (directories 0755, files 0644 — the same state a fresh install produces).
  Previously the staged tree inherited the host's umask and the swap carried that
  mode into the live tree, so on hosting where Apache serves static files as a
  different user than PHP the whole `public-assets/` tree became unreadable and the
  site rendered without any CSS, fonts or JavaScript after updating.
- The updater's post-update replacement of `update.php` now sets its mode
  explicitly, so a restrictive umask can no longer leave the executor unreadable by
  the web server and block all future updates.
- A rollback that restores retired files now creates directories with the correct
  mode and fails closed if it cannot.

## [0.1.1] - 2026-08-10

### Added
- Phone-first parent navigation with an accessible hamburger menu, large touch
  targets, active-page states, and a direct entry to the in-app updater.
- Responsive child dock and layouts for quests, rewards, journal, and settings.
- Mobile interaction, accessibility, overflow, and visual-regression coverage.

### Fixed
- Fresh installs now use universal query routing until the installer has tested
  and saved the host's rewrite capability, fixing no-rewrite subdirectory hosts.
- Responsive form controls, approval cards, update/status screens, and child
  theme controls no longer overflow narrow phone viewports.

### Changed
- GitHub workflows now use the current Node 24-compatible major releases of
  GitHub's checkout, Node setup, artifact, and release actions.

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

[0.1.1]: https://github.com/robertobarlocci/familycastel/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/robertobarlocci/familycastel/releases/tag/v0.1.0
