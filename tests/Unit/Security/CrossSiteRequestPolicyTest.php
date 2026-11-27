<?php

declare(strict_types=1);

namespace YooperGEDView\Tests\Security;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YooperGEDView\Security\CrossSiteRequestPolicy;

#[CoversClass(CrossSiteRequestPolicy::class)]
final class CrossSiteRequestPolicyTest extends TestCase
{
    private const string HOST = 'tree.example.org';

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function requestsFromElsewhere(): array
    {
        return [
            'the browser says another site' => ['cross-site', '', self::HOST],
            'the browser says a sibling site' => ['same-site', '', self::HOST],
            'the browser says another site, in capitals' => ['Cross-Site', '', self::HOST],
            'an unknown value from the browser' => ['something-new', '', self::HOST],
            'the browser header wins over a matching referer' => ['cross-site', 'https://tree.example.org/', self::HOST],
            'a referer on another host' => ['', 'https://mail.example.com/inbox', self::HOST],
            'a referer on a sibling host' => ['', 'https://other.example.org/', self::HOST],
            'a referer whose path names this host' => ['', 'https://evil.example/tree.example.org/', self::HOST],
            'a referer that starts with this host' => ['', 'https://tree.example.org.evil.example/', self::HOST],
            'a referer on another port' => ['', 'http://tree.example.org:8080/', self::HOST],
            'a referer that is not an address' => ['', 'about:blank', self::HOST],
            'a referer that cannot be read' => ['', 'http:///', self::HOST],
        ];
    }

    #[DataProvider('requestsFromElsewhere')]
    public function testARequestStartedElsewhereIsCrossSite(string $secFetchSite, string $referer, string $host): void
    {
        self::assertTrue((new CrossSiteRequestPolicy())->isCrossSite($secFetchSite, $referer, $host));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function requestsFromHere(): array
    {
        return [
            'the browser says this site' => ['same-origin', '', self::HOST],
            'the browser says the visitor typed it or used a bookmark' => ['none', '', self::HOST],
            'the browser header wins over a foreign referer' => ['same-origin', 'https://mail.example.com/', self::HOST],
            'neither header: not a browser, or nothing to go on' => ['', '', self::HOST],
            'a referer on this host' => ['', 'https://tree.example.org/individual.php?pid=I1', self::HOST],
            'a referer on this host in capitals' => ['', 'https://TREE.example.org/', 'tree.EXAMPLE.org'],
            'a referer on this host and port' => ['', 'http://127.0.0.1:8099/index.php', '127.0.0.1:8099'],
        ];
    }

    #[DataProvider('requestsFromHere')]
    public function testARequestStartedHereIsNot(string $secFetchSite, string $referer, string $host): void
    {
        self::assertFalse((new CrossSiteRequestPolicy())->isCrossSite($secFetchSite, $referer, $host));
    }
}
