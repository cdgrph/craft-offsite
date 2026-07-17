<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

interface DatabaseDumper
{
    /** Dumps the DB into $targetDir and returns the dump file path. Throws on failure. */
    public function dump(string $targetDir): string;
}
