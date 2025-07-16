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

// private function initializeClient(): void
// {
//     $config = [
//         'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
//         'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
//         'port' => (int) (getenv('REDIS_PORT') ?: 6379),
//         'password' => getenv('REDIS_PASSWORD') ?: null,
//         'read_write_timeout' => 0,
//         'cluster' => filter_var(getenv('REDIS_CLUSTER') ?: false, FILTER_VALIDATE_BOOLEAN),
//     ];

//     try {
//         if ($config['cluster']) {
//             $this->redisClient = new Client([
//                 'cluster' => 'redis',
//                 'parameters' => ['password' => $config['password']],
//                 'nodes' => [$config['scheme'] . '://' . $config['host'] . ':' . $config['port']],
//             ]);
//         } else {
//             $this->redisClient = new Client([
//                 'scheme' => $config['scheme'],
//                 'host' => $config['host'],
//                 'port' => $config['port'],
//                 'password' => $config['password'],
//                 'read_write_timeout' => $config['read_write_timeout'],
//             ]);
//         }
//         $this->redisClient->ping(); // Test connection
//         Console::info("Connected to Redis ✅");
//     } catch (\Exception $e) {
//         Console::error("Redis connection failed: " . $e->getMessage());
//         throw $e;
//     }
// }
$pubSub = $redis->pubSubLoop();
$pubSub->subscribe('test_channel');

foreach ($pubSub as $message) {
    if ($message->kind === 'message') {
        echo "Received: {$message->payload} on {$message->channel}\n";
    }
}