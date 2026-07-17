<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class SystemClock implements Clock
{
    public function now(): int
    {
        return time();
    }
}
