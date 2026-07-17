<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

interface Destination
{
    /** Upload with integrity verification (spec §4). Throws DestinationException on any failure. */
    public function put(string $key, string $localPath, string $sha256): void;

    public function getToFile(string $key, string $localPath): void;

    /** @return list<array{key: string, size: int, lastModified: int}> */
    public function listByPrefix(string $prefix): array;

    public function delete(string $key): void;

    public function putString(string $key, string $contents): void;

    public function getString(string $key): string;
}
