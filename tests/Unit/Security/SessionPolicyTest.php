<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Security;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Security\SessionPolicy;

#[CoversClass(SessionPolicy::class)]
final class SessionPolicyTest extends TestCase
{
    public function testTheCookieIsHiddenFromScriptsAndFromOtherSites(): void
    {
        $options = (new SessionPolicy(7200))->cookieOptions('/tree/', false);

        self::assertSame(
            ['lifetime' => 0, 'path' => '/tree/', 'secure' => false, 'httponly' => true, 'samesite' => 'Lax'],
            $options,
        );
    }

    public function testTheCookieIsSentOverHttpsOnlyWhenTheSiteUsesHttps(): void
    {
        self::assertTrue((new SessionPolicy(7200))->cookieOptions('/', true)['secure']);
    }

    public function testAnEmptyPathMeansTheWholeSite(): void
    {
        self::assertSame('/', (new SessionPolicy(7200))->cookieOptions('', false)['path']);
    }

    public function testASessionIsIdleOnlyAfterTheFullTimeHasPassed(): void
    {
        $policy = new SessionPolicy(600);

        self::assertFalse($policy->isIdle(1000, 1000));
        self::assertFalse($policy->isIdle(1000, 1600));
        self::assertTrue($policy->isIdle(1000, 1601));
    }

    public function testASessionWithNoRecordedActivityIsIdle(): void
    {
        self::assertTrue((new SessionPolicy(600))->isIdle(0, 1_700_000_000));
    }

    public function testTheIdleTimeMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new SessionPolicy(0);
    }
}
