#!/usr/bin/env bash
# Family Castel — updater system test (run INSIDE the app container):
#   docker compose -f docker/compose.yaml exec -T app bash tests/e2e-updater.sh
#
# Builds a sandbox copy of the app, a fixture release v9.9.9 (+ test migration),
# then drives update.php over real HTTP (php -S) through three scenarios:
#   1. happy path  — download → verify → swap → migrate → finish
#   2. checksum    — corrupted sha256 aborts BEFORE any mutation
#   3. rollback    — failing migration restores files AND database exactly
set -euo pipefail

ROOT="${FC_APP_ROOT:-/var/www/html}"
SANDBOX="${FC_E2E_SANDBOX:-/tmp/fc-updater-sandbox}"
RELEASES="${FC_E2E_RELEASES:-/tmp/fc-updater-releases}"
PORT="${FC_E2E_PORT:-8099}"
DBNAME=familycastel_updtest
DBHOST="${FC_TEST_DB_HOST:-db}"
DBROOTPW="${FC_TEST_DB_ROOT_PASSWORD:-root-dev-password}"

say() { echo "== $*"; }
fail() { echo "FAIL: $*" >&2; kill %1 2>/dev/null || true; exit 1; }

# ------------------------------------------------------------------ sandbox
say "building sandbox"
rm -rf "$SANDBOX" "$RELEASES"
mkdir -p "$SANDBOX" "$RELEASES"
cd "$ROOT"
cp -r index.php update.php .htaccess sw.js offline.html VERSION app views public-assets lang "$SANDBOX/"
mkdir -p "$SANDBOX/config" "$SANDBOX"/storage/{logs,cache,backups,updates,uploads}

php -r '
$pdo = new PDO("mysql:host='"$DBHOST"'", "root", "'"$DBROOTPW"'");
$pdo->exec("DROP DATABASE IF EXISTS '"$DBNAME"'");
$pdo->exec("CREATE DATABASE '"$DBNAME"' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("GRANT ALL ON '"$DBNAME"'.* TO \"fc\"@\"%\"");
$pdo->exec("FLUSH PRIVILEGES");
echo "db ready\n";
' 

cat > "$SANDBOX/config/config.php" << EOF
<?php return [
    'db' => ['host' => '$DBHOST', 'port' => 3306, 'name' => '$DBNAME', 'user' => 'fc', 'password' => 'fc-dev-password', 'prefix' => ''],
    'app' => ['locale' => 'de', 'timezone' => 'UTC', 'debug' => false, 'secret' => str_repeat('ab', 32)],
];
EOF
echo '{"installed_at":"test"}' > "$SANDBOX/config/installed.lock"

php -r '
define("FC_ROOT", "'"$SANDBOX"'");
require "'"$SANDBOX"'/app/Core/Autoloader.php";
FamilyCastel\Core\Autoloader::register("'"$SANDBOX"'/app");
require "'"$SANDBOX"'/app/Core/helpers.php";
$db = FamilyCastel\Core\Db::fromParams("'"$DBHOST"'", 3306, "'"$DBNAME"'", "fc", "fc-dev-password");
(new FamilyCastel\Database\Migrator($db, "'"$SANDBOX"'/app/Database/Migrations"))->migrate();
$db->execute("INSERT INTO children (name, theme, created_at, updated_at) VALUES (\"Emma\", \"fantasy\", UTC_TIMESTAMP(), UTC_TIMESTAMP())");
(new FamilyCastel\Domain\LedgerService($db))->post(1, 42, 42, "award", "Pre-update coins");
$db->execute("INSERT INTO settings (`key`, `value`, updated_at) VALUES (\"app.version\", JSON_QUOTE(\"0.1.0\"), UTC_TIMESTAMP())");
echo "sandbox DB ready\n";
'

