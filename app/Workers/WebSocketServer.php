<?php
namespace App\Workers;

use App\Config\DatabaseAccessors;
use App\Exceptions\Console;
use App\Services\RedisWrapper;
use Swoole\Process;
use Swoole\WebSocket\Server;
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\Timer;
use App\Services\RedisService;
use App\Models\NotificationModel;

class WebSocketServer
{
    private Server $server;
    private RedisService $redis;
    private NotificationModel $notificationModel;
    private array $config;
    private array $config2;
    private array $heartbeatTimers = [];
    private string $serverId;

    public function __construct(array $config = [])
    {
        $this->serverId = gethostname() . ':' . ($config['port'] ?? 9502);
        $this->config2 = [
            'host' => '0.0.0.0',
            'port' => 9502
        ];

        $this->config = array_merge([
            'worker_num' => 1,//swoole_cpu_num(),
            'task_worker_num' => 4,//swoole_cpu_num() * 2,
            'enable_coroutine' => true,
            'max_connection' => 1024,
            'dispatch_mode' => 2,
            'heartbeat_idle_time' => 180,
            'heartbeat_check_interval' => 60,
            'ssl_cert_file' => $this->getSSLCertPath(),
            'ssl_key_file' => $this->getSSLKeyPath(),
            'ssl_ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256',
            'ssl_prefer_server_ciphers' => true,
            'open_http2_protocol' => false,
            'buffer_output_size' => 8 * 1024 * 1024,
            'socket_buffer_size' => 32 * 1024 * 1024,
            'package_max_length' => 8 * 1024 * 1024,
            'reload_async' => true,
            'max_wait_time' => 60,
            'tcp_fastopen' => true,
            'open_tcp_nodelay' => true,
            'open_cpu_affinity' => true,
            'max_request' => 0,
            'enable_reuse_port' => true,
            'backlog' => 128,
        ], $config);

        $this->redis = new RedisService();
        $this->notificationModel = new NotificationModel();
    }

    private function getSSLCertPath(): ?string
    {
        $certPath = '/etc/letsencrypt/live/winsstarts.com/fullchain.pem';
        return (file_exists($certPath) && is_readable($certPath)) ? $certPath : null;
    }

    private function getSSLKeyPath(): ?string
    {
        $keyPath = '/etc/letsencrypt/live/winsstarts.com/privkey.pem';
        return (file_exists($keyPath) && is_readable($keyPath)) ? $keyPath : null;
    }

    public function start()
    {
        $host = $this->config['host'] ?? '0.0.0.0';
        $port = $this->config['port'] ?? 9502;

        $useSSL = $this->config['ssl_cert_file'] && $this->config['ssl_key_file'];

        if ($useSSL) {
            //Console::info("Starting WebSocket server with SSL");
            $this->server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
        } else {
            //Console::warn("Starting WebSocket server without SSL (certificates not found)");
            $this->server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
            unset(
                $this->config['ssl_cert_file'],
                $this->config['ssl_key_file'],
                $this->config['ssl_ciphers'],
                $this->config['ssl_prefer_server_ciphers']
            );
        }

        $serverConfig = $this->config;
        unset($serverConfig['host'], $serverConfig['port']);

        $this->server->set($serverConfig);

        $this->server->addProcess($this->getRedisSubscriberProcess());

        $this->registerEventHandlers();

        $this->server->start();
    }

    private function registerEventHandlers(): void
    {
        $this->server->on('start', [$this, 'onStart']);
        $this->server->on('workerStart', [$this, 'onWorkerStart']);
        $this->server->on('open', [$this, 'onOpen']);
        $this->server->on('message', [$this, 'onMessage']);
        $this->server->on('close', [$this, 'onClose']);
        $this->server->on('task', [$this, 'onTask']);
        $this->server->on('finish', [$this, 'onFinish']);
    }

    public function onStart(Server $server)
    {
        echo "WebSocket Server started at ws://{$server->host}:{$server->port}\n";
    }

    public function onWorkerStart(Server $server, int $workerId)
    {
        echo "Worker {$workerId} started\n";
        if ($workerId < $this->config['worker_num']) {
            Timer::tick(15000, function () use ($server) {
                $server->task(['type' => 'process_pending_db_notifications']);
            });
        }
    }

