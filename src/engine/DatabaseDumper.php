<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

interface DatabaseDumper
{
    /** Dumps the DB into $targetDir and returns the dump path and format. Throws on failure. */
    public function dump(string $targetDir): DumpResult;
}
