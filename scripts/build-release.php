<?php

declare(strict_types=1);

/**
 * Family Castel — production release builder (plan §15).
 *
 * Assembles the upload-to-shared-hosting ZIP from an explicit ALLOWLIST
 * (never a "strip unwanted" blocklist), generates the release.json manifest
 * the updater verifies file-by-file (version, min_php, update_from,
 * per-file SHA256, removed[]), and writes the .sha256 sidecar.
 *
 * CLI: php scripts/build-release.php [--out=dist] [--update-from=0.0.1]
 * Runs in CI and on a dev machine; NEVER ships in the release itself.
 */
final class ReleaseBuilder
{
    public const APP_NAME = 'Family Castel';
    public const MIN_PHP = '8.2.0';

    /** Single files shipped from the repository root (+ end-user docs). */
    private const ROOT_FILES = [
        'index.php', 'update.php', '.htaccess', 'sw.js', 'offline.html', 'VERSION',
        'README.md', 'CHANGELOG.md', 'LICENSE', 'SECURITY.md',
        'docs/INSTALL.md', 'docs/DEVELOPMENT.md', 'docs/ARCHITECTURE.md',
    ];

    /** Directories shipped recursively (dotfiles excluded except .htaccess). */
    private const DIRS = ['app', 'views', 'public-assets', 'lang'];

    /** config/ ships exactly these — never a live config.php or installed.lock.
     *  CAN_INSTALL is SYNTHESIZED (empty marker): the dev copy is consumed by
     *  the local install, but every release must arm the installer gate. */
    private const CONFIG_FILES = ['config/.htaccess', 'config/config.sample.php'];

    /** storage/ ships its guard + an EMPTY runtime skeleton. */
    private const STORAGE_SKELETON = ['storage/logs', 'storage/cache', 'storage/backups', 'storage/updates', 'storage/uploads'];

    public function __construct(
        private readonly string $rootDir,
    ) {
    }

    /**
     * @return array{zip: string, sha256: string, manifest: array<string, mixed>}
     */
    public function build(string $outDir, string $updateFrom = '0.0.1'): array
    {
        $version = trim((string) @file_get_contents($this->rootDir . '/VERSION'));
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            throw new RuntimeException('VERSION file missing or not semver: ' . $version);
        }
        if (!is_dir($outDir) && !@mkdir($outDir, 0775, true)) {
            throw new RuntimeException('Cannot create output directory: ' . $outDir);
        }

        $files = $this->collectFiles();

        $manifest = [
            'app' => self::APP_NAME,
            'version' => $version,
            'min_php' => self::MIN_PHP,
            'update_from' => $updateFrom,
            'files' => [],
            'removed' => $this->removedList(),
        ];
        foreach ($files as $relative => $absolute) {
            $hash = hash_file('sha256', $absolute);
            if (!is_string($hash)) {
                throw new RuntimeException('Cannot hash ' . $relative);
            }
            $manifest['files'][$relative] = $hash;
        }
        ksort($manifest['files']);