    public function onOpen(Server $server, Request $request)
    {
        $fd = $request->fd;

        $userId = $this->validateAndGetUserId($request);
        if (!$userId) {
            $server->disconnect($fd, 4000, "Missing userId");
            return;
        }

        $existingUserId = $this->redis->getUserIdByFd($fd);
        if ($existingUserId) {
            //Console::warn("FD {$fd} reused, overwriting old userId: {$existingUserId}");
            $this->redis->removeUserByFd($fd);
        }
        $this->redis->cleanupUserConnections($userId);
        echo "User {$userId} connected with FD {$fd}\n";
        $this->setUserFd($userId, $fd);

        $this->heartbeatTimers[$fd] = Timer::after(300_000, function () use ($fd, $userId, $server) {
            if ($server->isEstablished($fd)) {
                $server->disconnect($fd, 4001, "Inactive too long");
                echo "[Inactivity] Auto-closed FD {$fd} (user {$userId}) due to timeout\n";
                $this->redis->removeUser($userId, $fd);
            }
        });
        $this->sendInitialData($userId, $fd);
    }

    public function onMessage(Server $server, Frame $frame)
    {
        $data = json_decode($frame->data, true);
        if (!$data) {
            throw new \InvalidArgumentException("Invalid JSON format");
        }
        echo "Received message from FD {$frame->fd}: {$frame->data}\n";
        if (isset($this->heartbeatTimers[$frame->fd])) {
            Timer::clear($this->heartbeatTimers[$frame->fd]);
        }
        $userId = $this->redis->getUserIdByFd($frame->fd);
        if (!$userId) {
            echo "User ID not found for FD {$frame->fd}, disconnecting\n";
            $server->disconnect($frame->fd, 4002, "User not found");
            $this->redis->removeUserByFd($frame->fd);
            return;
        }

        $this->handleMessage($data, $frame->fd, $userId);
    }

    public function onClose(Server $server, int $fd)
    {
        $userId = $this->redis->getUserIdByFd($fd);
        if ($userId) {
            $this->redis->removeUserByFd($fd);
            $this->redis->cleanupUserConnections($userId);
            echo "FD {$fd} (user {$userId}) disconnected\n";
        } else {
            echo "FD {$fd} disconnected but user not found\n";
        }
    }

    public function onTask(Server $server, int $taskId, int $fromId, $data)
    {
        echo "Task {$taskId} received from worker {$fromId} with data: " . json_encode($data) . "\n";
        try {
            switch ($data['type'] ?? '') {
                case 'process_queued_notifications':
                    //Console::info("Processing queued notifications");
                    $this->processQueuedNotifications();
                    break;

                case 'process_pending_db_notifications':
                    //Console::info("Processing pending notifications from database");
                    $this->processPendingNotifications();
                    break;

                case 'send_notification':
                    //Console::info("Sending notification for user {$data['user_id']}");
                    $message = trim($data['message'] ?? '');
                    if ($message !== '') {
                        $this->sendDirectNotification(
                            $data['user_id'],
                            $message,
                            $data['event'] ?? 'notification'
                        );
                    }
                    break;

                case 'mark_notification_read':
                    if (isset($data['user_id'], $data['notification_id'])) {
                        //Console::info("Marking notification {$data['notification_id']} as read for user {$data['user_id']}");
                        $this->notificationModel->markAsRead((int) $data['notification_id'], (int) $data['user_id']);
                    }
                    break;

                default:
                    Console::warn("Unknown task type: " . ($data['type'] ?? 'none'));
            }
        } catch (\Exception $e) {
            Console::error("Task {$taskId} error: " . $e->getMessage());
        }
    }

    public function onFinish(Server $server, int $taskId, $data)
    {
        echo "Task {$taskId} finished with data: " . json_encode($data) . "\n";
    }

