<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Domain\UpdateChecker;
use PHPUnit\Framework\TestCase;

/**
 * Network-free coverage: the cache paths and the strict validation that
 * every returned release must pass (the updater is steered by this data).
 */
final class UpdateCheckerTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir() . '/fc-updchk-' . bin2hex(random_bytes(4));
        mkdir($this->cacheDir, 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->cacheDir));
    }

    private function warmCache(array $release): void
    {
        file_put_contents($this->cacheDir . '/update-check.json', json_encode([
            'fetched_at' => time(),
            'etag' => '"abc"',
            'release' => $release,
        ]));
    }

    private function validRelease(): array
    {
        return [
            'version' => '1.2.3',
            'notes' => 'Notes',
            'zip_url' => 'https://github.com/owner/repo/releases/download/v1.2.3/family-castel-v1.2.3.zip',
            'sha256_url' => 'https://github.com/owner/repo/releases/download/v1.2.3/family-castel-v1.2.3.zip.sha256',
            'size' => 400000,
            'checked_at' => '2026-01-01T00:00:00+00:00',
        ];
    }

    public function testFreshCacheHitReturnsValidatedRelease(): void
    {
        $this->warmCache($this->validRelease());
        $release = (new UpdateChecker($this->cacheDir))->latest();
        self::assertNotNull($release);
        self::assertSame('1.2.3', $release['version']);
    }

    public function testPoisonedCacheHostIsRejected(): void
    {
        $bad = $this->validRelease();
        $bad['zip_url'] = 'https://evil.example.com/family-castel-v1.2.3.zip';
        $this->warmCache($bad);
        self::assertNull((new UpdateChecker($this->cacheDir))->latest(), 'non-GitHub host must never leave the checker');
    }

    public function testPoisonedCacheVersionIsRejected(): void
    {
        $bad = $this->validRelease();
        $bad['version'] = '1.2.3; rm -rf /';
        $this->warmCache($bad);
        self::assertNull((new UpdateChecker($this->cacheDir))->latest());
    }

    public function testPoisonedCacheSchemeIsRejected(): void
    {
        $bad = $this->validRelease();
        $bad['sha256_url'] = 'http://github.com/owner/repo/x.sha256';
        $this->warmCache($bad);
        self::assertNull((new UpdateChecker($this->cacheDir))->latest(), 'plain http is refused even on github.com');
    }

    public function testOversizedReleaseIsRejected(): void
    {
        $bad = $this->validRelease();
        $bad['size'] = 3 * 1024 * 1024 * 1024;
        $this->warmCache($bad);
        self::assertNull((new UpdateChecker($this->cacheDir))->latest());
    }

    public function testEmptyCacheEntryYieldsNull(): void
    {
        $this->warmCache([]);
        self::assertNull((new UpdateChecker($this->cacheDir))->latest());
    }

    public function testLookalikeHostsAreRejected(): void
    {
        foreach (['https://github.com.evil.tld/x.zip', 'https://notgithub.com/x.zip', 'https://githubusercontent.com.evil.tld/x.zip'] as $url) {
            $bad = $this->validRelease();
            $bad['zip_url'] = $url;
            $this->warmCache($bad);
            self::assertNull((new UpdateChecker($this->cacheDir))->latest(), "lookalike accepted: {$url}");
        }
    }
}