        $zipPath = $outDir . '/family-castel-v' . $version . '.zip';
        @unlink($zipPath);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create ' . $zipPath);
        }
        foreach ($files as $relative => $absolute) {
            if (!$zip->addFile($absolute, $relative)) {
                throw new RuntimeException('Cannot add ' . $relative);
            }
        }
        foreach (self::STORAGE_SKELETON as $dir) {
            $zip->addEmptyDir($dir);
        }
        $zip->addFromString('config/CAN_INSTALL', '');
        $manifest['files']['config/CAN_INSTALL'] = hash('sha256', '');
        ksort($manifest['files']);
        $zip->addFromString('release.json', json_encode(
            $manifest,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ));
        if (!$zip->close()) {
            throw new RuntimeException('Cannot finalize ' . $zipPath);
        }

        $hash = hash_file('sha256', $zipPath);
        if (!is_string($hash)) {
            throw new RuntimeException('Cannot hash the built zip.');
        }
        $shaPath = $zipPath . '.sha256';
        file_put_contents($shaPath, $hash . '  ' . basename($zipPath) . "\n");

        return ['zip' => $zipPath, 'sha256' => $shaPath, 'manifest' => $manifest];
    }

    /** @return array<string, string> relative path in zip => absolute source path */
    private function collectFiles(): array
    {
        $rootReal = realpath($this->rootDir);
        if ($rootReal === false) {
            throw new RuntimeException('Cannot resolve the project root.');
        }
        $files = [];
        foreach ([...self::ROOT_FILES, ...self::CONFIG_FILES, 'storage/.htaccess'] as $file) {
            $abs = $this->rootDir . '/' . $file;
            // Direct allowlist entries are subject to the SAME symlink rule
            // as directory contents — a symlinked VERSION or .htaccess could
            // smuggle out-of-tree content into the package.
            if (is_link($abs)) {
                throw new RuntimeException('Refusing to package symlink: ' . $file);
            }
            if (!is_file($abs)) {
                throw new RuntimeException('Allowlisted file missing: ' . $file);
            }
            // Symlinked PARENT directories (docs/, config/, storage/) would
            // pass the final-component check while pointing out of tree —
            // realpath equality proves the WHOLE path is link-free.
            if (realpath($abs) !== $rootReal . '/' . $file) {
                throw new RuntimeException('Path escapes the project root (symlinked parent?): ' . $file);
            }
            $files[$file] = $abs;
        }

        foreach (self::DIRS as $dir) {
            $base = $this->rootDir . '/' . $dir;
            if (is_link($base)) {
                throw new RuntimeException('Refusing to package symlinked directory: ' . $dir);
            }
            if (!is_dir($base)) {
                throw new RuntimeException('Allowlisted directory missing: ' . $dir);
            }
            if (realpath($base) !== $rootReal . '/' . $dir) {
                throw new RuntimeException('Directory escapes the project root: ' . $dir);
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
            );
            $baseReal = realpath($base);
            if ($baseReal === false) {
                throw new RuntimeException('Cannot resolve ' . $dir);
            }
            foreach ($iterator as $item) {
                // SYMLINKS NEVER SHIP: hash_file/addFile would follow them and
                // smuggle content from OUTSIDE the allowlist into the release.
                if ($item->isLink()) {
                    throw new RuntimeException('Refusing to package symlink: ' . $item->getPathname());
                }
                if (!$item->isFile()) {
                    continue;
                }
                // Containment proof: the resolved path must stay inside the
                // allowlisted directory (defense in depth vs. bind trickery).
                $real = realpath($item->getPathname());
                if ($real === false || !str_starts_with($real . '/', $baseReal . '/')
                    && $real !== $baseReal) {
                    throw new RuntimeException('File escapes its allowlisted directory: ' . $item->getPathname());
                }
                $name = $item->getFilename();
                // Dotfiles stay out of the package — except the .htaccess
                // guards, which are first-class release artifacts (plan §15).
                if (str_starts_with($name, '.') && $name !== '.htaccess') {
                    continue;
                }
                if (preg_match('/(~|\.(bak|backup|old|orig|swp|save|tmp|log|patch|rej))$/i', $name)
                    || $name === 'Thumbs.db' || $name === 'desktop.ini') {
                    continue;
                }
                $relative = $dir . '/' . substr($item->getPathname(), strlen($base) + 1);
                $files[str_replace('\\', '/', $relative)] = $item->getPathname();
            }
        }

        return $files;
    }

    /**
     * Paths retired relative to PREVIOUS releases; maintained in
     * scripts/release-removed.json (updater whitelists them to swap entries).
     *
     * @return list<string>
     */
    private function removedList(): array
    {
        $file = $this->rootDir . '/scripts/release-removed.json';
        if (!is_file($file)) {
            return [];
        }
        $list = json_decode((string) file_get_contents($file), true);
        if (!is_array($list)) {
            throw new RuntimeException('scripts/release-removed.json is not a JSON array.');
        }

        return array_values(array_map('strval', $list));
    }
}

// ---------------------------------------------------------------- CLI
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $options = getopt('', ['out::', 'update-from::']);
    $out = is_string($options['out'] ?? null) && $options['out'] !== '' ? $options['out'] : __DIR__ . '/../dist';
    $updateFrom = is_string($options['update-from'] ?? null) && $options['update-from'] !== ''
        ? $options['update-from']
        : '0.0.1';

    try {
        $result = (new ReleaseBuilder(dirname(__DIR__)))->build($out, $updateFrom);
        fwrite(STDOUT, 'Built   ' . $result['zip'] . "\n");
        fwrite(STDOUT, 'Sidecar ' . $result['sha256'] . "\n");
        fwrite(STDOUT, 'Files   ' . count($result['manifest']['files']) . "\n");
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'BUILD FAILED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
