<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class ErrorSummarizer
{
    private const FALLBACK = 'Unexpected backup error';

    private const PATTERNS = [
        '/^Lock unavailable:/' => 'Another backup is already running',
        '/NoSuchBucket/i' => 'S3 bucket not found',
        '/InvalidAccessKeyId/i' => 'S3 access key invalid',
        '/SignatureDoesNotMatch/i' => 'S3 signature mismatch (check secret key or region)',
        '/AccessDenied/i' => 'S3 access denied',
        '/ExpiredToken/i' => 'S3 credentials expired',
        '/RequestTimeTooSkewed/i' => 'Server clock is out of sync',
        '/ConnectException|Could not resolve host/i' => 'Network error (S3 unreachable)',
        '/cURL error 60/i' => 'TLS certificate error',
        '/cURL error/i' => 'Network error',
        '/SlowDown|ServiceUnavailable/i' => 'S3 temporarily unavailable',
    ];

    public static function summarize(string $reason): string
    {
        foreach (self::PATTERNS as $pattern => $summary) {
            if (preg_match($pattern, $reason)) {
                return $summary;
            }
        }
        if (preg_match('/Error executing "(\w+)"/i', $reason, $m)) {
            return 'S3 ' . $m[1] . ' failed';
        }
        return self::FALLBACK;
    }
}
