<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Resolves the site identifier used in notifications.
 *
 * The identifier is the host of the primary site's base URL (e.g. "example.com"). The human-assigned
 * display name is only a fallback because it does not identify a site (Issue #42).
 */
final class SiteLabel
{
    public static function resolve(?string $baseUrl, string $fallbackName): string
    {
        $baseUrl = trim($baseUrl ?? '');
        if ($baseUrl === '') {
            return $fallbackName;
        }

        $host = parse_url($baseUrl, PHP_URL_HOST);
        return is_string($host) && $host !== '' ? $host : $fallbackName;
    }

    /** Whether a host can be extracted from the base URL — DiagnoseController uses this to surface the @web/console gap (Issue #43). */
    public static function hasResolvableHost(?string $baseUrl): bool
    {
        return self::resolve($baseUrl, '') !== '';
    }
}
