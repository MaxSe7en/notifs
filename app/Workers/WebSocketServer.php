<?php
namespace App\Workers;

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

        // Timer to check new notifications every 5 seconds
        Timer::tick(5000, function () {
            $this->checkAndSendNewNotifications();
        });
    }

    public function onWorkerStart(Server $server, int $workerId)
    {
        echo "Worker {$workerId} started\n";
        // Initialize any worker-specific resources here
    }

    public function onOpen(Server $server, Request $request)
    {
        $fd = $request->fd;
        $userId = $request->get['userId'] ?? null;

        if (!$userId) {
            $server->disconnect($fd, 4000, "Missing userId");
            return;
        }

        echo "User {$userId} connected with FD {$fd}\n";
        $this->redis->setUserFd($userId, $fd);
    }

    public function onMessage(Server $server, Frame $frame)
    {
        // Handle incoming messages from clients if needed
        echo "Received message from FD {$frame->fd}: {$frame->data}\n";
    }

    public function onClose(Server $server, int $fd)
    {
        $userId = $this->redis->getUserIdByFd($fd);
        if ($userId) {
            $this->redis->removeUser($userId, $fd);
            echo "FD {$fd} (user {$userId}) disconnected\n";
        } else {
            echo "FD {$fd} disconnected but user not found\n";
        }
    }

    public function onTask(Server $server, int $taskId, int $fromId, $data)
    {
        // Handle background tasks if needed
        echo "Task {$taskId} received from worker {$fromId}\n";
    }

    public function onFinish(Server $server, int $taskId, $data)
    {
        // Handle task completion if needed
        echo "Task {$taskId} finished with data: " . json_encode($data) . "\n";
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

}