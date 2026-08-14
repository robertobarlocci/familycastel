<?php

declare(strict_types=1);

namespace FamilyCastel\Domain;

/**
 * The trust boundary for penalty photos.
 *
 * Every untrusted byte a parent can send is handled here, and nothing
 * downstream re-derives anything from client input:
 *
 * - the stored NAME contains no client input at all (32 random hex), so there
 *   is no traversal surface to defend;
 * - the extension and the MIME come from the DETECTED image type, never from
 *   $_FILES['name'] or $_FILES['type'], both of which the client controls;
 * - the detector is getimagesize(), deliberately NOT finfo and NOT gd: neither
 *   ext/fileinfo nor ext/gd is in SystemCheck::REQUIRED_EXTENSIONS, so relying
 *   on them would break the product on hosting we promise to run on (INV-003).
 *   getimagesize() lives in ext/standard and is always present, and it returns
 *   false for text, for SVG, and for anything that is not a raster format it
 *   knows — which is exactly the check needed here.
 *
 * On read, resolve() treats this application's OWN database column as
 * untrusted: a restored backup or a hand-edited row must never become a
 * file-read primitive.
 *
 * Files land under storage/uploads/penalties/, which Apache refuses to serve
 * (storage/.htaccess, module-independent) and which PHP streams only behind an
 * authorisation check. They are therefore never executed and never sniffed.
 */
