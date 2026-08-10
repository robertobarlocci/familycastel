<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * The release ZIP is what non-technical parents upload to shared hosting —
 * it must be assembled from an explicit ALLOWLIST (plan §15), carry every
 * .htaccess as a first-class artifact, and ship a release.json manifest the
 * updater can verify file-by-file.
 */
final class ReleaseBuilderTest extends TestCase
{
    private static array $built;
    private static string $outDir;

    public static function setUpBeforeClass(): void
    {
        require_once FC_ROOT . '/scripts/build-release.php';
        self::$outDir = sys_get_temp_dir() . '/fc-release-' . bin2hex(random_bytes(4));
        self::$built = (new \ReleaseBuilder(FC_ROOT))->build(self::$outDir, updateFrom: '0.0.1');
    }

    public static function tearDownAfterClass(): void
    {
        exec('rm -rf ' . escapeshellarg(self::$outDir));
    }

    public function testZipAndChecksumExist(): void
    {
        self::assertFileExists(self::$built['zip']);
        self::assertFileExists(self::$built['sha256']);
        $expected = strtolower(strtok(trim((string) file_get_contents(self::$built['sha256'])), " \t"));
        self::assertSame(hash_file('sha256', self::$built['zip']), $expected, 'sha256 sidecar matches the zip');
    }

    public function testManifestIdentityAndVersion(): void
    {
        $m = self::$built['manifest'];
        self::assertSame('Family Castel', $m['app']);
        self::assertSame(trim((string) file_get_contents(FC_ROOT . '/VERSION')), $m['version']);
        self::assertSame('8.2.0', $m['min_php']);
        self::assertSame('0.0.1', $m['update_from']);
        self::assertIsArray($m['removed']);
    }

    public function testEveryManifestFileIsInZipWithMatchingHash(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(self::$built['zip']) === true);
        foreach (self::$built['manifest']['files'] as $path => $sha) {
            $content = $zip->getFromName($path);
            self::assertNotFalse($content, "manifest file missing from zip: {$path}");
            self::assertSame($sha, hash('sha256', $content), "hash mismatch: {$path}");
        }
        $zip->close();
    }

    public function testZipContainsOnlyAllowlistedTopLevelEntries(): void
    {
        $allowedTop = ['index.php', 'update.php', '.htaccess', 'sw.js', 'offline.html', 'VERSION',
            'release.json', 'README.md', 'CHANGELOG.md', 'LICENSE', 'SECURITY.md', 'docs',
            'app', 'views', 'public-assets', 'lang', 'config', 'storage'];
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(self::$built['zip']) === true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $top = explode('/', (string) $zip->getNameIndex($i), 2)[0];
            self::assertContains($top, $allowedTop, 'unexpected top-level entry: ' . $zip->getNameIndex($i));
        }
        $zip->close();
    }

    public function testForbiddenArtifactsNeverShip(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(self::$built['zip']) === true);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            self::assertStringNotContainsString('tests/', $name);
            self::assertStringNotContainsString('docker/', $name);
            self::assertStringNotContainsString('scripts/', $name);
            self::assertStringNotContainsString('.git', $name);
            self::assertDoesNotMatchRegularExpression('#^config/config\.php$#', $name, 'live config must never ship');
            self::assertDoesNotMatchRegularExpression('#^config/installed\.lock$#', $name);
            self::assertDoesNotMatchRegularExpression('#^storage/(logs|cache|backups|updates|uploads)/.+#', $name, 'storage contents must never ship');
        }
        $zip->close();
    }

    public function testHtaccessFilesAreFirstClassArtifacts(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(self::$built['zip']) === true);
        foreach (['.htaccess', 'app/.htaccess', 'views/.htaccess', 'config/.htaccess', 'lang/.htaccess', 'storage/.htaccess'] as $ht) {
            self::assertNotFalse($zip->locateName($ht), "missing {$ht}");
            self::assertArrayHasKey($ht, self::$built['manifest']['files'], "{$ht} not in manifest");
        }
        $zip->close();
    }

    public function testSymlinksAreRefused(): void
    {
        // A symlink inside an allowlisted dir must ABORT the build — following
        // it could smuggle out-of-tree content (secrets!) into the release.
        $tree = self::$outDir . '/symlink-tree';
        foreach (['app', 'views', 'public-assets', 'lang', 'config', 'storage', 'docs'] as $d) {
            mkdir($tree . '/' . $d, 0775, true);
        }
        foreach (['index.php', 'update.php', '.htaccess', 'sw.js', 'offline.html',
            'README.md', 'CHANGELOG.md', 'LICENSE', 'SECURITY.md'] as $f) {
            file_put_contents($tree . '/' . $f, "x\n");
        }
        file_put_contents($tree . '/VERSION', "9.9.9\n");
        foreach (['INSTALL.md', 'DEVELOPMENT.md', 'ARCHITECTURE.md'] as $doc) {
            file_put_contents($tree . '/docs/' . $doc, "doc\n");
        }
        foreach (['config/.htaccess', 'config/config.sample.php', 'storage/.htaccess'] as $f) {
            file_put_contents($tree . '/' . $f, "guard\n");
        }
        file_put_contents($tree . '/secret-outside.txt', 'the secret');
        symlink($tree . '/secret-outside.txt', $tree . '/app/innocent.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/symlink/i');
        (new \ReleaseBuilder($tree))->build(self::$outDir . '/symlink-out');
    }

    public function testInstallPrerequisitesShip(): void
    {
        $zip = new \ZipArchive();
        self::assertTrue($zip->open(self::$built['zip']) === true);
        self::assertNotFalse($zip->locateName('config/CAN_INSTALL'));
        self::assertNotFalse($zip->locateName('config/config.sample.php'));
        // storage skeleton dirs exist (as directory entries) but stay empty
        foreach (['storage/logs/', 'storage/cache/', 'storage/backups/', 'storage/updates/', 'storage/uploads/'] as $dir) {
            self::assertNotFalse($zip->locateName($dir), "missing skeleton dir {$dir}");
        }
        $zip->close();
    }
}
