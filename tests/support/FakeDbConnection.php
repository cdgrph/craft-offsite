<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\support;

/**
 * Duck-typed stand-in for craft\db\Connection covering exactly the surface
 * CraftDatabaseDumper touches (getIsPgsql / getSchema / backupTo), so the
 * dumper's format handling can be exercised without a database. backupTo()
 * writes the configured bytes to whatever path it is given.
 */
final class FakeDbConnection
{
    /** @var string[] paths passed to backupTo() */
    public array $backupCalls = [];
    public bool $failBackupTo = false;

    public function __construct(
        private readonly bool $pgsql,
        private readonly ?object $schema,
        private readonly string $dumpBytes,
    ) {
    }

    public function getIsPgsql(): bool
    {
        return $this->pgsql;
    }

    public function getSchema(): ?object
    {
        return $this->schema;
    }

    public function backupTo(string $path): void
    {
        if ($this->failBackupTo) {
            // Simulate a dump that fails mid-write, leaving a partial file
            file_put_contents($path, 'partial dump byt');
            throw new \RuntimeException('pg_dump exited 1');
        }
        file_put_contents($path, $this->dumpBytes);
        $this->backupCalls[] = $path;
    }
}
