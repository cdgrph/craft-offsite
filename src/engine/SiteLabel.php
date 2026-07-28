<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Resolves the site identifier used in notifications.
 *
 * The identifier is the host of the primary site's base URL (e.g. "example.com"). The human-assigned
 * display name is only a fallback because it does not identify a site: several sites can share one,
 * which leaves the reader of a notification unable to tell which site was backed up.
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

    /**
     * Whether a host can be extracted from the base URL. DiagnoseController uses this to warn about
     * a site whose base URL is @web: it resolves from the request, so on the console there is nothing
     * to resolve and the notification would silently fall back to the display name.
     */
    public static function hasResolvableHost(?string $baseUrl): bool
    {
        return self::resolve($baseUrl, '') !== '';
    }
}