    public function setUserFd(string $userId, int $newFd): void
    {
        $existingFd = $this->redis->getUserFd($userId);

        if ($existingFd) {
            if ($this->isConnectionActive($existingFd)) {
                try {
                    $this->server->disconnect($existingFd, 4003, "New connection established");
                    //Console::info("Disconnected old FD {$existingFd} for user {$userId}");
                } catch (\Exception $e) {
                    //Console::warn("Failed to disconnect old FD {$existingFd}: " . $e->getMessage());
                }
            }
            $this->redis->removeUserByFd($existingFd);
        }

        $this->redis->setUserFd($userId, $newFd);
        //Console::info("Established new connection FD {$newFd} for user {$userId}");
    }

    private function checkAndSendNewNotifications()
    {
        // Note: This method is commented out in the original code
        // Since getAllUserFds is not supported, this would need a different implementation
        //Console::warn("checkAndSendNewNotifications not supported with non-hash Redis");
    }

    public function getRedisSubscriberProcess(): Process
    {
        return new Process(function (Process $process) {
            //Console::info("[Redis Process] Starting Redis subscriber process");

            $redis = new RedisWrapper();
            $server = $this->server; // Pass the server instance explicitly

            // Wrap subscription in a retry loop
            while (true) {
                try {
                    $redis->subscribe([RedisWrapper::QUEUE_PREFIX], function ($redisClient, $channel, $message) use ($server) {
                        //Console::info("[Redis] Message from channel {$channel}: {$message}");

                        $payload = json_decode($message, true);

                        if (!$payload || !isset($payload['userId'], $payload['message'])) {
                            //Console::error("[Redis] Invalid message format");
                            return;
                        }

                        $userId = $payload['userId'];
                        $msg = $payload['message'];

                        $fd = $this->redis->getUserFd($userId);

                        if ($fd && $server->isEstablished((int) $fd)) {
                            $server->push((int) $fd, $msg);
                            //Console::info("[WebSocket] Pushed to FD {$fd}");
                        } else {
                            //Console::warn("[WebSocket] FD for user {$userId} not connected");
                        }
                    });
                } catch (\Exception $e) {
                    Console::error("[Redis Process] Subscribe failed: " . $e->getMessage());
                    //Console::warn("[Redis Process] Reconnecting in 5 seconds...");
                    sleep(5); // Wait before retrying
                    $redis = new RedisWrapper(); // Reinitialize Redis client
                }
            }
        }, false, 0, true); // Enable coroutine support for the process
    }

    private function handleMessage(array $data, int $fd, int $userId): void
    {
        //Console::log("Received message from user {$userId}: " . json_encode($data));
        switch ($data['action'] ?? '') {
            case 'ping':
                $this->server->push($fd, json_encode([
                    'type' => 'pong',
                    'timestamp' => time()
                ]));
                break;

            case 'pong':
                break;

            case 'get_notifications':
                $counts = $this->notificationModel->getNotificationCounts((string) $userId);
                $this->server->push($fd, json_encode([
                    'type' => 'notification_count',
                    'data' => $counts
                ]));
                break;

            case 'send_notification':
                $this->sendDirectNotification(
                    $data['user_id'],
                    $data['message'] ?? '',
                    $data['event'] ?? 'notification'
                );
                break;

            case 'mark_read':
                if (isset($data['notification_id'])) {
                    $this->server->task([
                        'type' => 'mark_notification_read',
                        'user_id' => $userId,
                        'notification_id' => $data['notification_id']
                    ]);
                }
                break;

            default:
                Console::warn("Unknown action: " . ($data['action'] ?? 'none'));
        }
    }

