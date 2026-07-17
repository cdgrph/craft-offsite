<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

interface HttpPoster
{
    /** @throws \RuntimeException on non-2xx or transport error */
    public function post(string $url, string $jsonBody): void;

    /** @throws \RuntimeException on non-2xx or transport error */
    public function get(string $url): void;
}
