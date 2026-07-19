<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\utilities;

use cdgrph\offsite\utilities\OffsiteUtility;
use PHPUnit\Framework\TestCase;

final class OffsiteUtilityTest extends TestCase
{
    public function testOverdueWhenNoBackupEverCommitted(): void
    {
        self::assertTrue(OffsiteUtility::isOverdue(null));
    }

    public function testNotOverdueForFreshBackup(): void
    {
        self::assertFalse(OffsiteUtility::isOverdue(0));
    }

    public function testNotOverdueJustBeforeThreshold(): void
    {
        self::assertFalse(OffsiteUtility::isOverdue(48 * 3600 - 1));
    }

    public function testOverdueExactlyAtThreshold(): void
    {
        self::assertTrue(OffsiteUtility::isOverdue(48 * 3600));
    }

    public function testOverdueBeyondThreshold(): void
    {
        self::assertTrue(OffsiteUtility::isOverdue(48 * 3600 + 1));
    }
}
