<?php
declare(strict_types=1);

namespace cdgrph\offsite\jobs;

final class QueueProgressCallback
{
    public function __construct(
        private readonly \Closure $update,
        private readonly \Closure $warning,
    ) {
    }

    public function __invoke(float $progress, string $label): void
    {
        try {
            ($this->update)($progress, $label);
        } catch (\Throwable $e) {
            ($this->warning)($e);
        }
    }
}
