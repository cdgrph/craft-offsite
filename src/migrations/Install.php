<?php
declare(strict_types=1);

namespace cdgrph\offsite\migrations;

use craft\db\Migration;

final class Install extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists('{{%offsite_runs}}')) {
            return true;
        }

        $this->createTable('{{%offsite_runs}}', [
            'id' => $this->primaryKey(),
            'runId' => $this->string()->notNull()->unique(),
            'startedAt' => $this->dateTime()->notNull(),
            'backupStatus' => $this->string(16)->notNull(),
            'summaryJson' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists('{{%offsite_runs}}');
        return true;
    }
}
