<?php

declare(strict_types=1);

namespace FamilyCastel\Install;

/**
 * Installer/system-status environment checks.
 * Levels: 'ok' (green) · 'warn' (yellow, may proceed) · 'fail' (red, blocking).
 */
final class SystemCheck
{
    public const MIN_PHP = '8.2.0';
    private const REQUIRED_EXTENSIONS = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session', 'openssl', 'curl', 'zip'];

    public function __construct(
        private readonly string $rootDir,
    ) {
    }

    /** @return list<array{id: string, label: string, level: string, detail: string}> */
    public function run(): array
    {
        $checks = [];

        $checks[] = [
            'id' => 'php_version',
            'label' => 'PHP ' . self::MIN_PHP . '+',
            'level' => version_compare(PHP_VERSION, self::MIN_PHP, '>=') ? 'ok' : 'fail',
            'detail' => 'PHP ' . PHP_VERSION,
        ];

        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'id' => 'ext_' . $ext,
                'label' => 'PHP extension: ' . $ext,
                'level' => $loaded ? 'ok' : 'fail',
                'detail' => $loaded ? 'loaded' : 'missing',
            ];
        }

        foreach (['config', 'storage'] as $dir) {
            $checks[] = $this->writableCheck($dir);
        }

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $checks[] = [
            'id' => 'https',
            'label' => 'HTTPS',
            'level' => $https ? 'ok' : 'warn',
            'detail' => $https ? 'active' : 'Not active — strongly recommended for production use',
        ];

        $checks[] = $this->protectionProbe();

        return $checks;
    }

    public function hasBlockingFailure(): bool
    {
        foreach ($this->run() as $check) {
            if ($check['level'] === 'fail') {
                return true;
            }
        }

        return false;
    }

    /**
     * Real touch() probe — is_writable() lies on some filesystems (NFS).
     */
    private function writableCheck(string $dir): array
    {
        $path = $this->rootDir . '/' . $dir;
        $probe = $path . '/.write-probe-' . bin2hex(random_bytes(4));
        $writable = @touch($probe);
        if ($writable) {
            @unlink($probe);
        }

        return [
            'id' => 'writable_' . $dir,
            'label' => $dir . '/ writable',
            'level' => $writable ? 'ok' : 'fail',
            'detail' => $writable ? 'writable (verified with a real write)' : 'not writable — fix permissions',
        ];
    }

    /**
     * HTTP self-probe: is storage/ actually denied over the web?
     * Exposure (HTTP 200) is a BLOCKING failure (plan §8).
     *
     * Design (Codex T3 review): no request header is derived from the client
     * request (no Host trust — plain loopback), and a CONTROL probe against a
     * file that must be publicly readable proves the loopback actually reaches
     * THIS app: control must be 200 and the storage probe 401/403/404.
     * Redirects and every other status are inconclusive → 'warn' with a manual
     * verification instruction, never silently "protected".
     */
    private function protectionProbe(): array
    {
        $label = 'storage/ protected from web access';

        // CONTROL: a random nonce in a public file, fetched by GET and compared
        // BY CONTENT — proves the loopback reaches THIS installation's docroot
        // (a default vhost serving some other css would fail the nonce check).
        $nonce = bin2hex(random_bytes(16));
        $controlName = 'install-probe-' . bin2hex(random_bytes(8)) . '.txt';
        $controlFile = $this->rootDir . '/public-assets/' . $controlName;

        $probeName = 'probe-' . bin2hex(random_bytes(8)) . '.txt';
        $probeFile = $this->rootDir . '/storage/' . $probeName;

        if (@file_put_contents($controlFile, $nonce) === false
            || @file_put_contents($probeFile, 'probe') === false) {
            @unlink($controlFile);
            @unlink($probeFile);

            return ['id' => 'protection', 'label' => $label, 'level' => 'warn', 'detail' => 'Could not create probe files'];
        }

        $control = null;
        $subject = null;
        try {
            // GET, not HEAD — method-specific deny rules must not fool us.
            // SERVER_PORT follows the Host header on Apache (UseCanonicalName
            // Off), which lies behind port-mapped proxies/containers — so try
            // the scheme-default port as a fallback and require the CONTROL
            // to prove whichever port actually reaches this installation.
            foreach ($this->loopbackPorts() as $port) {
                $control = $this->selfRequest('/public-assets/' . $controlName, $port);
                if ($control !== null && $control['status'] === 200 && trim($control['body']) === $nonce) {
                    $subject = $this->selfRequest('/storage/' . $probeName, $port);
                    break;
                }
                $control = null;
            }
        } finally {
            @unlink($controlFile);
            @unlink($probeFile);
        }

        if ($control === null || $subject === null) {
            return [
                'id' => 'protection',
                'label' => $label,
                'level' => 'warn',
                'detail' => 'Self-check inconclusive on this host — verify manually that /storage/ returns 403 in your browser',
            ];
        }

        if ($subject['status'] === 200) {
            return [
                'id' => 'protection',
                'label' => $label,
                'level' => 'fail',
                'detail' => 'HTTP 200 — storage/ is PUBLICLY READABLE. Fix your web server configuration (.htaccess support) before installing.',
            ];
        }

        if (in_array($subject['status'], [401, 403, 404], true)) {
            return ['id' => 'protection', 'label' => $label, 'level' => 'ok', 'detail' => 'HTTP ' . $subject['status'] . ' — protected'];
        }

        return [
            'id' => 'protection',
            'label' => $label,
            'level' => 'warn',
            'detail' => 'HTTP ' . $subject['status'] . ' — inconclusive (redirect/error); verify manually that /storage/ returns 403',
        ];
    }

    /** @return list<int> candidate loopback ports, most likely first */
    private function loopbackPorts(): array
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $ports = [(int) ($_SERVER['SERVER_PORT'] ?? 0), $https ? 443 : 80];

        return array_values(array_unique(array_filter($ports)));
    }

    /** @return array{status: int, body: string}|null */
    private function selfRequest(string $path, int $port): ?array
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = \FamilyCastel\Core\BasePath::get();
        // Plain loopback — deliberately NO Host header derived from the request.
        $url = $scheme . '://127.0.0.1:' . $port . $base . $path;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_MAXFILESIZE => 65536,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno !== 0 || $status === 0 || !is_string($body)) {
            return null;
        }

        return ['status' => $status, 'body' => $body];
    }
}
