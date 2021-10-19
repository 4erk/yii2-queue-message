<?php

namespace yii\queue_message;

use Yii;
use yii\base\Exception;
use yii\di\Instance;
use yii\helpers\FileHelper;
use yii\mutex\Mutex;

class QueueMessageFile extends QueueMessage
{

    /**
     * @var Mutex|array|string
     */
    public $mutex = 'mutex';
    /**
     * @var int timeout
     */
    public $mutexTimeout = 3;
    /**
     * @var string
     */
    public $path = '@runtime/queue-message';

    public $dirMode = 0775;
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

    private $_headers = [];

    private $_headersFiles = [];

    private $_messagesFiles = [];


    /**
     * @throws Exception
     */
    public function init()
    {
        parent::init();
        $this->path = Yii::getAlias($this->path);
        if (!is_dir($this->path)) {
            FileHelper::createDirectory($this->path, $this->dirMode, true);
        }
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
        $payload = [
            'channel'   => $channel,
            'message'   => $this->serialize($message),
            'delay'     => isset($delay) ? $delay : $this->delay,
            'priority'  => isset($priority) ? $priority : $this->priority,
            'ttr'       => isset($ttr) ? $ttr : $this->ttr,
            'pushed_at' => microtime(true),
        ];
        if (!$this->mutex->acquire(__CLASS__ . $channel, $this->mutexTimeout)) {
            throw new Exception('Has not waited the lock.');
        }
        try {
            $this->loadHeader($channel);
            $this->clearExpired();
            $result = $this->pushMessage($payload);

        } finally {
            $this->mutex->release(__CLASS__ . $channel);
        }

        return $result;
    }


    /**
     * @param string $channel
     * @return mixed
     * @throws Exception
     */
    public function receive($channel)
    {
        if (!$this->mutex->acquire(__CLASS__ . $channel, $this->mutexTimeout)) {
            throw new Exception('Has not waited the lock.');
        }
        try {
            $this->loadHeader($channel);
            $this->clearExpired();
            $result = $this->readMessage($channel);
        } finally {
            $this->mutex->release(__CLASS__ . $channel);
        }

        return $result;
    }


    /**
     * @param string $channel
     * @throws Exception
     */
    public function clear($channel)
    {
        if (!$this->mutex->acquire(__CLASS__ . $channel, $this->mutexTimeout)) {
            throw new Exception('Has not waited the lock.');
        }
        try {
            unset($this->_headersFiles[$channel], $this->_messagesFiles[$channel]);
            $filePath = $this->getHeaderFilePath($channel);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
            $filePath = $this->getMessageFilePath($channel);
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        } finally {
            $this->mutex->release(__CLASS__ . $channel);
        }
    }

    /**
     * @param $channel
     */
    private function loadHeader($channel)
    {
        $file = $this->getHeaderFile($channel);
        $size = $this->getHeaderSize($channel);
        if ($size > 0) {
            fseek($file, 4);
            $data    = fread($file, $size);
            $headers = $this->unserialize($data);

            $this->_headers[$channel] = $this->unpackHeaders($headers);
        }
        else {
            $this->_headers[$channel] = [];
        }
    }

    private function getHeaderFile($channel)
    {
        $filePath = $this->getHeaderFilePath($channel);
        if (!file_exists($filePath)) {
            touch($filePath);
        }
        $this->_headersFiles[$channel] = $this->_headersFiles[$channel] ?? fopen($filePath, 'rb+');
        return $this->_headersFiles[$channel];
    }

    /**
     * @param $channel
     * @return string
     */
    private function getHeaderFilePath($channel)
    {
        return $this->path . DIRECTORY_SEPARATOR . md5($channel) . '.map';
    }

    private function getHeaderSize($channel)
    {
        $file = $this->getHeaderFile($channel);
        fseek($file, 0);
        $data = fread($file, 4);
        return $this->str2int($data);
    }

    /**
     * @param string $str
     * @return int
     */
    private function str2int($str): int
    {
        $int = 0;
        for ($i = 0; $i < 4; $i++) {
            $int |= ord($str[$i] ?? chr(0)) << ($i * 8);
        }
        return $int;
    }


    private function unpackHeaders($headers)
    {
        $result = [];
        foreach ($headers as $header) {
            $result[] = [
                'pushed_at' => $header[0],
                'delay'     => $header[1],
                'priority'  => $header[2],
                'ttr'       => $header[3],
                'length'    => $header[4],
                'offset'    => $header[5],
            ];
        }
        return $result;
    }

