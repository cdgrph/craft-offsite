<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Phase-status run record. Backup status is a strict state machine;
 * side effects (retention, notifications, heartbeat) are independent
 * and can never flip a committed backup (spec §5-1).
 */
final class RunRecord
{
    public const PREPARED = 'prepared';
    public const UPLOADING = 'uploading';
    public const COMMITTED = 'committed';
    public const FAILED = 'failed';

    private string $backupStatus = self::PREPARED;
    private ?string $failureReason = null;
    /** @var array<string, array{ok: bool, error: ?string}> */
    private array $sideEffects = [];
    /** @var array<string, string> display-ready details (size, duration) — set by finish() */
    private array $details = [];

    public function __construct(
        public readonly string $runId,
        public readonly string $startedAt,
    ) {
    }

    public function markUploading(): void
    {
        $this->transition(self::PREPARED, self::UPLOADING);
    }

    public function markCommitted(): void
    {
        $this->transition(self::UPLOADING, self::COMMITTED);
    }

    public function markFailed(string $reason): void
    {
        if ($this->backupStatus === self::COMMITTED) {
            throw new \LogicException('A committed backup cannot be marked failed.');
        }
        $this->backupStatus = self::FAILED;
        $this->failureReason = $reason;
    }

    public function recordSideEffect(string $name, bool $ok, ?string $error = null): void
    {
        $this->sideEffects[$name] = ['ok' => $ok, 'error' => $error];
    }

    public function backupStatus(): string
    {
        return $this->backupStatus;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function isCommitted(): bool
    {
        return $this->backupStatus === self::COMMITTED;
    }

    /** @param array<string, string> $details */
    public function setDetails(array $details): void
    {
        $this->details = $details;
    }

    /** @return array<string, array{ok: bool, error: ?string}> */
    public function sideEffects(): array
    {
        return $this->sideEffects;
    }

    public function toArray(): array
    {
        return [
            'runId' => $this->runId,
            'startedAt' => $this->startedAt,
            'backupStatus' => $this->backupStatus,
            'failureReason' => $this->failureReason,
            'sideEffects' => $this->sideEffects,
            'details' => $this->details,
        ];
    }

    public static function fromArray(array $a): self
    {
        $r = new self($a['runId'], $a['startedAt']);
        $r->backupStatus = $a['backupStatus'];
        $r->failureReason = $a['failureReason'] ?? null;
        $r->sideEffects = $a['sideEffects'] ?? [];
        $r->details = $a['details'] ?? [];
        return $r;
    }

    private function transition(string $from, string $to): void
    {
        if ($this->backupStatus !== $from) {
            throw new \LogicException("Cannot transition from {$this->backupStatus} to {$to}.");
        }
        $this->backupStatus = $to;
    }
}
