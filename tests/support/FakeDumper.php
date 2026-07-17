<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\support;

use cdgrph\offsite\engine\DatabaseDumper;

final class FakeDumper implements DatabaseDumper
{
    public bool $fail = false;

    public function dump(string $targetDir): string
    {
        if ($this->fail) {
            throw new \RuntimeException('mysqldump exited 2');
        }
        $path = $targetDir . '/dump-' . uniqid() . '.sql';
        file_put_contents($path, "-- fake dump\nSELECT 1;\n");
        return $path;
    }
}
