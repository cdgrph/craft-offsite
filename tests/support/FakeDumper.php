<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\support;

use cdgrph\offsite\engine\DatabaseDumper;
use cdgrph\offsite\engine\DumpFormat;
use cdgrph\offsite\engine\DumpResult;

final class FakeDumper implements DatabaseDumper
{
    public bool $fail = false;
    public DumpFormat $format = DumpFormat::Plain;

    public function dump(string $targetDir): DumpResult
    {
        if ($this->fail) {
            throw new \RuntimeException('mysqldump exited 2');
        }
        $path = $targetDir . '/dump-' . uniqid() . $this->format->extension();
        file_put_contents(
            $path,
            $this->format === DumpFormat::Custom
                ? 'PGDMP' . random_bytes(64)
                : "-- fake dump\nSELECT 1;\n",
        );
        return new DumpResult($path, $this->format);
    }
}
