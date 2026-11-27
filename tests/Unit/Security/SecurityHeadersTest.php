<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Security\SecurityHeaders;

#[CoversClass(SecurityHeaders::class)]
final class SecurityHeadersTest extends TestCase
{
    public function testThePolicyKeepsEverythingOnThisSite(): void
    {
        $policy = (new SecurityHeaders())->forPage(false)['Content-Security-Policy'];

        self::assertSame(
            "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data:; object-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'",
            $policy,
        );
    }

    public function testNoOtherSiteIsNamedAnywhereInThePolicy(): void
    {
        $policy = (new SecurityHeaders())->forPage(true)['Content-Security-Policy'];

        self::assertDoesNotMatchRegularExpression('~https?:|\*|//~', $policy);
    }

    public function testTheOtherHeaders(): void
    {
        $headers = (new SecurityHeaders())->forPage(false);

        self::assertSame('nosniff', $headers['X-Content-Type-Options']);
        self::assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        self::assertSame('same-origin', $headers['Referrer-Policy']);
    }

    public function testBrowsersAreToldToKeepToHttpsOnlyWhenTheSiteIsReachedThatWay(): void
    {
        $headers = new SecurityHeaders();

        self::assertArrayNotHasKey('Strict-Transport-Security', $headers->forPage(false));
        self::assertSame('max-age=31536000', $headers->forPage(true)['Strict-Transport-Security']);
    }

    public function testNoHeaderValueCanStartANewHeader(): void
    {
        foreach ((new SecurityHeaders())->forPage(true) as $name => $value) {
            self::assertMatchesRegularExpression('/^[A-Za-z-]+$/', $name);
            self::assertDoesNotMatchRegularExpression('/[\r\n]/', $value);
        }
    }
}
