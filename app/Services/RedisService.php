<?php
namespace App\Services;

class RedisService
{
    private RedisWrapper $redis;

    public function __construct()
    {
        $this->redis = new RedisWrapper();
    }

    public function setUserFd(string $userId, int $fd): void
    {
        $this->redis->hSet('fd_map', $userId, $fd);
        $this->redis->hSet('user_fd', $fd, $userId);
    }

    public function getUserFd(string $userId): ?int
    {
        return $this->redis->hGet('fd_map', $userId);
    }

    public function getUserIdByFd(int $fd): ?string
    {
        return $this->redis->hGet('user_fd', $fd);
    }

    public function removeUser(string $userId, int $fd): void
    {
        $this->redis->hDel('fd_map', [$userId]);
        $this->redis->hDel('user_fd', [$fd]);
    }

    public function getAllUserFds(): array
    {
        return $this->redis->hGetAll('fd_map');
    }

    public function getNotificationList(string $userId): array
    {
        return $this->redis->lRange("notifications:{$userId}", 0, -1);
    }

    public function deleteNotificationList(string $userId): void
    {
        $this->redis->del("notifications:{$userId}");
    }

    public function publishNotification(array $notification): void
    {
        $this->redis->publish("user_notifications", json_encode($notification));
    }
    public function subscribeToNotifications(callable $callback): void
    {
        $this->redis->subscribe(["user_notifications"], $callback);
    }

    public function getNotificationCount(string $userId): int
    {
        return $this->redis->lLen("notifications:{$userId}");
    }
}
