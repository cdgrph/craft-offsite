<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

use Aws\Exception\AwsException;
use Aws\S3\MultipartUploader;
use Aws\S3\S3Client;

/**
 * S3-compatible destination. Integrity contract (spec §4, Codex r3):
 * multipart ETag is used only as an existence/identity signal, never as
 * an integrity proof. We request provider-native SHA-256 checksums; when
 * the provider does not return one, we do a full read-back SHA-256 BEFORE
 * the caller publishes the catalog entry.
 */
final class S3Destination implements Destination
{
    // Sticky one-way latch (true -> false) per run instance; all later objects use read-back verification.
    private bool $checksumApiSupported = true;

    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly string $keyPrefix = '',
        private readonly int $multipartThresholdBytes = 104857600, // 100MB
        private readonly int $maxAttempts = 3,
        private readonly ?string $verifyTmpDir = null,
    ) {
    }

    public function put(string $key, string $localPath, string $sha256): void
    {
        $fullKey = $this->keyPrefix . $key;
        $size = filesize($localPath);
        if ($size === false) {
            throw new DestinationException("Cannot stat: {$localPath}");
        }

        $this->withRetry(function () use ($fullKey, $localPath, $size): void {
            try {
                $this->uploadOnce($fullKey, $localPath, $size, $this->checksumApiSupported);
            } catch (\Throwable $e) {
                if (!$this->checksumApiSupported || !$this->isChecksumRejection($e)) {
                    throw $e;
                }
                $this->disableChecksumApi(
                    'switching to checksum-free requests and full read-back SHA-256 verification.',
                );
                $this->uploadOnce($fullKey, $localPath, $size, false);
            }
        });

        $this->verifyUploaded($fullKey, $localPath, $sha256, $size);
    }

    private function uploadOnce(string $fullKey, string $localPath, int $size, bool $withChecksum): void
    {
        if ($size >= $this->multipartThresholdBytes) {
            $options = [
                'bucket' => $this->bucket,
                'key' => $fullKey,
            ];
            if ($withChecksum) {
                $options['params'] = ['ChecksumAlgorithm' => 'SHA256'];
            }
            $uploader = new MultipartUploader($this->client, $localPath, $options);
            try {
                $uploader->upload();
            } catch (\Throwable $e) {
                // Explicitly abort the partial upload (spec §4)
                try {
                    $state = method_exists($uploader, 'getState') ? $uploader->getState() : null;
                    $uploadId = $state?->getId()['UploadId'] ?? null;
                    if ($uploadId !== null) {
                        $this->client->abortMultipartUpload([
                            'Bucket' => $this->bucket, 'Key' => $fullKey, 'UploadId' => $uploadId,
                        ]);
                    }
                } catch (\Throwable) {
                    // A failed abort leaves the multipart upload until an operator aborts it or a lifecycle rule removes it
                }
                throw $e;
            }
        } else {
            $params = [
                'Bucket' => $this->bucket,
                'Key' => $fullKey,
                'SourceFile' => $localPath,
            ];
            if ($withChecksum) {
                $params['ChecksumAlgorithm'] = 'SHA256';
            }
            $this->client->putObject($params);
        }
    }

    /**
     * Confirmed heuristics for detecting providers that reject the S3 checksum API.
     *
     * R2 smoke test (2026-07-14) and B2 smoke test (2026-07-17): both fully support
     * ChecksumAlgorithm=SHA256 — this fallback path never fires on either provider.
     * The heuristic set covers generic S3-compatible providers that may not implement
     * the checksum extension.
     */
    private function isChecksumRejection(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (!$current instanceof AwsException) {
                continue;
            }
            $errorCode = $current->getAwsErrorCode();
            if ($errorCode === 'NotImplemented' || $current->getStatusCode() === 501) {
                return true;
            }
            if (
                \in_array($errorCode, ['InvalidRequest', 'InvalidArgument', 'UnsupportedArgument', 'UnsupportedOperation'], true)
                && preg_match('/checksum/i', $current->getAwsErrorMessage() ?? $current->getMessage()) === 1
            ) {
                return true;
            }
        }
        return false;
    }

    private function disableChecksumApi(string $detail): void
    {
        $this->checksumApiSupported = false;
        \Craft::warning(
            'Offsite: the S3 provider rejected the checksum API; ' . $detail,
            __METHOD__,
        );
    }

    /**
     * Provider-native checksum verification with full read-back fallback.
     */
    private function verifyUploaded(string $fullKey, string $localPath, string $expectedSha256Hex, int $expectedSize): void
    {
        // Don't fail an already-uploaded run on a transient HEAD failure (partial fix for Codex review B8)
        $head = $this->withRetry(function () use ($fullKey): \Aws\ResultInterface {
            $params = ['Bucket' => $this->bucket, 'Key' => $fullKey];
            if ($this->checksumApiSupported) {
                $params['ChecksumMode'] = 'ENABLED';
            }
            try {
                return $this->client->headObject($params);
            } catch (\Throwable $e) {
                if (!$this->checksumApiSupported || !$this->isChecksumRejection($e)) {
                    throw $e;
                }
                $this->disableChecksumApi(
                    'retrying HEAD without checksum mode and switching to full read-back SHA-256 verification.',
                );
                return $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $fullKey]);
            }
        });

        if ((int)$head['ContentLength'] !== $expectedSize) {
            throw new DestinationException("Size mismatch for {$fullKey}: remote {$head['ContentLength']}, local {$expectedSize}.");
        }

        $remoteChecksum = $head['ChecksumSHA256'] ?? null;
        // A multipart composite checksum ("...-N" suffix) is not a full-object hash, so fall through to read-back
        if (\is_string($remoteChecksum) && !str_contains($remoteChecksum, '-')) {
            $expectedB64 = base64_encode((string)hex2bin($expectedSha256Hex));
            if ($remoteChecksum !== $expectedB64) {
                throw new DestinationException("Provider SHA-256 mismatch for {$fullKey}.");
            }
            return;
        }

        // Fallback: full read-back SHA-256 (always performed before catalog publish — spec §4 r3 fix).
        // Prefer the work dir whose capacity the preflight has verified, so we don't
        // exhaust /tmp on a different filesystem (Codex review S13)
        $tmp = tempnam($this->verifyTmpDir ?? sys_get_temp_dir(), 'offsite-verify');
        try {
            $this->getToFile(substr($fullKey, \strlen($this->keyPrefix)), $tmp);
            if (hash_file('sha256', $tmp) !== $expectedSha256Hex) {
                throw new DestinationException("Read-back SHA-256 mismatch for {$fullKey}.");
            }
        } finally {
            @unlink($tmp);
        }
    }

    public function getToFile(string $key, string $localPath): void
    {
        $this->withRetry(function () use ($key, $localPath): void {
            $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $this->keyPrefix . $key,
                '@http' => ['sink' => $localPath],
            ]);
        });
    }

    public function listByPrefix(string $prefix): array
    {
        $out = [];
        $params = ['Bucket' => $this->bucket, 'Prefix' => $this->keyPrefix . $prefix];
        do {
            $result = $this->client->listObjectsV2($params);
            foreach ($result['Contents'] ?? [] as $obj) {
                $lastModified = $obj['LastModified'] ?? null;
                if (!$lastModified instanceof \DateTimeInterface) {
                    \Craft::warning(
                        "Offsite: object {$obj['Key']} has no LastModified; treating it as new, so it will not be pruned as an orphan.",
                        __METHOD__,
                    );
                }
                $out[] = [
                    'key' => substr((string)$obj['Key'], \strlen($this->keyPrefix)),
                    'size' => (int)($obj['Size'] ?? 0),
                    // Treat objects with a missing LastModified as young to avoid deleting them by mistake
                    'lastModified' => $lastModified instanceof \DateTimeInterface ? $lastModified->getTimestamp() : time(),
                ];
            }
            $params['ContinuationToken'] = $result['NextContinuationToken'] ?? null;
        } while (($result['IsTruncated'] ?? false) && $params['ContinuationToken'] !== null);
        return $out;
    }

    public function delete(string $key): void
    {
        $this->withRetry(fn() => $this->client->deleteObject([
            'Bucket' => $this->bucket, 'Key' => $this->keyPrefix . $key,
        ]));
    }

    public function putString(string $key, string $contents): void
    {
        $this->withRetry(fn() => $this->client->putObject([
            'Bucket' => $this->bucket, 'Key' => $this->keyPrefix . $key,
            'Body' => $contents, 'ContentType' => 'application/json',
        ]));
    }

    public function getString(string $key): string
    {
        return $this->withRetry(function () use ($key): string {
            try {
                $result = $this->client->getObject(['Bucket' => $this->bucket, 'Key' => $this->keyPrefix . $key]);
            } catch (AwsException $e) {
                if ($e->getStatusCode() === 404) {
                    throw new DestinationException("No such key: {$key}", 0, $e);
                }
                throw $e;
            }
            return (string)$result['Body'];
        });
    }

    /** @template T @param callable(): T $fn @return T */
    private function withRetry(callable $fn): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $fn();
            } catch (DestinationException $e) {
                throw $e; // Verification failures are never retried
            } catch (\Throwable $e) {
                if ($attempt >= $this->maxAttempts) {
                    throw new DestinationException($e->getMessage(), 0, $e);
                }
                usleep((int)(2 ** $attempt * 250_000)); // Exponential backoff: 0.5s, 1s
            }
        }
    }
}
