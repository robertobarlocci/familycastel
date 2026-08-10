# 🏰 Family Castel

**A self-hosted family reward system that feels like a video game — not like chore software.**

Children complete **Sidequests**, earn **Coins** (spendable) and **XP** (permanent),
level up, watch their own world grow from a small camp to an epic kingdom (Fantasy
theme) or from a street pitch to a championship arena (Football theme), and redeem
Coins for rewards their parents approve. Parents get a fast, friendly control center;
kids get a game.

![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb3) ![MariaDB/MySQL](https://img.shields.io/badge/DB-MariaDB%20%7C%20MySQL-003545) ![License MIT](https://img.shields.io/badge/license-MIT-green)

## Why it exists

Most chore apps look like business software with points bolted on. Family Castel is
built around one rule: **if a screenshot looks like admin software, the design is
wrong.** The child experience is a polished game — worlds, levels, celebrations,
sounds — backed by an economy parents can trust.

## Highlights

- 🎮 **Two original themes** — Fantasy and Football — each with a five-tier world that
  grows with the child's level. Themes are data-driven; adding one is content, not code.
- 🪙 **Honest economy** — Coins and XP flow only through an append-only transaction
  ledger. Balances are derived, never edited. History is **never** deleted.
- ⚔️ **Sidequests** — one-time, daily, weekly or repeating; open to the whole family
  (first come, first served) or assigned; race-free claiming; parent approval with
  optional reward adjustment.
- 🎁 **Rewards & milestones** — atomic Coin reservations (no double-spending), custom
  wishes, long-term savings goals with progress.
- 🏆 **Achievements & Journal** — transactional rule engine, celebration animations,
  and a complete, filterable family history.
- 📱 **PWA** — installable on a child's tablet/phone, offline-friendly shell, QR-code
  child login.
- 🌍 **i18n** — German by default; English included; locale files are plain PHP arrays.
- 🔧 **Made for ordinary shared hosting** — Apache + PHP 8.2+ + MySQL/MariaDB. No
  Composer, no Node, no Docker, no cron, no Redis at runtime. Upload, open, install.
- 🔄 **Self-updater** — checks GitHub Releases, verifies SHA256, backs up first,
  applies atomically, migrates, health-checks — and rolls back files **and** database
  if anything fails. A manual-FTP update path exists for restrictive hosts.
- 💾 **Backups** — one-click consistent snapshots (database + uploads + config),
  download, retention, and disaster-recovery restore with typed confirmation.

## Requirements

| | Minimum |
|---|---|
| PHP | 8.2 (8.3 supported) with pdo_mysql, mbstring, json, session, openssl, curl, zip |
| Database | MySQL 5.7+ / MariaDB 10.4+ |
| Web server | Apache with `.htaccess` support (works without mod_rewrite via `?r=` fallback) |
| HTTPS | strongly recommended |

## Installation (shared hosting)

1. Download `family-castel-vX.Y.Z.zip` from the latest
   [GitHub Release](../../releases/latest).
2. Upload and extract it into your web root (or any subdirectory — subdirectory
   installs are fully supported).
3. Create a MySQL/MariaDB database and user in your hosting panel.
4. Open the site in your browser — the installer wizard starts automatically:
   system checks → database → admin account → optional demo family → done.
5. Log in, add your children, and hand them the QR code to log in.

See [docs/INSTALL.md](docs/INSTALL.md) for details, updating and troubleshooting.

## Local development

The dev environment (and only the dev environment) uses Docker:

```bash
docker compose -f docker/compose.yaml up -d
# app on http://localhost:8090, MariaDB internal, test DB familycastel_test
docker compose -f docker/compose.yaml exec app php vendor/bin/phpunit
docker compose -f docker/compose.yaml exec app bash tests/e2e-updater.sh
php scripts/build-release.php          # build a production ZIP into dist/
```

See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for architecture, testing and the
release pipeline, and [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the design.

## Security

Hardened for the shared-hosting reality: CSRF everywhere, contextual output escaping,
prepared statements only, throttled logins (parents, child PINs and QR tokens), locked
installer, `.htaccess` guards on every internal directory with an installer probe that
verifies they actually work, CSP without inline scripts, and an updater that only
accepts SHA256-verified packages from GitHub over HTTPS.
Found a vulnerability? Please read [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE). Bundled assets: qrcode.js (MIT), canvas-confetti (ISC),
Fredoka & Nunito fonts (SIL OFL 1.1).
