# Installing Family Castel

Family Castel is built for ordinary shared hosting: **Apache + PHP 8.2+ +
MySQL/MariaDB**, no shell access needed. Everything happens through your
hosting panel and browser.

## Requirements

- PHP 8.2 or 8.3 with extensions: `pdo_mysql`, `mbstring`, `json`, `session`,
  `openssl`, `curl`, `zip` (standard on cyon, Hostpoint, all-inkl & co.)
- One MySQL 5.7+ / MariaDB 10.4+ database
- Apache with `.htaccess` support (mod_rewrite recommended but optional —
  Family Castel falls back to `?r=` routing automatically)
- HTTPS strongly recommended

## Fresh install

1. **Download** `family-castel-vX.Y.Z.zip` from the latest GitHub Release.
   Optionally verify it: the `.sha256` file next to it contains the checksum.
2. **Upload & extract** into your web root (`htdocs/`, `public_html/`, …) or
   any subdirectory (e.g. `/family/`) — both work.
3. **Create a database** and database user in your hosting panel; note the
   credentials.
4. **Open your site** in the browser. The installer wizard starts:
   - **System check** — verifies PHP, extensions, writability, and actively
     probes that `storage/` is NOT reachable over the web (a red result here
     means your host ignores `.htaccess` — do not proceed).
   - **Ownership proof** — the wizard shows a code that you read from
     `storage/setup-token.txt` via your file manager/FTP. This prevents
     strangers from installing "your" site before you do.
   - **Database** — enter the credentials from step 3.
   - **Family, parent account** — pick your family name, language, timezone
     and create the first parent login. Optionally install the demo family
     (Emma & Noah) to explore with realistic data.
5. Done. Log in, add your children, and hand them their QR code
   (child profile → QR) or a PIN for logging in.

## Updating

### Automatic (recommended)

`System → Updates` shows the latest GitHub release. One click:
Family Castel verifies the package checksum, creates a full backup, enters
maintenance mode, applies the update, migrates the database, health-checks,
and reopens. If anything fails, files **and** database are rolled back to the
pre-update backup automatically.

### Manual (FTP)

If your host blocks outgoing connections:

1. Download the release ZIP yourself and extract it **over** your
   installation (never delete `config/` or `storage/`).
2. Open `System → Updates` — Family Castel detects the new files and offers
   to complete the update (verify, backup, migrate, health-check).

## Backups & restore

`System → Backups`: create consistent snapshots (database + uploads +
config), download them, and restore with a typed confirmation. Before every
restore, an emergency backup of the current state is created and retained
forever — history is never lost.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "storage/ is PUBLICLY READABLE" in the system check | Your Apache ignores `.htaccess`. Enable `AllowOverride All` or ask your host. Do not install until this is green. |
| 404 on every page except the start page | mod_rewrite missing — Family Castel should fall back automatically; if not, links via `index.php?r=/...` always work. |
| White page | Check `storage/logs/` via FTP. `display_errors` is off by design. |
| Update stuck in maintenance | Open `/update.php` in the browser to resume, or follow the manual-recovery hint on the page. Your data is safe: every update starts with a full backup in `storage/backups/`. |
| Site says an update is pending after FTP upload | That is the boot gate — go to `System → Updates` (parents can always reach it) and complete the update. |
