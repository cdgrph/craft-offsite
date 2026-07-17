<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\CatalogEntry;
use cdgrph\offsite\engine\RetentionPolicy;
use PHPUnit\Framework\TestCase;

final class RetentionPolicyTest extends TestCase
{
    /** @return list<CatalogEntry> desc order */
    private function entries(int $n): array
    {
        $out = [];
        for ($i = $n; $i >= 1; $i--) {
            $out[] = new CatalogEntry(
                runId: "run-{$i}", startedAt: sprintf('2026-07-%02dT00:00:00+00:00', $i),
                siteUid: 'u', craftVersion: '5.8.0', schemaVersion: 's', pluginVersion: '1.0.0',
                dbDriver: 'mysql', objectKey: "db/run-{$i}.zip", sizeBytes: 1, sha256: str_repeat('a', 64),
            );
        }
        return $out;
    }

    public function testPluginModeSelectsOldestBeyondKeepCount(): void
    {
        $policy = new RetentionPolicy('plugin', 3);
        $toDelete = $policy->selectForDeletion($this->entries(5));
        self::assertSame(['run-2', 'run-1'], array_map(fn($e) => $e->runId, $toDelete));
    }

    public function testPluginModeNothingToDeleteWithinKeepCount(): void
    {
        $policy = new RetentionPolicy('plugin', 5);
        self::assertSame([], $policy->selectForDeletion($this->entries(3)));
    }

    public function testLifecycleModeNeverDeletes(): void
    {
        $policy = new RetentionPolicy('lifecycle', 3);
        self::assertSame([], $policy->selectForDeletion($this->entries(10)));
    }

    public function testInvalidModeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetentionPolicy('magic', 3);
    }

    public function testKeepCountMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RetentionPolicy('plugin', 0);
    }
}
