<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\integration;

use Aws\S3\S3Client;
use cdgrph\offsite\engine\DestinationException;
use cdgrph\offsite\engine\S3Destination;
use PHPUnit\Framework\TestCase;

final class S3DestinationTest extends TestCase
{
    private S3Destination $dest;
    private S3Client $client;
    private string $bucket = 'offsite-test';

    protected function setUp(): void
    {
        if (getenv('OFFSITE_TEST_S3') !== '1') {
            self::markTestSkipped('Set OFFSITE_TEST_S3=1 with MinIO running (docker compose up -d).');
        }
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => getenv('OFFSITE_TEST_S3_ENDPOINT') ?: 'http://127.0.0.1:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'offsite', 'secret' => 'offsite-secret'],
        ]);
        if (!$this->client->doesBucketExistV2($this->bucket)) {
            $this->client->createBucket(['Bucket' => $this->bucket]);
        }
        $this->dest = new S3Destination($this->client, $this->bucket, 'test/');
    }

    public function testPutVerifiesAndRoundTrips(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'obj');
        file_put_contents($src, str_repeat('offsite', 1000));
        $sha = hash_file('sha256', $src);

        $this->dest->put('db/it-1.zip', $src, $sha);

        $downloaded = tempnam(sys_get_temp_dir(), 'dl');
        $this->dest->getToFile('db/it-1.zip', $downloaded);
        self::assertSame($sha, hash_file('sha256', $downloaded));

        $objects = $this->dest->listByPrefix('db/');
        $keys = array_column($objects, 'key');
        self::assertContains('db/it-1.zip', $keys);
        $listed = $objects[array_search('db/it-1.zip', $keys, true)];
        self::assertIsInt($listed['lastModified']);
        self::assertGreaterThan(0, $listed['lastModified']);
        self::assertLessThanOrEqual(300, abs(time() - $listed['lastModified']));

        $this->dest->delete('db/it-1.zip');
        self::assertNotContains('db/it-1.zip', array_column($this->dest->listByPrefix('db/'), 'key'));
        unlink($src);
        unlink($downloaded);
    }

    public function testPutRejectsWrongSha(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'obj');
        file_put_contents($src, 'data');
        $this->expectException(DestinationException::class);
        $this->dest->put('db/it-bad.zip', $src, str_repeat('0', 64));
    }

    public function testStringRoundTrip(): void
    {
        $this->dest->putString('catalog/it.json', '{"a":1}');
        self::assertSame('{"a":1}', $this->dest->getString('catalog/it.json'));
        $this->dest->delete('catalog/it.json');
    }

    public function testInvalidCredentialsFailAsDestinationException(): void
    {
        // Fault injection for the "revoked credentials" acceptance criterion (spec §7)
        $badClient = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => getenv('OFFSITE_TEST_S3_ENDPOINT') ?: 'http://127.0.0.1:9000',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'wrong', 'secret' => 'wrong-secret'],
        ]);
        $dest = new S3Destination($badClient, $this->bucket, 'test/', maxAttempts: 1);
        $src = tempnam(sys_get_temp_dir(), 'obj');
        file_put_contents($src, 'data');
        try {
            $this->expectException(DestinationException::class);
            $dest->put('db/it-cred.zip', $src, (string)hash_file('sha256', $src));
        } finally {
            unlink($src);
        }
    }
}
