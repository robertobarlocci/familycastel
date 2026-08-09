<?php

declare(strict_types=1);

namespace FamilyCastel\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EscapersTest extends TestCase
{
    public function testEEscapesHtmlTextContext(): void
    {
        self::assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            e('<script>alert(1)</script>')
        );
        self::assertSame('Tom &amp; Jerry', e('Tom & Jerry'));
        self::assertSame('&quot;quoted&quot;', e('"quoted"'));
        self::assertSame('&#039;single&#039;', e("'single'"));
    }

    public function testEHandlesNullAndNumbers(): void
    {
        self::assertSame('', e(null));
        self::assertSame('42', e(42));
        self::assertSame('4.5', e(4.5));
    }

    public function testEattrEscapesAttributeContext(): void
    {
        self::assertSame(
            '&quot; onmouseover=&quot;alert(1)',
            eattr('" onmouseover="alert(1)')
        );
        self::assertSame('&#039;&gt;&lt;img&gt;', eattr("'><img>"));
    }

    public function testEjsProducesSafeJsonForScriptContext(): void
    {
        $out = ejs(['name' => '</script><script>alert(1)</script>']);
        self::assertStringNotContainsString('</script>', $out);
        self::assertStringNotContainsString('<script>', $out);
        $decoded = json_decode($out, true);
        self::assertSame('</script><script>alert(1)</script>', $decoded['name']);
    }

    public function testEjsOutputContainsNoRawHtmlSpecialChars(): void
    {
        $out = ejs("Rock & Roll 'n <b> \"x\"");
        self::assertStringNotContainsString('<', $out);
        self::assertStringNotContainsString('>', $out);
        self::assertStringNotContainsString('&', $out);
        self::assertSame("Rock & Roll 'n <b> \"x\"", json_decode($out));
    }

    public function testEurlEncodesForUrlContext(): void
    {
        self::assertSame('a%20b%2Fc%3Fd%3De', eurl('a b/c?d=e'));
        self::assertSame('%C3%A4%C3%B6%C3%BC', eurl('äöü'));
    }
}
