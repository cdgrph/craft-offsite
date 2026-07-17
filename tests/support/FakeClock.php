<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\support;

use cdgrph\offsite\engine\Clock;

final class FakeClock implements Clock
{
    public function __construct(public int $t = 1000)
    {
    }

    public function now(): int
    {
        return $this->t;
    }
}
