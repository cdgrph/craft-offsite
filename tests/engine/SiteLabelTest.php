<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\SiteLabel;
use PHPUnit\Framework\TestCase;

final class SiteLabelTest extends TestCase
{
    public function testResolvesHostFromBaseUrl(): void
    {
        self::assertSame('example.com', SiteLabel::resolve('https://example.com', 'Control Panel'));
    }

    public function testResolvesHostWithoutPortOrPath(): void
    {
        self::assertSame('example.com', SiteLabel::resolve('https://example.com:8080/sub/path', 'fallback'));
    }

    public function testResolvesHostWithTrailingSlash(): void
    {
        self::assertSame('example.com', SiteLabel::resolve('https://example.com/', 'fallback'));
    }

    public function testFallsBackForNullBaseUrl(): void
    {
        self::assertSame('Control Panel', SiteLabel::resolve(null, 'Control Panel'));
    }

    public function testFallsBackForEmptyBaseUrl(): void
    {
        self::assertSame('Control Panel', SiteLabel::resolve('', 'Control Panel'));
    }

    public function testFallsBackForWhitespaceOnlyBaseUrl(): void
    {
        self::assertSame('Control Panel', SiteLabel::resolve('   ', 'Control Panel'));
    }

    public function testFallsBackWhenHostCannotBeExtracted(): void
    {
        self::assertSame('Control Panel', SiteLabel::resolve('not a url', 'Control Panel'));
    }

    public function testFallsBackForRootBaseUrl(): void
    {
        // Craft sets @web to '/' in console requests, so a baseUrl of '@web' resolves to '/' under cron — the most common real-world fallback input.
        self::assertSame('Control Panel', SiteLabel::resolve('/', 'Control Panel'));
    }

    public function testHasResolvableHostForAbsoluteUrl(): void
    {
        self::assertTrue(SiteLabel::hasResolvableHost('https://example.com'));
    }

    public function testHasNoResolvableHostForConsoleWebAlias(): void
    {
        // '@web' resolves to '/' in console requests, so cron-context notifications cannot extract a host.
        self::assertFalse(SiteLabel::hasResolvableHost('/'));
    }

    public function testHasNoResolvableHostForNull(): void
    {
        self::assertFalse(SiteLabel::hasResolvableHost(null));
    }

    public function testFallsBackWhenParseUrlReturnsFalse(): void
    {
        // parse_url() returns false (not null) here, pinning the is_string() guard.
        self::assertSame('Control Panel', SiteLabel::resolve('http:///', 'Control Panel'));
    }
}
