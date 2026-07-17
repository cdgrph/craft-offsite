<?php
declare(strict_types=1);

namespace cdgrph\offsite\tests\engine;

use cdgrph\offsite\engine\OrphanPolicy;
use PHPUnit\Framework\TestCase;

final class OrphanPolicyTest extends TestCase
{
    public function testOldUncatalogedObjectIsOrphan(): void
    {
        $policy = new OrphanPolicy(100);

        self::assertSame(
            ['orphans' => ['db/old.zip'], 'skipped' => 0],
            $policy->select(
                [['key' => 'db/old.zip', 'size' => 1, 'lastModified' => 899]],
                [],
                1000,
            ),
        );
    }

    public function testYoungUncatalogedObjectIsSkipped(): void
    {
        $policy = new OrphanPolicy(100);

        self::assertSame(
            ['orphans' => [], 'skipped' => 1],
            $policy->select(
                [['key' => 'db/in-flight.zip', 'size' => 1, 'lastModified' => 901]],
                [],
                1000,
            ),
        );
    }

    public function testCatalogedObjectIsNeverOrphan(): void
    {
        $policy = new OrphanPolicy(100);

        self::assertSame(
            ['orphans' => [], 'skipped' => 0],
            $policy->select(
                [['key' => 'db/cataloged.zip', 'size' => 1, 'lastModified' => 1]],
                ['db/cataloged.zip'],
                1000,
            ),
        );
    }

    public function testObjectExactlyAtThresholdIsOrphan(): void
    {
        $policy = new OrphanPolicy(100);

        self::assertSame(
            ['orphans' => ['db/boundary.zip'], 'skipped' => 0],
            $policy->select(
                [['key' => 'db/boundary.zip', 'size' => 1, 'lastModified' => 900]],
                [],
                1000,
            ),
        );
    }

    public function testZeroMinimumAgeSelectsAllUncatalogedObjects(): void
    {
        $policy = new OrphanPolicy(0);

        self::assertSame(
            ['orphans' => ['db/one.zip', 'db/two.zip'], 'skipped' => 0],
            $policy->select(
                [
                    ['key' => 'db/one.zip', 'size' => 1, 'lastModified' => 1],
                    ['key' => 'db/two.zip', 'size' => 1, 'lastModified' => 1000],
                    ['key' => 'db/cataloged.zip', 'size' => 1, 'lastModified' => 1],
                ],
                ['db/cataloged.zip'],
                1000,
            ),
        );
    }

    public function testEmptyInputReturnsEmptySelection(): void
    {
        $policy = new OrphanPolicy(100);

        self::assertSame(
            ['orphans' => [], 'skipped' => 0],
            $policy->select([], [], 1000),
        );
    }

    public function testMixedObjectsAreSelectedInListingOrder(): void
    {
        $policy = new OrphanPolicy(100);

        self::assertSame(
            ['orphans' => ['db/old-one.zip', 'db/old-two.zip'], 'skipped' => 1],
            $policy->select(
                [
                    ['key' => 'db/old-one.zip', 'size' => 1, 'lastModified' => 899],
                    ['key' => 'db/in-flight.zip', 'size' => 1, 'lastModified' => 901],
                    ['key' => 'db/cataloged.zip', 'size' => 1, 'lastModified' => 1],
                    ['key' => 'db/old-two.zip', 'size' => 1, 'lastModified' => 900],
                ],
                ['db/cataloged.zip'],
                1000,
            ),
        );
    }

    public function testNegativeMinimumAgeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OrphanPolicy(-1);
    }

    public function testMissingLastModifiedIsSkipped(): void
    {
        $policy = new OrphanPolicy(100);

        self::assertSame(
            ['orphans' => [], 'skipped' => 1],
            $policy->select(
                [['key' => 'db/x.zip', 'size' => 1]],
                [],
                1000,
            ),
        );
    }
}
