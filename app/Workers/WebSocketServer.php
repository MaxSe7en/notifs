<?php
namespace App\Workers;

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

    public function __construct()
    {
        $this->server = new Server("0.0.0.0", 9502);
        $this->redis = new RedisService();
        $this->notificationModel = new NotificationModel();

        $this->registerEvents();
    }

    public function start()
    {
        $this->server->start();
    }

    private function registerEvents(): void
    {
        $this->server->on('start', function () {
            echo "WebSocket Server started at ws://0.0.0.0:9502\n";

            // Timer to check new notifications every 5 seconds
            Timer::tick(5000, function () {
                $this->checkAndSendNewNotifications();
            });
        });

        $this->server->on('open', function (Server $server, Request $request) {
            $fd = $request->fd;
            $userId = $request->get['userId'] ?? null;

            if (!$userId) {
                $server->disconnect($fd, 4000, "Missing userId");
                return;
            }

            echo "User {$userId} connected with FD {$fd}\n";

            $this->redis->setUserFd($userId, $fd);

            // Get and send pending notifications from DB
            // $notifications = $this->notificationModel->getUnreadNotifications($userId);
            // foreach ($notifications as $notif) {
            //     $server->push($fd, json_encode($notif));
            //     $this->notificationModel->markAsSent($notif['id']);
            // }
        });

        $this->server->on('message', function (Server $server, Frame $frame) {
            // Handle client messages if needed
            echo "Received message from FD {$frame->fd}: {$frame->data}\n";
        });

        $this->server->on('close', function (Server $server, int $fd) {
            $userId = $this->redis->getUserIdByFd($fd);
            $this->redis->removeUser($userId, $fd);
            echo "FD {$fd} (user {$userId}) disconnected\n";
        });
    }

    private function checkAndSendNewNotifications()
    {
        $userFdMap = $this->redis->getAllUserFds(); // userId => fd

        foreach ($userFdMap as $userId => $fd) {
            if (!$this->server->isEstablished((int)$fd)) {
                continue;
            }

            $notifications = $this->notificationModel->getAllPendingNotices($userId);
            if (!empty($notifications)) {
                foreach ($notifications as $notif) {
                    $this->server->push((int)$fd, json_encode($notif));
                    $this->notificationModel->markAsSent($notif['id']);
                }
            }
        }
    }
}
