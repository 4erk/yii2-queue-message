# Yii2 queue messages
Queue message component for Yii2
##Installation
```
php composer.phar require --prefer-dist 4erk/yii2-queue-message
```
##Configurations
Configuration for `Files` driven:
`common/config/main.php`
```
'components' => [
    ...
    'messages' => [
        'class' => \yii\queue_message\QueueMessageFile::class
        'mutex' => \yii\mutex\FileMutex::class, // mutex driver
        //'path' => '@runtime/queue-message' // path for files
        //'ttr' => 30 // default time in seconds for read message from queue
        //'delay' => 0 // default delay time before read message from queue
        //'priority' => 1024 // default priority of message in queue 
    ]   
]
```
or `DataBase` driven
```
'components' => [
    ...
    'db' => [
        // Database configuration
    ],
    'mutex' => \yii\mutex\MysqlMutex::class,
    'messages' => [
        'class' => \yii\queue_message\QueueMessageDB::class
        // 'mutex' => 'mutex' // mutex driver
        // 'db' => 'db' // DB driver
    ]   
]
```
and add migrations for `console/config/main.php` 
```
'controllerMap' => [
    'migrate' => [
        'class' => \yii\console\controllers\MigrateController::class
        'migrationNamespaces' => [
            'yii\queue_messages\migrations'
        ]   
    ]
]
```
##Usage
```
// Sending message in 'test' queue channel
Yii::$app->messages->send('test','some message');
Yii::$app->messages->send('test',['some data index'=>'some data value']);
Yii::$app->messages->send('test','message with custom property',
60 /* delay 1 min */,
3600 /* time to read 1 hour */,
1 /* priority */);
// Getting message from 'test' queue channel
$message = Yii::$app->messages->receive('test'); // return null if no message
// Clearing queue channel 
Yii::$app->messages->clear('test')
```