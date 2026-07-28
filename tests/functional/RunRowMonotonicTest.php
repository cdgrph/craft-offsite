<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\functional;

use cdgrph\offsite\engine\RunRecord;
use cdgrph\offsite\records\RunRow;
use PHPUnit\Framework\TestCase;

/**
 * Pure decision rule for the local run cache: a committed row is never
 * demoted by a later non-committed attempt (redelivery races).
 */
final class RunRowMonotonicTest extends TestCase
{
    public function testInsertIntoEmptyAlwaysAllowed(): void
    {
        self::assertTrue(RunRow::shouldOverwrite(null, RunRecord::FAILED));
        self::assertTrue(RunRow::shouldOverwrite(null, RunRecord::COMMITTED));
    }

    public function testCommittedIsNeverDemoted(): void
    {
        self::assertFalse(RunRow::shouldOverwrite(RunRecord::COMMITTED, RunRecord::FAILED));
        self::assertFalse(RunRow::shouldOverwrite(RunRecord::COMMITTED, RunRecord::PREPARED));
        self::assertFalse(RunRow::shouldOverwrite(RunRecord::COMMITTED, RunRecord::UPLOADING));
    }

    public function testCommittedOverwritesAnything(): void
    {
        self::assertTrue(RunRow::shouldOverwrite(RunRecord::FAILED, RunRecord::COMMITTED));
        self::assertTrue(RunRow::shouldOverwrite(RunRecord::COMMITTED, RunRecord::COMMITTED));
    }

    public function testNonCommittedOverwritesNonCommitted(): void
    {
        self::assertTrue(RunRow::shouldOverwrite(RunRecord::FAILED, RunRecord::FAILED));
    }
}