    private function sendDirectNotification(int $userId, string $message, string $event = 'notification'): bool
    {
        $fd = $this->redis->getUserFd((string) $userId);
        //Console::debug("Fetching FD in sendDirectNotification fxn for user ID: {$userId} found in Redis " . json_encode($fd));
        if (!$fd) {
            //Console::warn("No FD found for user ID {$userId}, cannot send notification.");
            if (!empty($message)) {
                $this->redis->addNotification((string) $userId, [
                    'user_id' => $userId,
                    'message' => $message,
                    'event' => $event,
                    'timestamp' => time()
                ]);
                //Console::info("User {$userId} not connected, notification queued.");
            }
            return false;
        }
        if (!$this->isValidConnection($fd)) {
            if (!empty($message)) {
                $this->redis->addNotification((string) $userId, [
                    'user_id' => $userId,
                    'message' => $message,
                    'event' => $event,
                    'timestamp' => time()
                ]);
                //Console::info("User {$userId} not connected, notification queued.");
            }
            return false;
        }

        try {
            $counts = $this->notificationModel->getNotificationCountsOnly((string) $userId);
            $payload = json_encode([
                'type' => 'notification',
                'event' => $event,
                'message' => $message,
                'count' => $counts,
                'timestamp' => time()
            ]);

            $pushed = $this->server->push($fd, $payload);
            if ($pushed) {
                //Console::info("Sent direct notification to User {$userId} (fd: {$fd}).");
            } else {
                //Console::warn("Failed to push notification to fd {$fd} for User {$userId}.");
            }
            return $pushed;
        } catch (\Exception $e) {
            Console::error("Error pushing notification to fd {$fd} for User {$userId}: " . $e->getMessage());
            return false;
        }
    }

    private function processPendingNotifications(): void
    {
        try {
            $pending = $this->notificationModel->getPendingNotifications();
            if (empty($pending)) {
                return;
            }
            //Console::info("Processing " . count($pending) . " pending notifications from database." . " found in DB: " . json_encode($pending));

            foreach ($pending as $notification) {
                if (empty($notification['user_id'])) {
                    //Console::warn("Skipping notification with missing user_id: " . json_encode($notification));
                    continue;
                }
                if (empty($notification['message'])) {
                    //Console::warn("Skipping notification with missing message for user {$notification['user_id']}");
                    continue;
                }

                $userId = (int) $notification['user_id'];
                $message = $notification['message'];
                $event = $notification['n_event'] ?? 'notification';

                $sent = $this->sendDirectNotification(
                    $userId,
                    $message,
                    $event
                );

                DatabaseAccessors::update(
                    "UPDATE notifications SET status = 'sent' WHERE id = ?",
                    [$notification['id']]
                );
                if ($sent) {
                    //Console::info("Processed and sent DB notification ID {$notification['id']} to user {$userId}.");
                } else {
                    //Console::info("Processed DB notification ID {$notification['id']} for user {$userId}, queued for later.");
                }
            }
        } catch (\Exception $e) {
            Console::error("Error processing pending notifications from database: " . $e->getMessage());
        }
    }

    private function sendInitialData(int $userId, int $fd): void
    {
        try {
            $this->server->push($fd, json_encode([
                'type' => 'connection',
                'status' => 'connected',
                'message' => 'WebSocket connection established',
                'connection_id' => $fd
            ]));

            $counts = $this->notificationModel->getNotificationCounts((string) $userId);
            $this->server->push($fd, json_encode([
                'type' => 'notification_count',
                'data' => $counts
            ]));
        } catch (\Exception $e) {
            Console::error("Initial data error: " . $e->getMessage());
        }
    }

    private function processQueuedNotifications(): void
    {
        // Note: This method assumes notifications are stored per user
        // Since getAllUserFds is not supported, you might need to maintain a set of user IDs
        //Console::warn("processQueuedNotifications not fully supported without user enumeration");

    }

    private function validateAndGetUserId($request): int
    {
        parse_str($request->server['query_string'] ?? '', $query);
        if (!isset($query['userId']) || !is_numeric($query['userId'])) {
            throw new \InvalidArgumentException(message: "Invalid user ID");
        }

        $userId = (int) $query['userId'];
        //Console::info("Validating user ID: {$userId}");
        return $userId;
    }

    private function isValidConnection(int $fd): bool
    {
        return $this->server->isEstablished($fd);
        // return isset($this->heartbeatTimers[$fd]) && $this->server->isEstablished($fd)
        //     && Timer::exists($this->heartbeatTimers[$fd]) && $this->redis->getUserIdByFd($fd) !== null;
    }

    private function isConnectionActive(int $fd): bool
    {
        try {
            return $this->server->isEstablished($fd) &&
                isset($this->heartbeatTimers[$fd]) &&
                Timer::exists($this->heartbeatTimers[$fd]);
        } catch (\Exception $e) {
            return false;
        }
    }
}
