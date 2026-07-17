<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class ArchiveResult
{
    public function __construct(
        public readonly string $path,
        public readonly string $sha256,
        public readonly int $sizeBytes,
    ) {
    }
}
