<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

interface Clock
{
    public function now(): int;
}
