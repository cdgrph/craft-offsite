<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/** Dead-man's-switch ping, healthchecks.io compatible (spec §1). */
final class HeartbeatPinger
{
    private readonly string $baseUrl;

    public function __construct(
        private readonly HttpPoster $http,
        string $url,
    ) {
        $this->baseUrl = rtrim($url, '/');
    }

    public function success(): void
    {
        $this->http->get($this->baseUrl);
    }

    public function failure(): void
    {
        $this->http->get($this->baseUrl . '/fail');
    }
}
