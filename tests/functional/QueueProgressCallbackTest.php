<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\functional;

use cdgrph\offsite\jobs\QueueProgressCallback;
use PHPUnit\Framework\TestCase;

final class QueueProgressCallbackTest extends TestCase
{
    public function testForwardsProgressToTheUpdater(): void
    {
        if (!class_exists(QueueProgressCallback::class)) {
            self::fail('QueueProgressCallback is not implemented.');
        }
        $updates = [];
        $warnings = [];
        $callback = new QueueProgressCallback(
            update: static function(float $progress, string $label) use (&$updates): void {
                $updates[] = [$progress, $label];
            },
            warning: static function(\Throwable $e) use (&$warnings): void {
                $warnings[] = $e->getMessage();
            },
        );

        $callback(0.55, 'Uploading backup');

        self::assertSame([[0.55, 'Uploading backup']], $updates);
        self::assertSame([], $warnings);
    }

    public function testContainsUpdateFailuresAndReportsAWarning(): void
    {
        $warnings = [];
        $callback = new QueueProgressCallback(
            update: static function(float $progress, string $label): void {
                throw new \RuntimeException('Queue database unavailable.');
            },
            warning: static function(\Throwable $e) use (&$warnings): void {
                $warnings[] = $e->getMessage();
            },
        );
        $thrown = null;

        try {
            $callback(0.55, 'Uploading backup');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        self::assertNull($thrown, 'Queue progress failures must not escape the callback.');
        self::assertSame(['Queue database unavailable.'], $warnings);
    }
}
