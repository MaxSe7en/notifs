<?php
require 'vendor/autoload.php';
use Predis\Client;

$redis = new Client([
    'scheme' => 'tcp',
    'host' => '127.0.0.1',
    'port' => 6379,
    'timeout' => 5.0, // Connection timeout
    'read_write_timeout' => 0, // Disable read/write timeout for pub/sub
    'persistent' => true, // Use persistent connections
]);
$pubSub = $redis->pubSubLoop();
$pubSub->subscribe('test_channel');

foreach ($pubSub as $message) {
    if ($message->kind === 'message') {
        echo "Received: {$message->payload} on {$message->channel}\n";
    }
}