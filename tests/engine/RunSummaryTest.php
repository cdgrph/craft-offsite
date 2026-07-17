<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\RunSummary;
use PHPUnit\Framework\TestCase;

final class RunSummaryTest extends TestCase
{
    public function testMissingSiteFallsBackToUnknown(): void
    {
        $summary = new RunSummary('run-1', true, 'Backup succeeded');

        self::assertSame('unknown', $summary->site());
    }

    public function testEmptySiteFallsBackToUnknown(): void
    {
        $summary = new RunSummary('run-2', true, 'Backup succeeded', ['site' => '']);

        self::assertSame('unknown', $summary->site());
    }

    public function testNonEmptySiteIsReturned(): void
    {
        $summary = new RunSummary('run-3', true, 'Backup succeeded', ['site' => 'example.com']);

        self::assertSame('example.com', $summary->site());
    }
}