final class PenaltyPhotoStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * Decoded-size bounds. A byte cap bounds the download, not the bitmap a
     * phone has to allocate to display it — 40 MP at 4 bytes per pixel is
     * ~160 MB of RAM. Generous enough for any real phone camera (a 48 MP
     * sensor saves ~8000 x 6000 = 48 MP, hence the 12000 side allowance for
     * panoramas while the pixel budget still refuses the extreme cases).
     */
    public const MAX_DIMENSION = 12000;
    public const MAX_PIXELS = 40_000_000;

    /** Detected image type => [extension, mime]. SVG is absent on purpose. */
    private const ACCEPTED = [
        IMAGETYPE_JPEG => ['jpg', 'image/jpeg'],
        IMAGETYPE_PNG => ['png', 'image/png'],
        IMAGETYPE_WEBP => ['webp', 'image/webp'],
    ];

    /** The only shape a stored path may ever have. */
    public const PATH_PATTERN = '#^\d{4}/\d{2}/[a-f0-9]{32}\.(jpg|png|webp)$#';

    /** Subdirectory of the uploads root that this feature owns. */
    public const SUBDIR = 'penalties';

    private const DIR_MODE = 0770;
    private const FILE_MODE = 0640;
    private const NAME_ATTEMPTS = 5;

    /** Marker appended to the message of a too-large rejection (see isTooLarge()). */
    private const TOO_LARGE_TAG = '[too-large]';

    /**
     * @param string        $uploadsDir absolute path of storage/uploads
     * @param \Closure|null $chmod      test seam; production passes null and chmod() is used
     * @param \Closure|null $nameGen    test seam; production passes null and 16 random bytes are used
     */
    public function __construct(
        private readonly string $uploadsDir,
        private readonly ?\Closure $chmod = null,
        private readonly ?\Closure $nameGen = null,
    ) {
    }

    /**
     * Validate and store one uploaded photo.
     *
     * This method OWNS every failure after the move: once move_uploaded_file()
     * has run the caller holds no path yet, so it could not compensate. Any
     * post-move failure unlinks the file before throwing.
     *
     * @param array<string, mixed> $file one entry of $_FILES
     *
     * @return array{path: string, mime: string, bytes: int} path is relative to the uploads root
     */
    public function store(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw self::reject($error);
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new \InvalidArgumentException('The photo could not be read.');
        }

        // is_uploaded_file() refuses a caller-supplied local path. It is only
        // meaningful for a real upload, so tests (which fabricate the temp
        // file) are exempted via FC_TESTING rather than by dropping the check.
        if (!defined('FC_TESTING') && !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('The photo could not be read.');
        }

        // The client-declared size is not trusted; the real one is checked too.
        $realBytes = (int) filesize($tmp);
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES || $realBytes > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The photo is too large. ' . self::TOO_LARGE_TAG);
        }
        if ($realBytes <= 0) {
            throw new \InvalidArgumentException('The photo is empty.');
        }

        $info = @getimagesize($tmp);
        $type = is_array($info) ? ($info[2] ?? null) : null;
        if ($type === null || !array_key_exists($type, self::ACCEPTED)) {
            throw new \InvalidArgumentException('Only JPEG, PNG and WEBP photos can be attached.');
        }
        [$extension, $mime] = self::ACCEPTED[$type];

        // A byte cap does NOT bound the decoded size: image formats compress
        // uniform areas extremely well, so a few hundred KB can declare 30000 x
        // 30000 pixels. We never decode it — but the child's browser does, and
        // on a phone that is an out-of-memory crash on the journal page. The
        // dimensions come from the same getimagesize() header read, so this
        // costs nothing.
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 1 || $height < 1
            || $width > self::MAX_DIMENSION
            || $height > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS
        ) {
            throw new \InvalidArgumentException('The photo\'s dimensions are too large.');
        }

        $month = gmdate('Y/m');
        $dir = $this->penaltiesDir() . '/' . $month;
        $this->makeDirectory($dir);

        [$absolute, $name] = $this->reserveName($dir, $extension);

        if (!$this->moveInto($tmp, $absolute)) {
            @unlink($absolute);
            throw new \RuntimeException('The photo could not be saved.');
        }

        // From here on the file exists: every failure must clean up after itself.
        if (!$this->applyChmod($absolute, self::FILE_MODE)) {
            @unlink($absolute);
            throw new \RuntimeException('The photo could not be saved with safe permissions.');
        }

        $stored = (int) filesize($absolute);
        if ($stored > self::MAX_BYTES) {
            @unlink($absolute);
            throw new \InvalidArgumentException('The photo is too large. ' . self::TOO_LARGE_TAG);
        }

        return [
            'path' => $month . '/' . $name . '.' . $extension,
            'mime' => $mime,
            'bytes' => $stored,
        ];
    }

    /**
     * Turn a stored path into an absolute one, treating the column as
     * untrusted. Two independent gates: the shape must match PATH_PATTERN, and
     * the realpath must be contained by the uploads root — which is what stops
     * a symlink planted inside the tree from escaping it.
     */
    public function resolve(string $path): string
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            throw new \InvalidArgumentException('Malformed photo path.');
        }

        $base = realpath($this->penaltiesDir());
        $absolute = realpath($this->penaltiesDir() . '/' . $path);

        if ($base === false || $absolute === false) {
            throw new \InvalidArgumentException('Photo file is missing.');
        }
        if (!str_starts_with($absolute, $base . DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Photo path escapes the uploads directory.');
        }
        if (!is_file($absolute)) {
            throw new \InvalidArgumentException('Photo file is missing.');
        }

        return $absolute;
    }

    /**
     * Remove a stored file. Used only to compensate an upload whose transaction
     * never committed, and by the orphan sweep — never to delete history
     * (INV-001). Best effort: a path that is already gone is not an error.
     */
    public function delete(string $path): void
    {
        if (preg_match(self::PATH_PATTERN, $path) !== 1) {
            return;
        }
        $absolute = $this->penaltiesDir() . '/' . $path;
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    public function penaltiesDir(): string
    {
        return rtrim($this->uploadsDir, '/') . '/' . self::SUBDIR;
    }

    /**
     * The Content-Type a photo is served with, derived from the VALIDATED path
     * extension rather than from the stored mime column: the extension has been
     * through PATH_PATTERN, the column has not. A restored or hand-edited row
     * must never be able to pair image bytes with text/html.
     */
    public static function mimeForExtension(string $extension): string
    {
        foreach (self::ACCEPTED as [$ext, $mime]) {
            if ($ext === $extension) {
                return $mime;
            }
        }

        throw new \InvalidArgumentException('Unsupported photo extension: ' . $extension);
    }

    public static function extensionOf(string $path): string
    {
        if (preg_match(self::PATH_PATTERN, $path, $m) !== 1) {
            throw new \InvalidArgumentException('Malformed photo path.');
        }

        return $m[1];
    }

    /**
     * Whether a rejection was "the photo is too large", so the controller can
     * tell the parent which knob they hit instead of a generic failure. A tag
     * rather than a subclass: the store throws the framework-standard
     * InvalidArgumentException everywhere, like every other service here.
     */
    public static function isTooLarge(\Throwable $e): bool
    {
        return str_contains($e->getMessage(), self::TOO_LARGE_TAG);
    }

    // ------------------------------------------------------------------ internals

    private static function reject(int $error): \InvalidArgumentException
    {
        // The host's real upload_max_filesize is unknown to us, so the two size
        // errors get their own message: the parent needs to know it was the
        // size, not a generic failure.
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            return new \InvalidArgumentException('The photo is too large. ' . self::TOO_LARGE_TAG);
        }

        return new \InvalidArgumentException('The photo could not be uploaded.');
    }

    /**
     * Create a directory tree with EXPLICIT modes. mkdir()'s mode argument is
     * masked by the process umask, so it is a request; chmod() is not masked,
     * so it is the instruction (LESSONS 2026-08-10: a file mode is output).
     * Each level created is chmod-ed and checked.
     */
    private function makeDirectory(string $dir): void
    {
        $root = rtrim($this->uploadsDir, '/');
        if (!is_dir($root)) {
            throw new \RuntimeException('Uploads directory is missing: ' . $root);
        }

        $relative = trim(substr($dir, strlen($root)), '/');
        $current = $root;

        foreach (explode('/', $relative) as $segment) {
            $current .= '/' . $segment;
            if (!is_dir($current)) {
                // The trailing is_dir() closes the race where a sibling request
                // created it between the check and the mkdir.
                if (!@mkdir($current, self::DIR_MODE) && !is_dir($current)) {
                    throw new \RuntimeException('Could not create upload directory: ' . $current);
                }
            }
            if (!$this->applyChmod($current, self::DIR_MODE)) {
                throw new \RuntimeException('Could not set permissions on: ' . $current);
            }
        }
    }

    /**
     * Reserve a name with O_EXCL before filling it, so a collision can never
     * overwrite an existing photo. This is what makes "this path is mine" a
     * fact rather than a probability — which the compensating delete-by-path
     * depends on.
     *
     * @return array{0: string, 1: string} absolute path, bare name
     */
    private function reserveName(string $dir, string $extension): array
    {
        for ($attempt = 0; $attempt < self::NAME_ATTEMPTS; $attempt++) {
            $name = $this->nameGen !== null ? ($this->nameGen)() : bin2hex(random_bytes(16));
            $absolute = $dir . '/' . $name . '.' . $extension;

            $handle = @fopen($absolute, 'xb');   // O_CREAT | O_EXCL
            if ($handle !== false) {
                fclose($handle);

                return [$absolute, $name];
            }
        }

        throw new \RuntimeException('Could not reserve a filename for the photo.');
    }

    private function moveInto(string $tmp, string $absolute): bool
    {
        // move_uploaded_file() only accepts a genuine upload; the test seam
        // falls back to rename() for fabricated temp files.
        if (!defined('FC_TESTING')) {
            return move_uploaded_file($tmp, $absolute);
        }

        return rename($tmp, $absolute);
    }

    private function applyChmod(string $path, int $mode): bool
    {
        if ($this->chmod !== null) {
            return (bool) ($this->chmod)($path, $mode);
        }

        return @chmod($path, $mode);
    }
}
