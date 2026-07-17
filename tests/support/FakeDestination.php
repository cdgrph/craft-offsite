<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\support;

use cdgrph\offsite\engine\Destination;
use cdgrph\offsite\engine\DestinationException;

final class FakeDestination implements Destination
{
    /** @var array<string, string> key => contents */
    public array $objects = [];
    /** @var array<string, int> key => Unix timestamp */
    public array $lastModified = [];
    public int $now = 1000;
    public ?string $failOnKeySubstring = null;

    private function maybeFail(string $key): void
    {
        if ($this->failOnKeySubstring !== null && str_contains($key, $this->failOnKeySubstring)) {
            throw new DestinationException("Injected failure for key: {$key}");
        }
    }

    public function put(string $key, string $localPath, string $sha256): void
    {
        $this->maybeFail($key);
        $this->objects[$key] = (string)file_get_contents($localPath);
        $this->lastModified[$key] = $this->now;
    }

    public function getToFile(string $key, string $localPath): void
    {
        $this->maybeFail($key);
        if (!isset($this->objects[$key])) {
            throw new DestinationException("No such key: {$key}");
        }
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function listByPrefix(string $prefix): array
    {
        $out = [];
        foreach ($this->objects as $key => $contents) {
            if (str_starts_with($key, $prefix)) {
                $out[] = [
                    'key' => $key,
                    'size' => \strlen($contents),
                    'lastModified' => $this->lastModified[$key] ?? $this->now,
                ];
            }
        }
        return $out;
    }

    public function delete(string $key): void
    {
        $this->maybeFail($key);
        unset($this->objects[$key]);
        unset($this->lastModified[$key]);
    }

    public function putString(string $key, string $contents): void
    {
        $this->maybeFail($key);
        $this->objects[$key] = $contents;
        $this->lastModified[$key] = $this->now;
    }

    public function getString(string $key): string
    {
        $this->maybeFail($key);
        if (!isset($this->objects[$key])) {
            throw new DestinationException("No such key: {$key}");
        }
        return $this->objects[$key];
    }
}
