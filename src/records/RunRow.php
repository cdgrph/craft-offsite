<?php
declare(strict_types=1);

namespace cdgrph\offsite\records;

use craft\db\ActiveRecord;

/**
 * Local run cache — UI display only. The remote RunCatalog is the source of truth.
 */
final class RunRow extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%offsite_runs}}';
    }
}
