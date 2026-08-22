<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\CatalogEntry;
use cdgrph\offsite\engine\DestinationException;
use cdgrph\offsite\engine\RunCatalog;
use cdgrph\offsite\tests\support\FakeDestination;
use PHPUnit\Framework\TestCase;

final class RunCatalogTest extends TestCase
{
    private function entry(string $runId, string $startedAt): CatalogEntry
    {
        return new CatalogEntry(
            runId: $runId,
            startedAt: $startedAt,
            siteUid: 'site-uid-1',
            craftVersion: '5.8.0',
            schemaVersion: '5.8.0.1',
            pluginVersion: '1.0.0',
            dbDriver: 'mysql',
            objectKey: "db/{$runId}.zip",
            sizeBytes: 123,
            sha256: str_repeat('a', 64),
        );
    }

    public function testPublishAndListSortedDesc(): void
    {
        $dest = new FakeDestination();
        $catalog = new RunCatalog($dest);
        $catalog->publish($this->entry('run-1', '2026-07-01T00:00:00+00:00'));
        $catalog->publish($this->entry('run-2', '2026-07-02T00:00:00+00:00'));

        $all = $catalog->all();
        self::assertCount(2, $all);
        self::assertSame('run-2', $all[0]->runId);
        self::assertArrayHasKey('catalog/run-1.json', $dest->objects);
    }

    public function testGetRoundTripsAllFields(): void
    {
        $dest = new FakeDestination();
        $catalog = new RunCatalog($dest);
        $catalog->publish($this->entry('run-1', '2026-07-01T00:00:00+00:00'));
        $e = $catalog->get('run-1');
        self::assertSame('site-uid-1', $e->siteUid);
        self::assertSame('db/run-1.zip', $e->objectKey);
        self::assertSame(str_repeat('a', 64), $e->sha256);
    }

    public function testGetMissingThrows(): void
    {
        $catalog = new RunCatalog(new FakeDestination());
        $this->expectException(DestinationException::class);
        $catalog->get('nope');
    }

    public function testRemoveDeletesManifest(): void
    {
        $dest = new FakeDestination();
        $catalog = new RunCatalog($dest);
        $catalog->publish($this->entry('run-1', '2026-07-01T00:00:00+00:00'));
        $catalog->remove('run-1');
        self::assertSame([], $catalog->all());
    }

    public function testHasReturnsTrueForPublishedRun(): void
    {
        $dest = new FakeDestination();
        $catalog = new RunCatalog($dest);
        $catalog->publish($this->entry('run-1', '2026-07-01T00:00:00+00:00'));
        self::assertTrue($catalog->has('run-1'));
    }

    public function testHasReturnsFalseForUnknownRun(): void
    {
        self::assertFalse((new RunCatalog(new FakeDestination()))->has('nope'));
    }

    public function testHasPropagatesDestinationFailure(): void
    {
        $dest = new FakeDestination();
        $dest->failOnKeySubstring = 'catalog/';
        $this->expectException(DestinationException::class);
        (new RunCatalog($dest))->has('run-1');
    }

    public function testFromArrayDefaultsDumpFormatToPlain(): void
    {
        // Manifests published before the dumpFormat field existed must keep restoring
        $legacy = $this->entry('run-1', '2026-07-01T00:00:00+00:00')->toArray();
        unset($legacy['dumpFormat']);

        $e = CatalogEntry::fromArray($legacy);
        self::assertSame('plain', $e->dumpFormat);
    }

    public function testDumpFormatRoundTripsThroughCatalog(): void
    {
        $dest = new FakeDestination();
        $catalog = new RunCatalog($dest);
        $catalog->publish(new CatalogEntry(
            runId: 'run-1',
            startedAt: '2026-07-01T00:00:00+00:00',
            siteUid: 'site-uid-1',
            craftVersion: '5.10.11',
            schemaVersion: '5.10.0.1',
            pluginVersion: '1.1.0',
            dbDriver: 'pgsql',
            objectKey: 'db/run-1.zip',
            sizeBytes: 123,
            sha256: str_repeat('a', 64),
            dumpFormat: 'custom',
        ));

        $e = $catalog->get('run-1');
        self::assertSame('custom', $e->dumpFormat);
        $stored = json_decode($dest->objects['catalog/run-1.json'], true);
        self::assertSame('custom', $stored['dumpFormat'] ?? null);
    }
}
