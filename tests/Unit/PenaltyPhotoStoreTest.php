<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Domain\PenaltyPhotoStore;
use PHPUnit\Framework\TestCase;

/**
 * The upload trust boundary. Every untrusted byte a parent can send is handled
 * in PenaltyPhotoStore, so this suite is where the security properties live:
 * type detection independent of the client, a path with no client input in it,
 * explicit file modes, and a read boundary that treats its own database column
 * as untrusted.
 *
 * Filesystem-flavoured unit test, same shape as FileModeHealTest: a real temp
 * tree, real image bytes, real octal mode assertions.
 */
final class PenaltyPhotoStoreTest extends TestCase
{
    // Real 1x1 images. Verified with getimagesize() in the dev container, which
    // has ext/gd DISABLED — proving the store's detector needs neither gd nor
    // fileinfo, which is the whole reason getimagesize() was chosen (INV-003).
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    private const JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==';
    private const WEBP = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAwA0JaQAA3AA/vuUAAA=';

    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/fc-penalty-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0777, true) && !is_dir($this->root)) {
            self::fail('could not create temp root');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    // ---------------------------------------------------------------- helpers

    private function store(?\Closure $chmod = null): PenaltyPhotoStore
    {
        return new PenaltyPhotoStore($this->root, $chmod);
    }

    /** Build a $_FILES-shaped array around real bytes in a real temp file. */
    private function upload(string $bytes, string $clientName = 'photo.jpg', ?int $error = null, ?int $declaredSize = null): array
    {
        $tmp = $this->root . '/incoming-' . bin2hex(random_bytes(6));
        file_put_contents($tmp, $bytes);

        return [
            'name' => $clientName,
            'type' => 'image/jpeg',      // client-declared; must never be trusted
            'tmp_name' => $tmp,
            'error' => $error ?? UPLOAD_ERR_OK,
            'size' => $declaredSize ?? strlen($bytes),
        ];
    }

    /** Stored paths are relative to the penalties subdirectory, not to the uploads root. */
    private function abs(string $storedPath): string
    {
        return $this->root . '/' . PenaltyPhotoStore::SUBDIR . '/' . $storedPath;
    }

    private function mode(string $absolute): int
    {
        clearstatcache(true, $absolute);

        return fileperms($absolute) & 0777;
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());
            } else {
                @rmdir($item->getPathname());
            }
        }
        @rmdir($dir);
    }

    // ------------------------------------------------------------ test 1: errors

    public function testIniSizeErrorsAreReportedAsTooLargeAndWriteNothing(): void
    {
        foreach ([UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE] as $code) {
            try {
                $this->store()->store($this->upload(base64_decode(self::PNG), error: $code));
                self::fail('expected rejection for upload error ' . $code);
            } catch (\InvalidArgumentException $e) {
                self::assertTrue(
                    PenaltyPhotoStore::isTooLarge($e),
                    'error ' . $code . ' must be classified as too-large, got: ' . $e->getMessage()
                );
            }
        }

        self::assertSame([], glob($this->root . '/penalties/*/*/*') ?: []);
    }

    public function testOtherUploadErrorsAreRejectedButNotAsTooLarge(): void
    {
        try {
            $this->store()->store($this->upload(base64_decode(self::PNG), error: UPLOAD_ERR_PARTIAL));
            self::fail('expected rejection');
        } catch (\InvalidArgumentException $e) {
            self::assertFalse(PenaltyPhotoStore::isTooLarge($e));
        }
    }

    // ------------------------------------------------------------ test 2: size

    public function testRejectsFileOverTheCap(): void
    {
        $big = str_repeat('x', PenaltyPhotoStore::MAX_BYTES + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->store()->store($this->upload($big));
    }

    public function testDeclaredSizeUnderTheCapDoesNotSmuggleAnOversizedFile(): void
    {
        // The client says 10 bytes; the real bytes are over the cap. The real
        // size is what must decide.
        $big = str_repeat('x', PenaltyPhotoStore::MAX_BYTES + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->store()->store($this->upload($big, declaredSize: 10));
    }

    // --------------------------------------------------- tests 3+4: not an image

    public function testRejectsTextBytesNamedAsAnImage(): void
    {
        $upload = $this->upload("<?php echo 'pwned';", 'photo.jpg');

        try {
            $this->store()->store($upload);
            self::fail('expected rejection');
        } catch (\InvalidArgumentException) {
            self::assertFileExists($upload['tmp_name'], 'a rejected upload must not be moved');
            self::assertSame([], glob($this->root . '/penalties/*/*/*') ?: []);
        }
    }

    public function testRejectsSvg(): void
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->expectException(\InvalidArgumentException::class);
        $this->store()->store($this->upload($svg, 'drawing.svg'));
    }

    // --------------------------------------------- decoded-size (bomb) bounds

    /**
     * A byte cap bounds the download, not the bitmap the CHILD's phone has to
     * allocate to display it: image formats compress uniform areas so well
     * that a few hundred KB can declare tens of thousands of pixels per side.
     * The dimensions come free from the same getimagesize() header read.
     */
    public function testRejectsAnImageWhoseDECODEDSizeIsAbsurdEvenThoughItIsTiny(): void
    {
        // A real PNG declaring 20000 x 20000 (400 MP) in well under 1 KB.
        $bomb = $this->pngWithDimensions(20000, 20000);
        self::assertLessThan(2048, strlen($bomb), 'the fixture must be small, or it proves nothing');

        $info = getimagesize('data://image/png;base64,' . base64_encode($bomb));
        self::assertSame([20000, 20000], [$info[0], $info[1]], 'fixture must really declare those dimensions');

        $upload = $this->upload($bomb, 'huge.png');

        try {
            $this->store()->store($upload);
            self::fail('a 400-megapixel image was accepted');
        } catch (\InvalidArgumentException) {
            self::assertFileExists($upload['tmp_name'], 'nothing should have been moved');
            self::assertSame([], glob($this->root . '/penalties/*/*/*') ?: []);
        }
    }

    public function testAcceptsARealisticPhoneCameraResolution(): void
    {
        // 8000 x 6000 = 48 MP is a real phone sensor... and is over the pixel
        // budget, so assert the boundary that MUST pass: a 12 MP shot.
        $stored = $this->store()->store($this->upload($this->pngWithDimensions(4000, 3000), 'cam.png'));

        self::assertStringEndsWith('.png', $stored['path']);
    }

    /** Build a real, valid PNG header declaring $w x $h with a tiny IDAT. */
    private function pngWithDimensions(int $w, int $h): string
    {
        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        $ihdr = pack('NN', $w, $h) . chr(8) . chr(0) . chr(0) . chr(0) . chr(0);   // 8-bit greyscale

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('IDAT', gzcompress(str_repeat("\x00", 16)))
            . $chunk('IEND', '');
    }

    // ------------------------------------------- test 5: type drives everything

    /**
     * The extension and the stored MIME come from the DETECTED type, never from
     * the client's filename or its declared Content-Type. Proven by uploading
     * real PNG bytes under the name "evil.php".
     */
    public function testStoresEachAcceptedTypeUsingTheDetectedTypeNotTheClientName(): void
    {
        $cases = [
            ['bytes' => base64_decode(self::PNG), 'name' => 'evil.php', 'ext' => 'png', 'mime' => 'image/png'],
            ['bytes' => base64_decode(self::JPEG), 'name' => 'shot.png', 'ext' => 'jpg', 'mime' => 'image/jpeg'],
            ['bytes' => base64_decode(self::WEBP), 'name' => 'x.txt', 'ext' => 'webp', 'mime' => 'image/webp'],
        ];

        foreach ($cases as $case) {
            $stored = $this->store()->store($this->upload($case['bytes'], $case['name']));

            self::assertMatchesRegularExpression(
                '#^\d{4}/\d{2}/[a-f0-9]{32}\.' . $case['ext'] . '$#',
                $stored['path'],
                'stored path shape for ' . $case['name']
            );
            self::assertSame($case['mime'], $stored['mime']);
            self::assertSame(strlen($case['bytes']), $stored['bytes']);
            self::assertFileExists($this->abs($stored['path']));
        }
    }

    // ------------------------------------------------------------- test 6: modes

    /**
     * Modes are OUTPUT, not a side effect (LESSONS 2026-08-10). Asserted under
     * umask(077) so the assertion is about our chmod calls and not about the
     * dev box's ambient umask — a gate that cannot observe the failure class is
     * not coverage.
     */
    public function testFileAndDirectoryModesAreExplicitUnderAHostileUmask(): void
    {
        $previous = umask(077);
        try {
            $stored = $this->store()->store($this->upload(base64_decode(self::PNG)));
        } finally {
            umask($previous);
        }

        $abs = $this->abs($stored['path']);
        self::assertSame(0640, $this->mode($abs), 'stored photo must be 0640');
        self::assertSame(0770, $this->mode(dirname($abs)), 'YYYY/MM must be 0770');
        self::assertSame(0770, $this->mode(dirname($abs, 2)), 'YYYY must be 0770');
        self::assertSame(0770, $this->mode(dirname($abs, 3)), 'penalties root must be 0770');
    }

    // -------------------------------------------------- test 7: no client path

    public function testClientFilenameCannotInfluenceTheStoredPath(): void
    {
        $stored = $this->store()->store(
            $this->upload(base64_decode(self::PNG), '../../../../etc/evil.jpg')
        );

        self::assertMatchesRegularExpression('#^\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $stored['path']);
        self::assertFileExists($this->abs($stored['path']));
        self::assertStringNotContainsString('..', $stored['path']);
    }

    // ------------------------------------------------- tests 8+9: read boundary

    public function testResolveRejectsMalformedStoredPaths(): void
    {
        $bad = [
            '../../etc/passwd',
            '/etc/passwd',
            '2026/08/../../../etc/passwd',
            '2026\\08\\aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg',
            '2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.php',
            '2026/08/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA.jpg',   // upper-case hex
            '2026/08/abc.jpg',                                  // wrong length
            '2026/8/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.jpg',     // month not 2 digits
            '',
        ];

        foreach ($bad as $path) {
            try {
                $this->store()->resolve($path);
                self::fail('resolve() accepted a malformed path: ' . $path);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testResolveRejectsAPathThatEscapesTheRootViaSymlink(): void
    {
        $outside = sys_get_temp_dir() . '/fc-outside-' . bin2hex(random_bytes(6));
        mkdir($outside . '/08', 0777, true);
        file_put_contents($outside . '/08/' . str_repeat('a', 32) . '.jpg', base64_decode(self::JPEG));

        mkdir($this->root . '/penalties', 0770, true);
        symlink($outside, $this->root . '/penalties/2026');

        try {
            $this->store()->resolve('2026/08/' . str_repeat('a', 32) . '.jpg');
            self::fail('resolve() followed a symlink out of the uploads root');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        } finally {
            @unlink($this->root . '/penalties/2026');
            $this->removeTree($outside);
        }
    }

    // ----------------------------------------------------------- test 10: delete

    public function testDeleteRemovesAStoredFileAndIsANoOpWhenAlreadyGone(): void
    {
        $stored = $this->store()->store($this->upload(base64_decode(self::PNG)));
        $abs = $this->abs($stored['path']);
        self::assertFileExists($abs);

        $this->store()->delete($stored['path']);
        self::assertFileDoesNotExist($abs);

        $this->store()->delete($stored['path']);   // must not throw
        self::assertTrue(true);
    }

    // ------------------------------------------------- test 10a: name reservation

    /**
     * The rollback deletes BY PATH, so "this path is mine" has to be a fact and
     * not a probability. The store reserves the name with O_EXCL and redraws on
     * collision. Driven with an injected name generator so the retry is
     * asserted rather than waiting for a 2^-128 event.
     */
    public function testAnExistingNameIsNeverOverwritten(): void
    {
        $taken = str_repeat('b', 32);
        $month = gmdate('Y/m');
        mkdir($this->root . '/penalties/' . $month, 0770, true);
        $collide = $this->root . '/penalties/' . $month . '/' . $taken . '.png';
        file_put_contents($collide, 'PRE-EXISTING');

        $names = [$taken, $taken, str_repeat('c', 32)];
        $store = new PenaltyPhotoStore($this->root, null, static function () use (&$names): string {
            return array_shift($names) ?? bin2hex(random_bytes(16));
        });

        $stored = $store->store($this->upload(base64_decode(self::PNG)));

        self::assertSame('PRE-EXISTING', file_get_contents($collide), 'the existing file was overwritten');
        self::assertStringEndsWith(str_repeat('c', 32) . '.png', $stored['path']);
    }

    // ------------------------------------------- test 10b: post-move failure

    /**
     * Once move_uploaded_file() has run the caller holds no path, so store()
     * must clean up after itself. Driven through the $chmod seam: making the
     * parent directory read-only does NOT make chmod() on an existing file
     * fail, so that version of this test would pass while exercising nothing.
     */
    public function testAFailureAfterTheMoveLeavesNoFileBehind(): void
    {
        $store = $this->store(static fn (): bool => false);

        try {
            $store->store($this->upload(base64_decode(self::PNG)));
            self::fail('expected the chmod failure to abort the store');
        } catch (\RuntimeException) {
            $left = glob($this->root . '/penalties/*/*/*') ?: [];
            self::assertSame([], $left, 'a post-move failure must not leave a file behind');
        }
    }

    // ------------------------------------------------ test 10c: mime by extension

    public function testMimeIsDerivedFromTheValidatedExtension(): void
    {
        self::assertSame('image/jpeg', PenaltyPhotoStore::mimeForExtension('jpg'));
        self::assertSame('image/png', PenaltyPhotoStore::mimeForExtension('png'));
        self::assertSame('image/webp', PenaltyPhotoStore::mimeForExtension('webp'));

        // Anything else is a programming error, never a served Content-Type.
        $this->expectException(\InvalidArgumentException::class);
        PenaltyPhotoStore::mimeForExtension('svg');
    }

    public function testExtensionOfReadsTheValidatedPathNotAStoredColumn(): void
    {
        self::assertSame('png', PenaltyPhotoStore::extensionOf('2026/08/' . str_repeat('a', 32) . '.png'));
        self::assertSame('webp', PenaltyPhotoStore::extensionOf('2026/08/' . str_repeat('a', 32) . '.webp'));
    }
}
