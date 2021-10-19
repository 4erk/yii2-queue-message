<?php

namespace yii\queue_message;

use yii\base\Component;

abstract class QueueMessage extends Component
{
    /**
     * @param string  $channel
     * @param mixed   $message
     * @param integer $delay
     * @param integer $priority
     * @param integer $ttr
     * @return mixed
     */
    abstract public function send($channel, $message, $delay = null, $priority = null, $ttr = null);

    /**
     * @param string $channel
     * @return mixed
     */
    abstract public function receive($channel);


    /**
     * @param string $channel
     */
    abstract public function clear($channel);


    /**
     * @param mixed $data
     * @return string
     */
    protected function serialize($data)
    {
        return serialize($data);
    }


    /**
     * @param string $data
     * @return mixed
     */
    protected function unserialize(string $data)
    {
        return unserialize($data);
    }
}