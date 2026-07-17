<?php
declare(strict_types=1);

namespace cdgrph\offsite\engine;

/**
 * Holds a host-local flock while an Offsite run performs protected work.
 *
 * Four counter-intuitive properties are deliberate:
 *
 * - The descriptor is inherited by child processes (no close-on-exec), so the
 *   lock is released only when the last process holding the descriptor exits.
 * - There is no TTL, takeover, or escape hatch. A hung holder keeps the lock.
 * - lock.json is diagnostic metadata only. flock provides exclusion, and a
 *   metadata write failure after acquisition must not abort protected work.
 * - flock belongs to an inode, not a path. progress() revalidates the guard so
 *   a replaced or unreadable guard fails closed at the next phase boundary.
 *
 * Do not "fix" any of these without reading docs/setup.md first.
 */
final class RunLock
{
    private const MAX_INODE_ATTEMPTS = 3;
    private const MAX_CONTENTION_RETRIES = 5;
    private const CONTENTION_RETRY_MICROSECONDS = 50_000;
    private const MAX_STATE_BYTES = 65_536;

    /** @var resource|null */
    private $handle = null;

    private ?string $owner = null;

    /** @var array{owner: string, pid: int, acquiredAt: int, currentPhase: string, phaseStartedAt: int, status: string}|null */
    private ?array $state = null;