# ------------------------------------------------------------------ fixture release v9.9.9
say "building fixture release v9.9.9"
STAGE=/tmp/fc-release-stage
rm -rf "$STAGE"; mkdir -p "$STAGE"
cp -r "$SANDBOX"/{index.php,.htaccess,sw.js,offline.html,app,views,public-assets,lang} "$STAGE/"
echo "9.9.9" > "$STAGE/VERSION"
cp "$ROOT/update.php" "$STAGE/update.php"
# new migration in the release
cat > "$STAGE/app/Database/Migrations/900_updater_test.php" << 'EOF'
<?php
declare(strict_types=1);
use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;
return static fn (Db $db) => new class($db) extends Migration {
    public function up(): void
    {
        $this->db->execute('CREATE TABLE IF NOT EXISTS updater_test_marker (id INT PRIMARY KEY) ENGINE=InnoDB');
    }
    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS updater_test_marker');
    }
};
EOF
# manifest
php -r '
$stage = "'"$STAGE"'";
$files = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $f) {
    if ($f->isFile()) {
        $rel = substr($f->getPathname(), strlen($stage) + 1);
        if ($rel === "release.json") continue;
        $files[$rel] = hash_file("sha256", $f->getPathname());
    }
}
file_put_contents($stage . "/release.json", json_encode([
    "app" => "Family Castel", "version" => "9.9.9", "min_php" => "8.2.0",
    "update_from" => "0.0.1", "files" => $files, "removed" => ["offline.html"],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "manifest: " . count($files) . " files\n";
'
php -r '
$zip = new ZipArchive();
$zip->open("'"$RELEASES"'/family-castel-v9.9.9.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$stage = "'"$STAGE"'";
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $f) { if ($f->isFile()) { $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($stage) + 1)); } }
$zip->close();
'
( cd "$RELEASES" && sha256sum family-castel-v9.9.9.zip | awk '{print $1}' > family-castel-v9.9.9.zip.sha256 )

# ------------------------------------------------------------------ start sandbox server
say "starting sandbox server on :$PORT"
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
sleep 0.5
( cd "$SANDBOX" && FC_UPDATE_TEST=1 php -S 127.0.0.1:$PORT >/tmp/fc-updater-server.log 2>&1 ) &
sleep 1

TOKEN="test-updater-token-$(date +%s)"
echo -n "$TOKEN" > "$SANDBOX/storage/updates/auth-token"

write_journal() { # $1 = zip path
  php -r '
  file_put_contents("'"$SANDBOX"'/storage/updates/state.json", json_encode([
      "step" => "preflight", "status" => "prepared", "started_at" => time(),
      "from_version" => "0.1.0",
      "target" => [
          "version" => "9.9.9",
          "zip_url" => "file://" . "'"$1"'",
          "sha256_url" => "file://" . "'"$1"'.sha256",
          "size" => filesize("'"$1"'"),
      ],
      "log" => [],
  ], JSON_PRETTY_PRINT));'
}

run_step() { # $1 = step; echoes response
  curl -s -X POST "http://127.0.0.1:$PORT/update.php" -d "step=$1" -d "token=$TOKEN"
}

drive_until_done() {
  local step="preflight" response
  for i in $(seq 1 20); do
    response=$(run_step "$step")
    echo "$step -> $response" | head -c 300; echo
    if echo "$response" | grep -q '"ok":false'; then return 1; fi
    if echo "$response" | grep -q '"step":"done"'; then return 0; fi
    step=$(echo "$response" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["step"] ?? "";')
    [ -n "$step" ] || return 1
  done
  return 1
}

# ------------------------------------------------------------------ scenario 1: happy path
say "SCENARIO 1: happy path"
write_journal "$RELEASES/family-castel-v9.9.9.zip"
drive_until_done || fail "happy path did not finish"

[ "$(cat "$SANDBOX/VERSION")" = "9.9.9" ] || fail "VERSION not swapped"
[ ! -f "$SANDBOX/offline.html" ] || fail "removed[] file still present"
[ ! -f "$SANDBOX/storage/maintenance.flag" ] || fail "maintenance flag left behind"
php -r '
$pdo = new PDO("mysql:host='"$DBHOST"';dbname='"$DBNAME"'", "fc", "fc-dev-password");
$gate = $pdo->query("SELECT write_locked FROM ops_state WHERE id=1")->fetch()[0];
if ((int) $gate !== 0) { fwrite(STDERR, "gate left locked\n"); exit(1); }
$marker = $pdo->query("SHOW TABLES LIKE \"updater_test_marker\"")->fetch();
if ($marker === false) { fwrite(STDERR, "migration 900 not applied\n"); exit(1); }
$hist = $pdo->query("SELECT status FROM update_history ORDER BY id DESC LIMIT 1")->fetch()[0];
if ($hist !== "success") { fwrite(STDERR, "history != success\n"); exit(1); }
$ver = json_decode($pdo->query("SELECT `value` FROM settings WHERE `key`=\"app.version\"")->fetch()[0], true);
if ($ver !== "9.9.9") { fwrite(STDERR, "app.version not recorded: $ver\n"); exit(1); }
$coins = $pdo->query("SELECT coin_balance FROM children WHERE id=1")->fetch()[0];
if ((int) $coins !== 42) { fwrite(STDERR, "data lost\n"); exit(1); }
echo "scenario 1 assertions OK\n";
' || fail "scenario 1 assertions"

# token replay after finish must be rejected (token consumed on commit)
REPLAY=$(run_step "finish")
echo "$REPLAY" | grep -q '"ok":false' || fail "token replay after finish was accepted"
echo "token replay rejected OK"

# ------------------------------------------------------------------ scenario 2: checksum abort
say "SCENARIO 2: checksum abort"
cp "$RELEASES/family-castel-v9.9.9.zip" "$RELEASES/family-castel-v9.9.8.zip"
echo "deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef" > "$RELEASES/family-castel-v9.9.8.zip.sha256"
echo -n "$TOKEN" > "$SANDBOX/storage/updates/auth-token"
write_journal "$RELEASES/family-castel-v9.9.8.zip"
if drive_until_done; then fail "checksum mismatch did not abort"; fi
grep -q '"status": "aborted"' "$SANDBOX/storage/updates/state.json" || fail "journal not aborted"
[ "$(cat "$SANDBOX/VERSION")" = "9.9.9" ] || fail "VERSION mutated on abort"
[ ! -f "$SANDBOX/storage/maintenance.flag" ] || fail "maintenance left on after abort"
echo "scenario 2 OK"

# ------------------------------------------------------------------ scenario 3: migration failure → rollback
say "SCENARIO 3: migration failure rolls back files + database"
STAGE2=/tmp/fc-release-stage-bad
rm -rf "$STAGE2"; cp -r "$STAGE" "$STAGE2"
echo "9.9.10" > "$STAGE2/VERSION"
cat > "$STAGE2/app/Database/Migrations/901_bad_migration.php" << 'EOF'
<?php
declare(strict_types=1);
use FamilyCastel\Core\Db;
use FamilyCastel\Database\Migration;
return static fn (Db $db) => new class($db) extends Migration {
    public function up(): void
    {
        $this->db->execute('CREATE TABLE half_done (id INT PRIMARY KEY) ENGINE=InnoDB');
        throw new RuntimeException('intentional test failure AFTER partial DDL');
    }
    public function down(): void
    {
        $this->db->execute('DROP TABLE IF EXISTS half_done');
    }
};
EOF
php -r '
$stage = "'"$STAGE2"'";
$files = [];
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $f) {
    if ($f->isFile()) {
        $rel = substr($f->getPathname(), strlen($stage) + 1);
        if ($rel === "release.json") continue;
        $files[$rel] = hash_file("sha256", $f->getPathname());
    }
}
file_put_contents($stage . "/release.json", json_encode([
    "app" => "Family Castel", "version" => "9.9.10", "min_php" => "8.2.0",
    "update_from" => "0.0.1", "files" => $files, "removed" => [],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'
php -r '
$zip = new ZipArchive();
$zip->open("'"$RELEASES"'/family-castel-v9.9.10.zip", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$stage = "'"$STAGE2"'";
$iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS));
foreach ($iter as $f) { if ($f->isFile()) { $zip->addFile($f->getPathname(), substr($f->getPathname(), strlen($stage) + 1)); } }
$zip->close();
'
( cd "$RELEASES" && sha256sum family-castel-v9.9.10.zip | awk '{print $1}' > family-castel-v9.9.10.zip.sha256 )

# baseline data before the failed update
php -r '
$pdo = new PDO("mysql:host='"$DBHOST"';dbname='"$DBNAME"'", "fc", "fc-dev-password");
$pdo->exec("UPDATE children SET coin_balance = 777 WHERE id = 1");
'
echo -n "$TOKEN" > "$SANDBOX/storage/updates/auth-token"
php -r '
$j = json_decode(file_get_contents("'"$SANDBOX"'/storage/updates/state.json"), true);
file_put_contents("'"$SANDBOX"'/storage/updates/state.json", json_encode([
    "step" => "preflight", "status" => "prepared", "started_at" => time(),
    "from_version" => "9.9.9",
    "target" => [
        "version" => "9.9.10",
        "zip_url" => "file://" . "'"$RELEASES"'/family-castel-v9.9.10.zip",
        "sha256_url" => "file://" . "'"$RELEASES"'/family-castel-v9.9.10.zip.sha256",
        "size" => filesize("'"$RELEASES"'/family-castel-v9.9.10.zip"),
    ],
    "log" => [],
], JSON_PRETTY_PRINT));'
if drive_until_done; then fail "bad migration did not fail the update"; fi
grep -q '"status": "rolled_back"' "$SANDBOX/storage/updates/state.json" || fail "journal not rolled_back"
[ "$(cat "$SANDBOX/VERSION")" = "9.9.9" ] || fail "files not rolled back (VERSION=$(cat "$SANDBOX/VERSION"))"
[ ! -f "$SANDBOX/storage/maintenance.flag" ] || fail "maintenance left on after rollback"
php -r '
$pdo = new PDO("mysql:host='"$DBHOST"';dbname='"$DBNAME"'", "fc", "fc-dev-password");
$coins = $pdo->query("SELECT coin_balance FROM children WHERE id=1")->fetch()[0];
if ((int) $coins !== 777) { fwrite(STDERR, "DB not restored exactly: coins=$coins\n"); exit(1); }
$half = $pdo->query("SHOW TABLES LIKE \"half_done\"")->fetch();
if ($half !== false) { fwrite(STDERR, "partial DDL survived rollback\n"); exit(1); }
$gate = $pdo->query("SELECT write_locked FROM ops_state WHERE id=1")->fetch()[0];
if ((int) $gate !== 0) { fwrite(STDERR, "gate left locked\n"); exit(1); }
$hist = $pdo->query("SELECT status FROM update_history ORDER BY id DESC LIMIT 1")->fetch()[0];
if ($hist !== "rolled_back") { fwrite(STDERR, "history != rolled_back\n"); exit(1); }
echo "scenario 3 assertions OK\n";
' || fail "scenario 3 assertions"

kill %1 2>/dev/null || true
say "ALL UPDATER SCENARIOS PASSED ✅"
