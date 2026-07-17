<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class CatalogEntry
{
    public function __construct(
        public readonly string $runId,
        public readonly string $startedAt,
        public readonly string $siteUid,
        public readonly string $craftVersion,
        public readonly string $schemaVersion,
        public readonly string $pluginVersion,
        public readonly string $dbDriver,
        public readonly string $objectKey,
        public readonly int $sizeBytes,
        public readonly string $sha256,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public static function fromArray(array $a): self
    {
        return new self(
            runId: $a['runId'],
            startedAt: $a['startedAt'],
            siteUid: $a['siteUid'],
            craftVersion: $a['craftVersion'],
            schemaVersion: $a['schemaVersion'],
            pluginVersion: $a['pluginVersion'],
            dbDriver: $a['dbDriver'],
            objectKey: $a['objectKey'],
            sizeBytes: $a['sizeBytes'],
            sha256: $a['sha256'],
        );
    }
}
