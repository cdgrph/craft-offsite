<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use cdgrph\offsite\engine\DestinationException;
use cdgrph\offsite\engine\S3Destination;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class S3DestinationChecksumFallbackTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $tempFile) {
            @unlink($tempFile);
        }
    }

    public function testPutFallsBackWhenChecksumRejected(): void
    {
        $contents = 'checksum fallback upload';
        $localPath = $this->createTempFile($contents);
        $observations = [];
        $handler = new MockHandler();
        $handler->append(
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                throw self::s3Exception(
                    'x-amz-sdk-checksum-algorithm is not supported',
                    $cmd,
                    'InvalidRequest',
                    400,
                );
            },
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                return new Result();
            },
            self::headResult($observations, strlen($contents)),
            self::readBackResult($observations, $contents),
        );

        $destination = $this->createDestination($handler);
        $destination->put('backup.zip', $localPath, (string)hash_file('sha256', $localPath));

        self::assertSame(['PutObject', 'PutObject', 'HeadObject', 'GetObject'], array_column($observations, 'command'));
        self::assertSame(['SHA256', null], array_column(array_slice($observations, 0, 2), 'checksumAlgorithm'));
        self::assertChecksumFreeRequest($observations[1]);
        self::assertNull($observations[2]['checksumMode']);
        self::assertCount(0, $handler);
    }

    public function testChecksumRejectionIsSticky(): void
    {
        $firstContents = 'first checksum fallback upload';
        $secondContents = 'second checksum-free upload';
        $firstPath = $this->createTempFile($firstContents);
        $secondPath = $this->createTempFile($secondContents);
        $observations = [];
        $handler = new MockHandler();
        $handler->append(
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                throw self::s3Exception(
                    'x-amz-sdk-checksum-algorithm is not supported',
                    $cmd,
                    'InvalidRequest',
                    400,
                );
            },
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                return new Result();
            },
            self::headResult($observations, strlen($firstContents)),
            self::readBackResult($observations, $firstContents),
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                return new Result();
            },
            self::headResult($observations, strlen($secondContents)),
            self::readBackResult($observations, $secondContents),
        );

        $destination = $this->createDestination($handler);
        $destination->put('first.zip', $firstPath, (string)hash_file('sha256', $firstPath));
        $destination->put('second.zip', $secondPath, (string)hash_file('sha256', $secondPath));

        self::assertSame(
            ['PutObject', 'PutObject', 'HeadObject', 'GetObject', 'PutObject', 'HeadObject', 'GetObject'],
            array_column($observations, 'command'),
        );
        $putObservations = self::observationsForCommand($observations, 'PutObject');
        self::assertSame(['SHA256', null, null], array_column($putObservations, 'checksumAlgorithm'));
        self::assertChecksumFreeRequest($putObservations[1]);
        self::assertChecksumFreeRequest($putObservations[2]);
        self::assertSame([null, null], array_column(self::observationsForCommand($observations, 'HeadObject'), 'checksumMode'));
        self::assertCount(0, $handler);
    }

    public function testUnrelatedErrorDoesNotTriggerFallback(): void
    {
        $localPath = $this->createTempFile('access denied upload');
        $observations = [];
        $handler = new MockHandler();
        $handler->append(function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
            $observations[] = self::observe($cmd, $req);
            throw self::s3Exception('Access Denied', $cmd, 'AccessDenied', 403);
        });
        $destination = $this->createDestination($handler, maxAttempts: 1);
        $caught = null;

        try {
            $destination->put('denied.zip', $localPath, (string)hash_file('sha256', $localPath));
        } catch (DestinationException $e) {
            $caught = $e;
        }

        self::assertInstanceOf(DestinationException::class, $caught);
        self::assertSame(['PutObject'], array_column($observations, 'command'));
        self::assertSame(['SHA256'], array_column($observations, 'checksumAlgorithm'));
        self::assertCount(0, $handler);
    }

    public function testHeadChecksumRejectionFallsBackToReadBack(): void
    {
        $contents = 'head checksum fallback';
        $localPath = $this->createTempFile($contents);
        $observations = [];
        $handler = new MockHandler();
        $handler->append(
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                return new Result();
            },
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                throw self::s3Exception(
                    'A header you provided implies functionality that is not implemented',
                    $cmd,
                    'NotImplemented',
                    501,
                );
            },
            self::headResult($observations, strlen($contents)),
            self::readBackResult($observations, $contents),
        );

        $destination = $this->createDestination($handler);
        $destination->put('backup.zip', $localPath, (string)hash_file('sha256', $localPath));

        self::assertSame(['PutObject', 'HeadObject', 'HeadObject', 'GetObject'], array_column($observations, 'command'));
        self::assertSame('SHA256', $observations[0]['checksumAlgorithm']);
        self::assertSame(['ENABLED', null], array_column(self::observationsForCommand($observations, 'HeadObject'), 'checksumMode'));
        self::assertChecksumFreeRequest($observations[2]);
        self::assertCount(0, $handler);
    }

    public function testMultipartPutFallsBackWhenChecksumRejected(): void
    {
        $contents = str_repeat('m', 2048);
        $localPath = $this->createTempFile($contents);
        $observations = [];
        $handler = new MockHandler();
        $handler->append(
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                throw self::s3Exception(
                    'ChecksumAlgorithm is not supported',
                    $cmd,
                    'InvalidRequest',
                    400,
                );
            },
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                return new Result(['UploadId' => 'test-upload-id']);
            },
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                return new Result(['ETag' => '"etag-1"']);
            },
            function (CommandInterface $cmd, RequestInterface $req) use (&$observations): Result {
                $observations[] = self::observe($cmd, $req);
                return new Result();
            },
            self::headResult($observations, strlen($contents)),
            self::readBackResult($observations, $contents),
        );

        $destination = $this->createDestination($handler);
        $destination->put('multipart.zip', $localPath, (string)hash_file('sha256', $localPath));

        self::assertSame(
            ['CreateMultipartUpload', 'CreateMultipartUpload', 'UploadPart', 'CompleteMultipartUpload', 'HeadObject', 'GetObject'],
            array_column($observations, 'command'),
        );
        self::assertSame(['SHA256', null], array_column(array_slice($observations, 0, 2), 'checksumAlgorithm'));
        self::assertNull($observations[2]['checksumAlgorithm']);
        self::assertChecksumFreeRequest($observations[1]);
        self::assertChecksumFreeRequest($observations[2]);
        self::assertNull($observations[4]['checksumMode']);
        self::assertCount(0, $handler);
    }

    private function createDestination(MockHandler $handler, int $maxAttempts = 3): S3Destination
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $handler,
            'request_checksum_calculation' => 'when_required',
        ]);

        return new S3Destination(
            $client,
            'test-bucket',
            multipartThresholdBytes: 1024,
            maxAttempts: $maxAttempts,
        );
    }

    private function createTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'offsite-s3-fallback');
        if ($path === false) {
            throw new \RuntimeException('Failed to create a temporary file.');
        }
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;
        return $path;
    }

    /**
     * @param list<array<string, string|null>> $observations
     * @return callable(CommandInterface, RequestInterface): Result
     */
    private static function headResult(array &$observations, int $contentLength): callable
    {
        return function (CommandInterface $cmd, RequestInterface $req) use (&$observations, $contentLength): Result {
            $observations[] = self::observe($cmd, $req);
            return new Result(['ContentLength' => $contentLength]);
        };
    }

    /**
     * @param list<array<string, string|null>> $observations
     * @return callable(CommandInterface, RequestInterface): Result
     */
    private static function readBackResult(array &$observations, string $contents): callable
    {
        return function (CommandInterface $cmd, RequestInterface $req) use (&$observations, $contents): Result {
            $observations[] = self::observe($cmd, $req);
            file_put_contents((string)$cmd['@http']['sink'], $contents);
            return new Result();
        };
    }

    /** @return array<string, string|null> */
    private static function observe(CommandInterface $cmd, RequestInterface $req): array
    {
        return [
            'command' => $cmd->getName(),
            'checksumAlgorithm' => $cmd['ChecksumAlgorithm'] ?? null,
            'checksumMode' => $cmd['ChecksumMode'] ?? null,
            'sdkChecksumAlgorithm' => $req->getHeaderLine('x-amz-sdk-checksum-algorithm'),
            'sha256Checksum' => $req->getHeaderLine('x-amz-checksum-sha256'),
            'crc32Checksum' => $req->getHeaderLine('x-amz-checksum-crc32'),
        ];
    }

    /**
     * @param list<array<string, string|null>> $observations
     * @return list<array<string, string|null>>
     */
    private static function observationsForCommand(array $observations, string $command): array
    {
        return array_values(array_filter(
            $observations,
            static fn(array $observation): bool => $observation['command'] === $command,
        ));
    }

    /** @param array<string, string|null> $observation */
    private static function assertChecksumFreeRequest(array $observation): void
    {
        self::assertSame('', $observation['sdkChecksumAlgorithm']);
        self::assertSame('', $observation['sha256Checksum']);
        self::assertSame('', $observation['crc32Checksum']);
    }

    private static function s3Exception(
        string $message,
        CommandInterface $cmd,
        string $code,
        int $status,
    ): S3Exception {
        return new S3Exception($message, $cmd, [
            'code' => $code,
            'message' => $message,
            'response' => new Response($status),
        ]);
    }
}
