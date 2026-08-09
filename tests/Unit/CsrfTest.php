<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use FamilyCastel\Core\Csrf;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testTokenIsGeneratedOncePerSession(): void
    {
        $a = Csrf::token();
        $b = Csrf::token();
        self::assertSame($a, $b);
        self::assertSame(64, strlen($a)); // 32 random bytes hex
    }

    public function testValidateAcceptsCurrentToken(): void
    {
        self::assertTrue(Csrf::validate(Csrf::token()));
    }

    public function testValidateRejectsWrongEmptyAndNull(): void
    {
        Csrf::token();
        self::assertFalse(Csrf::validate('wrong'));
        self::assertFalse(Csrf::validate(''));
        self::assertFalse(Csrf::validate(null));
    }

    public function testRotateInvalidatesOldToken(): void
    {
        $old = Csrf::token();
        Csrf::rotate();
        self::assertFalse(Csrf::validate($old));
        self::assertTrue(Csrf::validate(Csrf::token()));
    }
}