    private function clearExpired()
    {
        foreach ($this->_headers as $channel => $headers) {
            $this->_headers[$channel] = array_filter($headers, static function ($item) {
                return $item['pushed_at'] >= time() - $item['delay'] - $item['ttr'];
            });
        }
    }

    /**
     * @param $payload
     * @return int
     */
    private function pushMessage($payload)
    {
        $payload['length']                     = strlen($payload['message']);
        $payload['offset']                     = $this->findOffset($payload['channel'], $payload['length']);
        $this->_headers[$payload['channel']][] = $payload;
        $this->saveMessage($payload);
        $this->saveHeaders($payload['channel']);
        return count($this->_headers);
    }

    /**
     * @param string $channel
     * @param int    $length
     * @return int|mixed
     */
    private function findOffset($channel, $length)
    {
        $headers = $this->_headers[$channel];
        usort($headers, static function ($a, $b) {
            return $a['offset'] <=> $b['offset'];
        });
        $offset = 0;
        foreach ($headers as $header) {
            $size = $header['offset'] - $offset;
            if ($size >= $length) {
                return $offset;
            }
            $offset = $header['offset'] + $header['length'];
        }
        return $offset;
    }

    /**
     * @param $payload
     */
    private function saveMessage($payload)
    {
        $file = $this->getMessageFile($payload['channel']);
        fseek($file, $payload['offset']);
        fwrite($file, $payload['message']);
    }

    private function getMessageFile($channel)
    {
        $filePath = $this->getMessageFilePath($channel);
        if (!file_exists($filePath)) {
            touch($filePath);
        }
        $this->_messagesFiles[$channel] = $this->_messagesFiles[$channel] ?? fopen($filePath, 'rb+');
        return $this->_messagesFiles[$channel];

    }

    private function getMessageFilePath($channel)
    {
        return $this->path . DIRECTORY_SEPARATOR . md5($channel) . '.bin';
    }

    private function saveHeaders($channel)
    {
        $file     = $this->getHeaderFile($channel);
        $headers  = $this->packHeaders($channel);
        $data     = $this->serialize($headers);
        $size     = strlen($data);
        $sizeByte = $this->int2str($size);
        fseek($file, 0);
        fwrite($file, $sizeByte);
        fwrite($file, $data);
        unset($this->_headers[$channel]);
    }

    private function packHeaders($channel)
    {
        $result = [];
        foreach ($this->_headers[$channel] as $header) {
            $result[] = [
                $header['pushed_at'],
                $header['delay'],
                $header['priority'],
                $header['ttr'],
                $header['length'],
                $header['offset'],
            ];
        }
        return $result;
    }

    /**
     * @param $int
     * @return string
     */
    private function int2str($int): string
    {
        $str = '';
        for ($i = 0; $i < 4; $i++) {
            $str .= chr($int >> ($i * 8) & 255);
        }
        return $str;
    }


    /**
     * @param $channel
     * @return mixed
     */
    private function readMessage($channel)
    {
        $payload = $this->getFirstHeader($channel);
        if (!is_null($payload)) {
            $data    = $this->readMessageFromFile($channel, $payload['offset'], $payload['length']);
            $message = $this->unserialize($data);
        }
        else {
            $message = null;
        }
        $this->saveHeaders($channel);
        return $message;
    }

    /**
     * @return array|null
     */
    private function getFirstHeader($channel)
    {

        usort($this->_headers[$channel], static function ($a, $b) {
            $sort = $a['priority'] <=> $b['priority'];
            if (!$sort) {
                $sort = $a['pushed_at'] + $a['delay'] <=> $b['pushed_at'] + $b['delay'];
            }
            return $sort;
        });

        foreach ($this->_headers[$channel] as $key => $header) {
            if ($header['pushed_at'] <= time() - $header['delay']) {
                unset($this->_headers[$channel][$key]);
                return $header;
            }
        }
        return null;
    }

    /**
     * @param $channel
     * @param $offset
     * @param $length
     * @return false|string
     */
    private function readMessageFromFile($channel, $offset, $length)
    {
        $file = $this->getMessageFile($channel);
        fseek($file, $offset);
        return fread($file, $length);
    }


}