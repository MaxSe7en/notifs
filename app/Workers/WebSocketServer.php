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

        // Fixed configuration with better timeouts
        $this->config = array_merge([
            'worker_num' => swoole_cpu_num(),
            'task_worker_num' => swoole_cpu_num() * 2,
            'enable_coroutine' => true,
            'max_connection' => 1024,
            'dispatch_mode' => 2,

            // FIXED: Better heartbeat configuration
            'heartbeat_idle_time' => 180, // 3 minutes instead of 2
            'heartbeat_check_interval' => 60, // Keep at 60 seconds

            // SSL Configuration - Make optional
            'ssl_cert_file' => $this->getSSLCertPath(),
            'ssl_key_file' => $this->getSSLKeyPath(),
            // 'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
            'ssl_ciphers' => 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256',
            'ssl_prefer_server_ciphers' => true,
            'open_http2_protocol' => false, // Disable HTTP/2 for WebSocket

            // Buffer settings
            'buffer_output_size' => 8 * 1024 * 1024, // Reduced to 8MB
            'socket_buffer_size' => 32 * 1024 * 1024, // Reduced to 32MB
            'package_max_length' => 8 * 1024 * 1024,

            // Connection settings
            'reload_async' => true,
            'max_wait_time' => 60,
            'tcp_fastopen' => true,
            'open_tcp_nodelay' => true,
            'open_cpu_affinity' => true,

            // FIXED: Add these important settings
            'max_request' => 0, // Don't restart workers
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

        // Check if SSL is available
        $useSSL = $this->config['ssl_cert_file'] && $this->config['ssl_key_file'];

        if ($useSSL) {
            Console::info("Starting WebSocket server with SSL");
            $this->server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL);
        } else {
            Console::warn("Starting WebSocket server without SSL (certificates not found)");
            $this->server = new Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
            // Remove SSL configs if not using SSL
            unset(
                $this->config['ssl_cert_file'],
                $this->config['ssl_key_file'],
                $this->config['ssl_protocols'],
                $this->config['ssl_ciphers'],
                $this->config['ssl_prefer_server_ciphers']
            );
        }

        // Remove host/port from config
        $serverConfig = $this->config;
        unset($serverConfig['host'], $serverConfig['port']);

        $this->server->set($serverConfig);

        // FIXED: Add Redis subscriber process BEFORE starting the server
        $this->server->addProcess($this->getRedisSubscriberProcess());

        $this->registerEventHandlers();
        // Clear Redis hash to ensure no stale data
        Console::info("Clearing Redis hash to remove stale data" . json_encode($this->redis->getAllUserFds()));
        if (empty($this->redis->getAllFdsPairUserKeys()) || empty($this->redis->getAllUserFdsKeys())) {
            Console::info("No user FDs found in Redis, skipping cleanup");
            $this->server->start();
            return;
        }
        (new RedisWrapper())->hDel(RedisWrapper::USER_FD, $this->redis->getAllUserFdsKeys());
        (new RedisWrapper())->hDel(RedisWrapper::FD_USER_MAP, $this->redis->getAllFdsPairUserKeys());
        Console::info("Cleared ws:user_fd hash to remove stale data");
        // $this->server->start();
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

        // Timer to check new notifications every 5 seconds
        // Timer::tick(5000, function () {
        //     $this->checkAndSendNewNotifications();
        // });
    }

    public function onWorkerStart(Server $server, int $workerId)
    {
        echo "Worker {$workerId} started\n";
        // Console::info("WebSocket Server ID: ". json_encode(gettype([])));
        // Initialize any worker-specific resources here
        if ($workerId < $this->config['worker_num']) {
            // Process pending notifications from Database (new)
            Timer::tick(15000, function () use ($server) { // e.g., every 15 seconds
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
        // Check for existing FD mapping
        $existingUserId = $this->redis->getUserIdByFd($fd);
        if ($existingUserId) {
            Console::warn("FD {$fd} reused, overwriting old userId: {$existingUserId}");
            $this->redis->removeUserByFd($fd);
        }
        $this->redis->cleanupUserConnections($userId);
        echo "User {$userId} connected with FD {$fd}\n";
        $this->setUserFd($userId, $fd);

        // 🟨 Auto-clear after 5 minutes if still idle
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
        // Handle incoming messages from clients if needed
        echo "Received message from FD {$frame->fd}: {$frame->data}\n";
        // 🔁 Reset inactivity timer
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
            // $this->redis->removeUser($userId, $fd);
            $this->redis->removeUserByFd($fd);
            $this->redis->cleanupUserConnections($userId);
            echo "FD {$fd} (user {$userId}) disconnected\n";
        } else {
            echo "FD {$fd} disconnected but user not found\n";
        }
    }

    public function onTask(Server $server, int $taskId, int $fromId, $data)
    {
        // Handle background tasks if needed
        echo "Task {$taskId} received from worker {$fromId}\n";
        try {
            switch ($data['type'] ?? '') {
                case 'process_queued_notifications':
                    // This processes the general Redis queue (`notification_queue`)
                    $this->processQueuedNotifications();
                    break;

                case 'process_pending_db_notifications':
                    // This processes notifications from the database (new)
                    $this->processPendingNotifications();
                    break;

                case 'send_notification':
                    $message = trim($data['message'] ?? '');
                    if ($message !== '') {
                        $this->sendDirectNotification(
                            $data['user_id'],
                            $message,
                            $data['event'] ?? 'notification'
                        );
                    }
                    break;

                case 'broadcast':
                    $message = trim($data['message'] ?? '');
                    if ($message !== '') {
                        // $this->broadcastNotification($message, $data['event'] ?? 'broadcast');
                    }
                    break;

                case 'mark_notification_read':
                    // Handle marking notification as read in a task worker
                    if (isset($data['user_id'], $data['notification_id'])) {
                        $this->notificationModel->markAsRead((int) $data['notification_id'], (int) $data['user_id']);
                        // Optionally, send updated count back to user
                        // $fd = $this->redisService->executeWithRetry2(function ($client) use ($data) {
                        //     return $client->hget(RedisService2::USER_CONNECTION_MAP, (string) $data['user_id']);
                        // });
                        // if ($fd && $this->server->exists((int) $fd)) {
                        //     $this->sendNotificationCount((int) $data['user_id'], (int) $fd, true);
                        // }
                    }
                    break;
            }
        } catch (\Exception $e) {
            Console::error("Task error: " . $e->getMessage());
        }
    }

    public function onFinish(Server $server, int $taskId, $data)
    {
        // Handle task completion if needed
        echo "Task {$taskId} finished with data: " . json_encode($data) . "\n";
    }


    public function setUserFd(string $userId, int $newFd): void
    {
        // Get existing FD if any
        $existingFd = $this->redis->getUserFd($userId);

        // If there's an existing connection
        if ($existingFd) {
            // Check if the existing connection is still active
            if ($this->isConnectionActive($existingFd)) {
                // Disconnect the old connection if it's still active
                try {
                    $this->server->disconnect($existingFd, 4003, "New connection established");
                    Console::info("Disconnected old FD {$existingFd} for user {$userId}");
                } catch (\Exception $e) {
                    Console::warn("Failed to disconnect old FD {$existingFd}: " . $e->getMessage());
                }
            }

            // Clean up Redis mappings for old connection
            $this->redis->removeUserByFd( $existingFd);
        }
        $this->redis->setUserFd(1, 3);

        // Set new mappings
        // $this->redis->hSet(RedisWrapper::FD_USER_MAP, $userId, $newFd);
        // $this->redis->hSet(RedisWrapper::USER_FD, $newFd, $userId);

        Console::info("Established new connection FD {$newFd} for user {$userId}");
    }

    /**
     * Check for new notifications and send them to connected users.
     * This runs every 5 seconds via a timer.
     */
    private function checkAndSendNewNotifications()
    {
        $userFdMap = $this->redis->getAllUserFds(); // userId => fd

        foreach ($userFdMap as $userId => $fd) {
            if (!$this->server->isEstablished((int) $fd)) {
                continue;
            }

            $notifications = $this->notificationModel->getAllPendingNotices($userId);
            if (!empty($notifications)) {
                foreach ($notifications as $notif) {
                    $this->server->push((int) $fd, json_encode($notif));
                    $this->notificationModel->markAsSent($notif['id']);
                }
            }
        }
    }

    public function getRedisSubscriberProcess(): Process
    {
        return new Process(function () {
            Console::info("[Redis Process] Starting Redis subscriber process");

            $redis = new RedisWrapper();

            $redis->subscribe([RedisWrapper::QUEUE_PREFIX], function ($redisClient, $channel, $message) {
                echo "[Redis] Message from channel {$channel}: {$message}\n";

                $payload = json_decode($message, true);

                if (!$payload || !isset($payload['userId'], $payload['message'])) {
                    echo "[Redis] Invalid message format\n";
                    return;
                }

                $userId = $payload['userId'];
                $msg = $payload['message'];

                $fd = (new RedisWrapper())->hGet(RedisWrapper::USER_CONNECTION_MAP, $userId);

                global $serverInstance;
                if ($fd && $serverInstance->isEstablished((int) $fd)) {
                    $serverInstance->push((int) $fd, $msg);
                    echo "[WebSocket] Pushed to FD {$fd}\n";
                } else {
                    echo "[WebSocket] FD for user {$userId} not connected\n";
                }
            });
        });
    }

    private function handleMessage(array $data, int $fd, int $userId): void
    {
        Console::log("Received message from user {$userId}: " . json_encode($data));
        switch ($data['action'] ?? '') {
            case 'ping':
                $this->server->push($fd, json_encode([
                    'type' => 'pong',
                    'timestamp' => time()
                ]));
                // $this->refreshTTL($userId, $fd);
                break;

            case 'pong':
                // Client responded to our ping
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
        Console::debug("Fetching FD in sendDirectNotification fxn for user ID: {$userId} found in Redis " . json_encode($fd));
        if (!$fd) {
            Console::warn("No FD found for user ID {$userId}, cannot send notification.");
            return false;
        }
        if (!$this->isValidConnection($fd)) {
            // User is not connected to this server or connection is stale, queue the notification
            if (!empty($message)) {
                // Queue notification to the general Redis queue for later processing
                // This queue is checked by processQueuedNotifications()
                $this->redis->addNotification($userId, [
                    'user_id' => $userId,
                    'message' => $message,
                    'event' => $event,
                    'timestamp' => time()
                ]);
                Console::info("User {$userId} not connected, notification queued.");
            }
            return false;
        }

        try {
            $payload = json_encode([
                'type' => 'notification',
                'event' => $event,
                'message' => $message,
                'timestamp' => time()
            ]);

            // Push the notification to the client's WebSocket connection
            $pushed = $this->server->push($fd, $payload);
            if ($pushed) {
                Console::info("Sent direct notification to User {$userId} (fd: {$fd}).");
            } else {
                Console::warn("Failed to push notification to fd {$fd} for User {$userId}.");
                // $this->cleanupStaleConnection($userId, $fd); // Clean up if push fails
            }
            return $pushed;
        } catch (\Exception $e) {
            Console::error("Error pushing notification to fd {$fd} for User {$userId}: " . $e->getMessage());
            // $this->cleanupStaleConnection($userId, $fd); // Clean up on push error
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
            Console::info("Processing " . count($pending) . " pending notifications from database.");

            foreach ($pending as $notification) {
                if (empty($notification['user_id'])) {
                    Console::warn("Skipping notification with missing user_id: " . json_encode($notification));
                    continue;
                }
                if (empty($notification['message'])) {
                    Console::warn("Skipping notification with missing message for user {$notification['user_id']}");
                    continue;
                }

                $userId = (int) $notification['user_id'];
                $message = $notification['message'];
                $event = $notification['n_event'] ?? 'notification'; // Assuming 'n_event' is the event field

                // Attempt to send the notification directly
                $sent = $this->sendDirectNotification(
                    $userId,
                    $message,
                    $event
                );

                // Mark as sent in the database regardless of immediate push success
                // If sendDirectNotification queued it, it's considered "handled" by the WebSocket server.
                DatabaseAccessors::update(
                    "UPDATE notifications SET status = 'sent' WHERE id = ?",
                    [$notification['id']]
                );
                if ($sent) {
                    Console::info("Processed and sent DB notification ID {$notification['id']} to user {$userId}.");
                } else {
                    Console::info("Processed DB notification ID {$notification['id']} for user {$userId}, queued for later.");
                }
            }
        } catch (\Exception $e) {
            Console::error("Error processing pending notifications from database: " . $e->getMessage());
        }
    }

    private function sendInitialData(int $userId, int $fd): void
    {
        try {
            // Send connection acknowledgement
            $this->server->push($fd, json_encode([
                'type' => 'connection',
                'status' => 'connected',
                'message' => 'WebSocket connection established',
                'connection_id' => $fd
            ]));

            // Send initial notification count
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
        // Get batch of queued notifications from the general Redis queue
        // This queue is populated by sendDirectNotification when a user is offline.
        $notifications = $this->redis->getNotificationList(3);
        if (empty($notifications) || !is_array($notifications)) {
            return;
        }

        // foreach ($notifications as $notification) {
        //     $data = json_decode($notification, true);
        //     if (!$data || empty(trim($data['message'] ?? ''))) {
        //         $client->lrem(RedisWrapper::QUEUE_PREFIX . 'notifications', 1, $notification); // remove empty/invalid
        //         continue;
        //     }

        //     $userId = $data['user_id'] ?? null;
        //     $message = $data['message'] ?? '';
        //     $event = $data['event'] ?? 'notification';
        //     $connId = $data['connection_id'] ?? null;

        //     if ($userId) {
        //         $this->sendDirectNotification($userId, $message, $event);
        //     }

        //     // Remove processed notification from the general queue
        //     $client->lrem(RedisWrapper::QUEUE_PREFIX . 'notifications', 1, $notification);
        // }
    }

    private function validateAndGetUserId($request): int
    {
        parse_str($request->server['query_string'] ?? '', $query);
        if (!isset($query['userId']) || !is_numeric($query['userId'])) {
            throw new \InvalidArgumentException(message: "Invalid user ID");
        }

        $userId = (int) $query['userId'];
        Console::info("Validating user ID: {$userId}");

        // Optional: Add additional validation (e.g., token verification)

        return $userId;
    }

    private function isValidConnection(int $fd): bool
    {
        // Check if the connection is still valid
        return isset($this->heartbeatTimers[$fd]) && $this->server->isEstablished($fd)
            && Timer::exists($this->heartbeatTimers[$fd]) && $this->redis->getUserIdByFd($fd) !== null;
    }

    private function isConnectionActive(int $fd): bool
    {
        try {
            // Check if connection exists and is established
            return $this->server->isEstablished($fd) &&
                isset($this->heartbeatTimers[$fd]) &&
                Timer::exists($this->heartbeatTimers[$fd]);
        } catch (\Exception $e) {
            return false;
        }
    }
}