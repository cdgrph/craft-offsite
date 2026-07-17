<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\support;

use cdgrph\offsite\engine\HttpPoster;

final class FakeHttpPoster implements HttpPoster
{
    /** @var list<array{method: string, url: string, body: ?string}> */
    public array $calls = [];
    public bool $fail = false;

    public function post(string $url, string $jsonBody): void
    {
        $this->calls[] = ['method' => 'POST', 'url' => $url, 'body' => $jsonBody];
        if ($this->fail) {
            throw new \RuntimeException('HTTP 500');
        }
    }

    public function get(string $url): void
    {
        $this->calls[] = ['method' => 'GET', 'url' => $url, 'body' => null];
        if ($this->fail) {
            throw new \RuntimeException('HTTP 500');
        }
    }
}
