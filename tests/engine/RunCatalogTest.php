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
}
