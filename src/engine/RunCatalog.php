<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Remote commit catalog — the single source of truth for what is backed up
 * (spec §4). list/restore read this catalog, never the local DB cache.
 */
final class RunCatalog
{
    public function __construct(
        private readonly Destination $dest,
        private readonly string $prefix = 'catalog/',
    ) {
    }

    public function publish(CatalogEntry $entry): void
    {
        $this->dest->putString($this->keyFor($entry->runId), json_encode($entry->toArray(), JSON_PRETTY_PRINT));
    }

    /** @return list<CatalogEntry> sorted by startedAt desc */
    public function all(): array
    {
        $entries = [];
        foreach ($this->dest->listByPrefix($this->prefix) as $obj) {
            $entries[] = CatalogEntry::fromArray(json_decode($this->dest->getString($obj['key']), true));
        }
        usort($entries, fn(CatalogEntry $a, CatalogEntry $b) => strcmp($b->startedAt, $a->startedAt));
        return $entries;
    }

    public function get(string $runId): CatalogEntry
    {
        return CatalogEntry::fromArray(json_decode($this->dest->getString($this->keyFor($runId)), true));
    }

    public function remove(string $runId): void
    {
        $this->dest->delete($this->keyFor($runId));
    }

    private function keyFor(string $runId): string
    {
        return $this->prefix . $runId . '.json';
    }
}
