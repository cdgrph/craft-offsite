<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Outcome of a database dump: where the file landed and in which format,
 * so downstream steps (archive entry naming, compression, catalog metadata,
 * restore dispatch) all derive from one source of truth.
 */
final class DumpResult
{
    public function __construct(
        public readonly string $path,
        public readonly DumpFormat $format,
    ) {
    }
}