    public function __construct(
        private readonly string $guardPath,
        private readonly string $statePath,
        private readonly Clock $clock,
    ) {
        foreach (array_unique([\dirname($guardPath), \dirname($statePath)]) as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create lock directory: {$dir}");
            }
        }
    }

    public function acquire(string $owner): void
    {
        if ($this->handle !== null) {
            throw new \LogicException('This RunLock instance already holds the lock.');
        }

        $inodeAttempts = 0;
        $contentionRetries = 0;
        while ($inodeAttempts < self::MAX_INODE_ATTEMPTS) {
            // No close-on-exec: children (mysqldump/mysql) inherit the fd, so the lock
            // lives as long as the protected work does, even if the parent dies mid-run.
            $handle = @fopen($this->guardPath, 'c');
            if ($handle === false) {
                throw new \RuntimeException("Cannot open lock guard: {$this->guardPath}");
            }

            $wouldBlock = 0;
            if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
                fclose($handle);
                if ($wouldBlock === 1) {
                    // Don't fail a production run just because it raced a short diagnose
                    // probe. A real holder keeps the lock for the whole run, so the
                    // conclusion is the same 250ms later.
                    if ($contentionRetries >= self::MAX_CONTENTION_RETRIES) {
                        throw new LockUnavailableException($this->unavailableSummary());
                    }
                    $contentionRetries++;
                    usleep(self::CONTENTION_RETRY_MICROSECONDS);
                    continue;
                }
                throw new \RuntimeException("Cannot acquire lock guard: {$this->guardPath}");
            }

            if (!$this->guardMatchesPath($handle)) {
                // flock binds to the inode: holding an fd whose path was swapped out
                // allows running in parallel with the new inode's holder. Discard the
                // acquisition and try again (three attempts in total).
                flock($handle, LOCK_UN);
                fclose($handle);
                $inodeAttempts++;
                continue;
            }

            $now = $this->clock->now();
            $this->handle = $handle;
            $this->owner = $owner;
            $this->state = [
                'owner' => $owner,
                'pid' => (int)getmypid(),
                'acquiredAt' => $now,
                'currentPhase' => 'acquired',
                'phaseStartedAt' => $now,
                'status' => 'active',
            ];

            try {
                $this->writeState($this->state);
            } catch (\Throwable $e) {
                try {
                    flock($handle, LOCK_UN);
                } finally {
                    fclose($handle);
                    $this->handle = null;
                    $this->owner = null;
                    $this->state = null;
                }
                throw $e;
            }
            return;
        }

        throw new \RuntimeException("Lock guard changed inode or became unreadable during acquisition: {$this->guardPath}");
    }

    /** @param resource $handle */
    private function guardMatchesPath($handle): bool
    {
        $descriptorStat = fstat($handle);
        clearstatcache(true, $this->guardPath);
        $pathStat = @stat($this->guardPath);

        return $descriptorStat !== false
            && $pathStat !== false
            && $descriptorStat['ino'] === $pathStat['ino']
            && $descriptorStat['dev'] === $pathStat['dev'];
    }

    public function progress(string $phase): void
    {
        if ($this->handle === null || $this->state === null) {
            throw new \LogicException('Cannot record progress without holding the lock.');
        }
        if (!$this->guardMatchesPath($this->handle)) {
            // After a path swap another process can acquire the new inode, so unlike the
            // diagnostics this cannot guarantee correctness. Fail closed instead of
            // moving on to the next phase.
            throw new \RuntimeException('Lock guard was replaced or became unreadable; cannot guarantee mutual exclusion. Aborting the run.');
        }
        $this->state['currentPhase'] = $phase;
        $this->state['phaseStartedAt'] = $this->clock->now();
        try {
            $this->writeState($this->state);
        } catch (\Throwable) {
            // The state file is diagnostics-only. As long as flock is held, run correctness is unaffected.
        }
    }

    public function release(string $owner): void
    {
        if ($this->handle === null || $this->owner !== $owner) {
            return;
        }

        $handle = $this->handle;
        try {
            if ($this->state !== null) {
                $this->state['status'] = 'released';
                try {
                    $this->writeState($this->state);
                } catch (\Throwable) {
                    // The released marker is diagnostics-only. Only unlock success/failure is reported to the caller.
                }
            }
        } finally {
            try {
                $unlocked = flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
                $this->handle = null;
                $this->owner = null;
                $this->state = null;
            }
        }

        if (!$unlocked) {
            throw new \RuntimeException("Cannot release lock guard: {$this->guardPath}");
        }
    }

    /** @return list<string> */
    public function diagnostics(): array
    {
        $lines = ['lock guard: ' . $this->guardPath];
        if (!is_file($this->guardPath)) {
            $lines[] = 'lock availability: available (no run has taken the lock yet)';
        } else {
            $guard = @fopen($this->guardPath, 'r');
            if ($guard === false) {
                $lines[] = 'lock availability: check FAILED — cannot open guard';
            } else {
                $wouldBlock = 0;
                if (flock($guard, LOCK_EX | LOCK_NB, $wouldBlock)) {
                    $lines[] = 'lock availability: available';
                    flock($guard, LOCK_UN);
                } elseif ($wouldBlock === 1) {
                    $lines[] = 'lock availability: HELD (kernel lock is currently contended)';
                } else {
                    $lines[] = 'lock availability: check FAILED — filesystem error';
                }
                fclose($guard);
            }
        }

        $state = $this->readActiveState();
        if ($state === null) {
            $lines[] = 'lock state: holder metadata unavailable';
        } else {
            $lines[] = 'lock state: active';
            $lines[] = '  last recorded holder : ' . $this->singleLine($state['owner']) . ' (may be stale)';
            $processStatus = $this->recordedProcessStatus($state['pid']);
            if ($processStatus !== null) {
                $lines[] = "  recorded pid         : {$state['pid']} ({$processStatus})";
            }
            $lines[] = '  acquired             : ' . date('Y-m-d H:i:s', $state['acquiredAt'])
                . ' (' . $this->elapsed($state['acquiredAt']) . ' ago)';
            $lines[] = '  current phase        : ' . $this->singleLine($state['currentPhase']) . ' (since '
                . date('H:i:s', $state['phaseStartedAt']) . ', '
                . $this->elapsed($state['phaseStartedAt']) . ' ago)';
        }

        $lines[] = '';
        $lines[] = 'Never delete the guard file. Deleting it does not break the lock — it duplicates it:';
        $lines[] = 'the running holder keeps its lock on the old inode while a new process takes a lock';
        $lines[] = 'on the new one, and both run against the same database.';
        $lines[] = '';
        $lines[] = 'The lock is released when the last process holding its descriptor exits. To find';
        $lines[] = 'the real holder:';
        $lines[] = '';
        $lines[] = '  lsof ' . $this->guardPath;
        $lines[] = '';
        if (($state['currentPhase'] ?? null) === 'import') {
            $lines[] = 'This run is importing a database. Do NOT kill it — you will be left with a';
            $lines[] = 'half-imported database. If it is genuinely stuck, stop traffic first, then';
            $lines[] = 'restore from the pre-restore dump under storage/runtime/offsite/work/.';
        } else {
            $lines[] = 'Killing the recorded pid alone may not release the lock because a child process';
            $lines[] = 'may still hold the inherited descriptor.';
        }

        return $lines;
    }

    /** @param array{owner: string, pid: int, acquiredAt: int, currentPhase: string, phaseStartedAt: int, status: string} $state */
    private function writeState(array $state): void
    {
        $tmp = $this->statePath . '.tmp';
        $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        if (@file_put_contents($tmp, $json . "\n") === false) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write lock state: {$tmp}");
        }
        if (!@rename($tmp, $this->statePath)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot commit lock state: {$this->statePath}");
        }
    }

    private function unavailableSummary(): string
    {
        $state = $this->readActiveState();
        if ($state === null) {
            return 'Another Offsite run holds the lock (holder metadata unavailable). '
                . 'Run `php craft offsite/diagnose` to identify the real holder.';
        }

        return 'Another Offsite run holds the lock (last recorded holder: '
            . $this->singleLine($state['owner']) . ", pid {$state['pid']}, phase "
            . $this->singleLine($state['currentPhase']) . ' for '
            . $this->elapsed($state['phaseStartedAt']) . ' — may be stale). '
            . 'Run `php craft offsite/diagnose` to identify the real holder.';
    }

    /** @return array{owner: string, pid: int, acquiredAt: int, currentPhase: string, phaseStartedAt: int, status: string}|null */
    private function readActiveState(): ?array
    {
        $raw = @file_get_contents($this->statePath, false, null, 0, self::MAX_STATE_BYTES + 1);
        if ($raw === false || strlen($raw) > self::MAX_STATE_BYTES) {
            return null;
        }
        $state = json_decode($raw, true);
        if (!is_array($state)
            || ($state['status'] ?? null) !== 'active'
            || !is_string($state['owner'] ?? null)
            || !is_int($state['pid'] ?? null)
            || $state['pid'] <= 0
            || !is_int($state['acquiredAt'] ?? null)
            || !is_string($state['currentPhase'] ?? null)
            || !is_int($state['phaseStartedAt'] ?? null)) {
            return null;
        }
        return $state;
    }

    private function recordedProcessStatus(int $pid): ?string
    {
        if (!function_exists('posix_kill') || !function_exists('posix_get_last_error')) {
            return null;
        }
        posix_get_last_error();
        if (posix_kill($pid, 0)) {
            return 'still running';
        }

        return posix_get_last_error() === 3
            ? 'not running'
            : 'running, or not visible to this user';
    }

    private function singleLine(string $value): string
    {
        $value = trim((string)preg_replace('/\s+/', ' ', $value));
        if (strlen($value) <= 160) {
            return $value;
        }
        return substr($value, 0, 157) . '...';
    }

    private function elapsed(int $since): string
    {
        $seconds = max(0, $this->clock->now() - $since);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }
        if ($minutes > 0) {
            return "{$minutes}m";
        }
        return "{$seconds}s";
    }
}
