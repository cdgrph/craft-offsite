<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\ErrorSummarizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorSummarizerTest extends TestCase
{
    #[DataProvider('knownPatterns')]
    public function testKnownPatterns(string $input, string $expected): void
    {
        self::assertSame($expected, ErrorSummarizer::summarize($input));
    }

    /** @return iterable<string, array{string, string}> */
    public static function knownPatterns(): iterable
    {
        yield 'lock' => [
            'Lock unavailable: already held by pid 1234',
            'Another backup is already running',
        ];
        yield 'NoSuchBucket' => [
            'Error executing "PutObject" on "https://bucket.s3.amazonaws.com/key"; AWS HTTP error: NoSuchBucket (client): The specified bucket does not exist',
            'S3 bucket not found',
        ];
        yield 'InvalidAccessKeyId' => [
            'Error executing "PutObject"; InvalidAccessKeyId (client): The access key Id you provided does not exist',
            'S3 access key invalid',
        ];
        yield 'SignatureDoesNotMatch' => [
            'Error executing "PutObject"; SignatureDoesNotMatch (client): The request signature we calculated does not match',
            'S3 signature mismatch (check secret key or region)',
        ];
        yield 'AccessDenied' => [
            'Error executing "PutObject"; AccessDenied (client): Access Denied',
            'S3 access denied',
        ];
        yield 'ExpiredToken' => [
            'Error executing "PutObject"; ExpiredToken (client): The provided token has expired',
            'S3 credentials expired',
        ];
        yield 'RequestTimeTooSkewed' => [
            'RequestTimeTooSkewed (client): The difference between the request time and the current time is too large',
            'Server clock is out of sync',
        ];
        yield 'DNS resolution' => [
            'Could not resolve host: nonexistent.s3.amazonaws.com',
            'Network error (S3 unreachable)',
        ];
        yield 'ConnectException' => [
            'ConnectException: cURL error 7: Failed to connect',
            'Network error (S3 unreachable)',
        ];
        yield 'cURL TLS' => [
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
            'TLS certificate error',
        ];
        yield 'cURL generic' => [
            'cURL error 28: Operation timed out after 30000 milliseconds',
            'Network error',
        ];
        yield 'SlowDown' => [
            'SlowDown (client): Please reduce your request rate',
            'S3 temporarily unavailable',
        ];
        yield 'generic S3 operation' => [
            'Error executing "GetObject" on "https://bucket.s3.amazonaws.com/key"; something went wrong',
            'S3 GetObject failed',
        ];
    }

    public function testUnknownErrorReturnsSafeFallback(): void
    {
        $raw = 'https://s3.example.com/private/path timed out with internal details';
        $result = ErrorSummarizer::summarize($raw);
        self::assertSame('Unexpected backup error', $result);
        self::assertStringNotContainsString('s3.example.com', $result);
    }

    public function testUnknownLongErrorDoesNotLeakContent(): void
    {
        $raw = str_repeat('x', 200);
        self::assertSame('Unexpected backup error', ErrorSummarizer::summarize($raw));
    }
}
