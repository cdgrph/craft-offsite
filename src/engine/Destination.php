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

    /**
     * True when the key exists. A missing key (404) returns false; any other
     * failure throws — an error must never be read as absence (spec: the
     * commit-aware resume check would otherwise re-run a committed backup).
     */
    public function exists(string $key): bool;
}
