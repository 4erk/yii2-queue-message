<?php

namespace yii\queue_message;

use yii\base\InvalidConfigException;
use yii\db\Connection;
use yii\db\Exception;
use yii\db\Query;
use yii\di\Instance;
use yii\mutex\Mutex;

class QueueMessageDB extends QueueMessage
{
    /**
     * @var Connection|array|string
     */
    public $db = 'db';
    /**
     * @var Mutex|array|string
     */
    public $mutex = 'mutex';
    /**
     * @var int timeout
     */
    public $mutexTimeout = 3;
    /**
     * @var string table name
     */
    public $tableName = '{{%queue_message}}';
    /**
     * @var int time to read
     */
    public $ttr = 30;
    /**
     * @var int default delay
     */
    public $delay = 0;
    /**
     * @var int default priority
     */
    public $priority = 1024;

    /**
     * @throws InvalidConfigException
     */
    public function init()
    {
        parent::init();
        $this->db    = Instance::ensure($this->db, Connection::class);
        $this->mutex = Instance::ensure($this->mutex, Mutex::class);
    }

    /**
     * @param string  $channel
     * @param mixed   $message
     * @param integer $delay
     * @param integer $priority
     * @param integer $ttr
     * @return int
     * @throws Exception
     */
    public function send($channel, $message, $delay = null, $priority = null, $ttr = null)
    {
        $this->clearExpired();
        $payload = [
            'channel'   => $channel,
            'message'   => $this->serialize($message),
            'delay'     => $delay ?? $this->delay,
            'priority'  => $priority ?? $this->priority,
            'ttr'       => $ttr ?? $this->ttr,
            'pushed_at' => time(),
        ];

        $this->db->createCommand()->insert($this->tableName, $payload)->execute();
        $tableSchema = $this->db->getTableSchema($this->tableName);
        return $tableSchema ? (int)$this->db->getLastInsertID($tableSchema->sequenceName) : 0;
    }

    /**
     * @throws Exception
     */
    public function clearExpired()
    {
        $this->db->createCommand()->delete(
            $this->tableName,
            '[[pushed_at]] <= :time - [[delay]] - [[ttr]]',
            [':time' => time()]
        )->execute();
    }


    /**
     * @param string $channel
     * @return mixed
     * @throws \yii\base\Exception
     */
    public function receive($channel)
    {
        if (!$this->mutex->acquire(__CLASS__ . $channel, $this->mutexTimeout)) {
            throw new \yii\base\Exception('Has not waited the lock.');
        }

        try {
            $this->clearExpired();
            $payload = (new Query())
                ->from($this->tableName)
                ->andWhere(['channel' => $channel])
                ->andWhere('[[pushed_at]] <= :time - [[delay]]', [':time' => time()])
                ->orderBy(['priority' => SORT_ASC, 'id' => SORT_ASC])
                ->limit(1)
                ->one($this->db);
            if ($payload) {
                $message = $this->unserialize($payload['message']);
                $this->clearRead($payload['id']);
            }
            else {
                $message = null;
            }
        } finally {
            $this->mutex->release(__CLASS__ . $channel);
        }

        return $message;
    }



    /**
     * @param $id
     * @throws Exception
     */
    protected function clearRead($id)
    {
        $this->db->createCommand()->delete(
            $this->tableName,
            ['id' => $id]
        )->execute();
    }

    /**
     * @throws Exception
     */
    public function clear($channel)
    {
        $this->db->createCommand()->delete($this->tableName, ['channel' => $channel])->execute();
    }


}