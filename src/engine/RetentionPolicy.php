<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Two retention modes (spec §5-3):
 * - 'plugin':    the plugin deletes old generations (credential has delete rights)
 * - 'lifecycle': deletion is fully delegated to bucket lifecycle rules; plugin never deletes
 */
final class RetentionPolicy
{
    public const MODE_PLUGIN = 'plugin';
    public const MODE_LIFECYCLE = 'lifecycle';

    public function __construct(
        public readonly string $mode,
        public readonly int $keepCount,
    ) {
        if (!\in_array($mode, [self::MODE_PLUGIN, self::MODE_LIFECYCLE], true)) {
            throw new \InvalidArgumentException("Unknown retention mode: {$mode}");
        }
        if ($keepCount < 1) {
            throw new \InvalidArgumentException('keepCount must be >= 1');
        }
    }

    /**
     * @param list<CatalogEntry> $entriesSortedDesc newest first
     * @return list<CatalogEntry> entries to delete (oldest beyond keepCount)
     */
    public function selectForDeletion(array $entriesSortedDesc): array
    {
        if ($this->mode === self::MODE_LIFECYCLE) {
            return [];
        }
        return \array_slice($entriesSortedDesc, $this->keepCount);
    }
}
