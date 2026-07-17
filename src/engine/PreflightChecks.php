<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

final class PreflightChecks
{
    /** @var callable(string): (int|float|false) */
    private $freeSpaceFn;

    public function __construct(
        private readonly string $workDir,
        private readonly int $minFreeBytes,
        ?callable $freeSpaceFn = null,
    ) {
        $this->freeSpaceFn = $freeSpaceFn ?? 'disk_free_space';
    }

    public function run(): void
    {
        if (!is_dir($this->workDir) || !is_writable($this->workDir)) {
            throw new PreflightException("Work directory not writable: {$this->workDir}");
        }
        $free = ($this->freeSpaceFn)($this->workDir);
        if ($free === false || $free < $this->minFreeBytes) {
            throw new PreflightException(
                sprintf('Insufficient disk space in %s: %s bytes free, %d required.', $this->workDir, var_export($free, true), $this->minFreeBytes)
            );
        }
    }
}
