<?php

namespace yii\queue_message\migrations;

use yii\db\Migration;

/**
 * Handles the creation of table `{{%queue_message}}`.
 */
class m211005_133531_create_queue_message_table extends Migration
{

    public $tableName = '{{%queue_message}}';

    /**
     * {@inheritdoc}
     */
    public function safeUp()
    {
        $this->createTable($this->tableName, [
            'id'        => $this->primaryKey(),
            'channel'   => $this->string(),
            'message'   => $this->binary()->notNull(),
            'ttr'       => $this->integer()->defaultValue(0)->notNull(),
            'delay'     => $this->integer()->defaultValue(0)->notNull(),
            'priority'  => $this->integer()->unsigned()->defaultValue(1024)->notNull(),
            'pushed_at' => $this->integer(),
        ]);

        $this->createIndex('channel', $this->tableName, 'channel');
        $this->createIndex('priority', $this->tableName, 'priority');
    }

    /**
     * {@inheritdoc}
     */
    public function safeDown()
    {
        $this->dropTable('{{%queue_message}}');
    }
}
