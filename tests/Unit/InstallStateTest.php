<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Install\InstallState;
use PHPUnit\Framework\TestCase;

final class InstallStateTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/fc-install-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        @rmdir($this->dir);
    }

    public function testFreshStateIsEmpty(): void
    {
        $state = new InstallState($this->dir);
        self::assertNull($state->get('db'));
        self::assertSame([], $state->completedSteps());
    }

    public function testSetPersistsAcrossInstances(): void
    {
        $state = new InstallState($this->dir);
        $state->set('family', ['name' => 'Barlocci', 'locale' => 'de']);
        $state->markStep('family');

        $reloaded = new InstallState($this->dir);
        self::assertSame(['name' => 'Barlocci', 'locale' => 'de'], $reloaded->get('family'));
        self::assertContains('family', $reloaded->completedSteps());
    }

    public function testExpiredStateIsDiscarded(): void
    {
        $state = new InstallState($this->dir, ttlSeconds: 1);
        $state->set('db', ['host' => 'x']);

        // Simulate age by rewriting the started_at timestamp.
        $file = $this->dir . '/install-state.json';
        $data = json_decode((string) file_get_contents($file), true);
        $data['started_at'] = time() - 3600;
        file_put_contents($file, json_encode($data));

        $reloaded = new InstallState($this->dir, ttlSeconds: 1);
        self::assertNull($reloaded->get('db'), 'expired install state must be discarded');
    }

    public function testDestroyRemovesStateFile(): void
    {
        $state = new InstallState($this->dir);
        $state->set('db', ['host' => 'x']);
        $state->destroy();

        self::assertFileDoesNotExist($this->dir . '/install-state.json');
    }

    public function testTokenRegeneratedAfterTenFailedAttempts(): void
    {
        $state = new InstallState($this->dir);
        $original = $state->ensureSetupToken();

        for ($i = 0; $i < 10; $i++) {
            self::assertFalse($state->verifySetupToken('WRONGCOD'));
        }

        $regenerated = trim((string) file_get_contents($this->dir . '/setup-token.txt'));
        self::assertNotSame($original, $regenerated, 'token must rotate after 10 failures');
        self::assertFalse($state->verifySetupToken($original), 'old token must be worthless');
        self::assertTrue($state->verifySetupToken($regenerated));
    }

    public function testSessionBindingRoundTrip(): void
    {
        $state = new InstallState($this->dir);
        self::assertFalse($state->sessionMatches(null));
        self::assertFalse($state->sessionMatches('anything'));

        $binding = $state->bindSession();
        self::assertSame(64, strlen($binding));
        self::assertTrue($state->sessionMatches($binding));
        self::assertFalse($state->sessionMatches('impostor'));

        $reloaded = new InstallState($this->dir);
        self::assertTrue($reloaded->sessionMatches($binding), 'binding must persist');
    }

    public function testStateFileHasRestrictivePermissions(): void
    {
        $state = new InstallState($this->dir);
        $state->set('db', ['password' => 'secret']);

        $perms = fileperms($this->dir . '/install-state.json') & 0777;
        self::assertSame(0600, $perms, 'credentials must never be group/world readable');
    }

    public function testSetupTokenRoundTrip(): void
    {
        $state = new InstallState($this->dir);
        $code = $state->ensureSetupToken();

        self::assertSame(8, strlen($code));
        self::assertFileExists($this->dir . '/setup-token.txt');
        // Same code on re-entry (no regeneration churn)
        self::assertSame($code, $state->ensureSetupToken());
        self::assertTrue($state->verifySetupToken($code));
        self::assertFalse($state->verifySetupToken('AAAAAAAA'));
        self::assertFalse($state->verifySetupToken(''));
    }
}
