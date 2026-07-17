<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Objects are uploaded before their catalog entries are published, so an
 * uncataloged object may be either an abandoned run or an in-flight upload.
 * Listing alone cannot distinguish them; only objects at least the minimum
 * age are eligible for deletion.
 */
final class OrphanPolicy
{
    public function __construct(private readonly int $minAgeSeconds)
    {
        if ($minAgeSeconds < 0) {
            throw new \InvalidArgumentException('minAgeSeconds must be >= 0');
        }
    }

    /**
     * @param list<array{key: string, size: int, lastModified?: int}> $objects All objects under the prefix
     * @param list<string> $catalogedKeys Object keys published in the catalog
     * @return array{orphans: list<string>, skipped: int} Keys to delete and the number protected by the age guard
     */
    public function select(array $objects, array $catalogedKeys, int $now): array
    {
        $cataloged = array_fill_keys($catalogedKeys, true);
        $threshold = $now - $this->minAgeSeconds;
        $orphans = [];
        $skipped = 0;

        foreach ($objects as $object) {
            if (isset($cataloged[$object['key']])) {
                continue;
            }
            if (($object['lastModified'] ?? $now) <= $threshold) {
                $orphans[] = $object['key'];
            } else {
                $skipped++;
            }
        }

        return ['orphans' => $orphans, 'skipped' => $skipped];
    }
}
