<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

/**
 * Release check against public GitHub Releases (plan §9): unauthenticated,
 * ETag-aware, cached ≥24h in storage/cache (shared-hosting IPs share the
 * 60 req/h quota — 403/network trouble degrades to "no update info", never
 * to an error page). Asset downloads themselves don't count against the API.
 */
final class UpdateChecker
{
    private const CACHE_TTL = 86400;
    public const DEFAULT_REPO = 'robertobarlocci/familycastel';

    public function __construct(
        private readonly string $cacheDir,
        private readonly string $repo = self::DEFAULT_REPO,
    ) {
    }

    /**
     * @return array{version: string, notes: string, zip_url: string, sha256_url: string, size: int, checked_at: string}|null
     */
    public function latest(bool $force = false): ?array
    {
        $cacheFile = $this->cacheDir . '/update-check.json';
        $cached = is_file($cacheFile) ? json_decode((string) file_get_contents($cacheFile), true) : null;

        if (!$force && is_array($cached)
            && ($cached['fetched_at'] ?? 0) > time() - self::CACHE_TTL) {
            return $this->validateRelease($cached['release'] ?? null);
        }

        $etag = is_array($cached) ? (string) ($cached['etag'] ?? '') : '';
        [$status, $body, $newEtag] = $this->request(
            'https://api.github.com/repos/' . $this->repo . '/releases/latest',
            $etag
        );

        if ($status === 304 && is_array($cached)) {
            $cached['fetched_at'] = time();
            $this->writeCache($cacheFile, $cached);

            return $this->validateRelease($cached['release'] ?? null);
        }

        if ($status !== 200 || $body === '') {
            // Rate limit / offline: keep whatever we knew, mark the attempt.
            if (is_array($cached)) {
                $cached['fetched_at'] = time();
                $this->writeCache($cacheFile, $cached);

                return $this->validateRelease($cached['release'] ?? null);
            }

            return null;
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return null;
        }

        $release = $this->parseRelease($data);
        $this->writeCache($cacheFile, [
            'fetched_at' => time(),
            'etag' => $newEtag,
            'release' => $release,
        ]);

        return $release;
    }

    /** @param array<string, mixed> $data */
    private function parseRelease(array $data): ?array
    {
        $version = ltrim((string) ($data['tag_name'] ?? ''), 'v');
        if ($version === '') {
            return null;
        }

        $zipUrl = '';
        $shaUrl = '';
        $size = 0;
        foreach (($data['assets'] ?? []) as $asset) {
            $name = (string) ($asset['name'] ?? '');
            if (preg_match('/^family-castel-v.+\.zip$/', $name)) {
                $zipUrl = (string) ($asset['browser_download_url'] ?? '');
                $size = (int) ($asset['size'] ?? 0);
            } elseif (preg_match('/^family-castel-v.+\.zip\.sha256$/', $name)) {
                $shaUrl = (string) ($asset['browser_download_url'] ?? '');
            }
        }
        if ($zipUrl === '' || $shaUrl === '') {
            return null;
        }

        return $this->validateRelease([
            'version' => $version,
            'notes' => (string) ($data['body'] ?? ''),
            'zip_url' => $zipUrl,
            'sha256_url' => $shaUrl,
            'size' => $size,
            'checked_at' => gmdate('c'),
        ]);
    }

    /**
     * Strict validation — this data steers the updater. Applied to EVERY
     * returned release, including cached ones (a pre-validation cache file
     * must not bypass the GitHub-host restriction).
     *
     * @return array{version: string, notes: string, zip_url: string, sha256_url: string, size: int, checked_at: string}|null
     */
    private function validateRelease(mixed $release): ?array
    {
        if (!is_array($release)) {
            return null;
        }
        $version = (string) ($release['version'] ?? '');
        $zipUrl = (string) ($release['zip_url'] ?? '');
        $shaUrl = (string) ($release['sha256_url'] ?? '');
        $size = (int) ($release['size'] ?? -1);
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)
            || !$this->isGithubDownloadUrl($zipUrl)
            || !$this->isGithubDownloadUrl($shaUrl)
            || $size < 0 || $size > 2 * 1024 * 1024 * 1024) {
            return null;
        }

        return [
            'version' => $version,
            'notes' => (string) ($release['notes'] ?? ''),
            'zip_url' => $zipUrl,
            'sha256_url' => $shaUrl,
            'size' => $size,
            'checked_at' => (string) ($release['checked_at'] ?? gmdate('c')),
        ];
    }

    private function isGithubDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return ($parts['scheme'] ?? '') === 'https'
            && ($host === 'github.com' || str_ends_with($host, '.github.com')
                || str_ends_with($host, '.githubusercontent.com'));
    }

    /** @return array{0: int, 1: string, 2: string} status, body, etag */
    private function request(string $url, string $etag): array
    {
        $headers = ['Accept: application/vnd.github+json'];
        if ($etag !== '') {
            $headers[] = 'If-None-Match: ' . $etag;
        }

        $ch = curl_init($url);
        $responseEtag = '';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'FamilyCastel-UpdateCheck',
            CURLOPT_HEADERFUNCTION => function ($ch, string $header) use (&$responseEtag): int {
                if (stripos($header, 'etag:') === 0) {
                    $responseEtag = trim(substr($header, 5));
                }

                return strlen($header);
            },
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return [$status, is_string($body) ? $body : '', $responseEtag];
    }

    /** @param array<string, mixed> $data */
    private function writeCache(string $file, array $data): void
    {
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0770, true);
        }
        @file_put_contents($file, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX);
    }
}
