# Changelog

All notable changes to Family Castel are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/) · Versioning: [SemVer](https://semver.org/).

## [Unreleased]

## [0.1.4] - 2026-08-10

Stay signed in. Parents and children no longer have to type a password or PIN
every time — Family Castel remembers the device and keeps you signed in, including
after closing the browser or restarting the phone.

### Added
- **Stay signed in on this device.** After signing in once, that device stays
  signed in. This works for parents and for children, survives closing the browser
  and any amount of time away, and needs no setting to switch on. Signing out still
  works exactly as before and makes that device forget you — other devices are
  unaffected.
- **Backups, restore, updates, the backup download and the diagnostics file now ask
  for your password again**, even while you stay signed in. Everything else — giving
  Coins, approvals, children, templates, quests, rewards — stays one tap away. This
  is what makes staying signed in safe: if a phone is lost or borrowed, nobody can
  wipe, restore or download the family's data without knowing the password.
- **A one-time note explaining the login cookie**, with a "Got it" button. It says
  plainly what is stored and why: one cookie so you stay signed in. No advertising,
  no analytics, nothing shared with anyone.

### Security
- The stored login is a random 256-bit token, kept only as a hash — a copy of the
  database (or of a downloaded backup) contains nothing that can sign anyone in.
- The token changes every time it is used, so a copied cookie stops working as soon
  as the real device is used again. If an old copy shows up later it is treated as
  theft: that device's login is cancelled and the event is recorded.
- Deactivating a parent or archiving a child takes effect immediately on every
  device, and restoring a backup signs all devices out once as a precaution.
- Signing in is remembered for 400 days and renewed on every visit, so an
  actively-used device stays signed in indefinitely. (Browsers cap saved cookies at
  roughly this length, so a longer promise would not have been kept.)

## [0.1.3] - 2026-08-10

Fixes a phone showing the old, cramped parent navigation instead of the hamburger
menu. If the parent area looks messy on your phone — all the menu entries crammed
across several rows on top of each other — installing this release fixes it. You do
not need to clear your browser data or reinstall the app from your home screen.

### Fixed
- Stylesheets and scripts are now requested with the app version attached to their
  address, so your browser fetches the current ones after an update instead of
  reusing what it downloaded weeks ago. Family Castel ships no cache rules of its
  own (deliberately — the target hosting cannot be relied on to support them), so
  browsers were free to keep an old stylesheet for days. On a phone that meant the
  mobile layout, including the hamburger menu, never took effect: the design had
  shipped in 0.1.1, but the file carrying it was never re-downloaded.
- The offline app's stored copy of the design files is now tied to the installed
  version, so it is refreshed on every update. Previously it was labelled "v1"
  permanently, so once a child had opened Family Castel from the home screen, that
  device kept serving the same stylesheets and scripts forever — and, because the
  offline helper also covers the parent area, it could keep the old look there too.
- The offline page itself is refreshed the same way, instead of being pinned to the
  version that was installed when the app was first opened.

### Added
- The release check now refuses to publish if a page's stylesheet is missing its
  version marker, and the test suite pins the agreement between the addresses the
  pages request and the ones the offline helper stores — a mismatch there is
  invisible in normal use and would quietly disable offline support.

## [0.1.2] - 2026-08-10

Repairs the in-app updater, which could leave a site without any styling after an
update. If your site currently looks unstyled, installing this release fixes it —
no manual step is needed.

### Fixed
- The in-app updater now installs the new release with explicit, web-servable file
  modes (directories 0755, files 0644 — the same state a fresh install produces).
  Previously the staged tree inherited the host's umask and the swap carried that
  mode into the live tree, so on hosting where Apache serves static files as a
  different user than PHP the whole `public-assets/` tree became unreadable and the
  site rendered without any CSS, fonts or JavaScript after updating.
- The application now repairs those file permissions by itself on the first request
  after an update. This matters because `update.php` is replaced last, so the update
  that delivers the fix above is still carried out by the previous updater: without
  this self-repair, the next update after installing 0.1.2 would break styling one
  final time. The check costs a single filesystem lookup per request and only acts
  when assets are provably unservable.
- The updater's post-update replacement of `update.php` now sets its mode
  explicitly, so a restrictive umask can no longer leave the executor unreadable by
  the web server and block all future updates.
- A rollback that restores retired files now creates directories with the correct
  mode and fails closed if it cannot.

### Added
- The updater's end-to-end test suite now runs under a restrictive umask and asserts
  the permissions of every swapped path, so this class of regression cannot ship
  again, plus assertions that `config/` and `storage/uploads/` survive an update
  byte-identical.

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
