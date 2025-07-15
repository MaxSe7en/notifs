<?php
namespace App\Services;

use App\Exceptions\Console;

class RedisService
{
    private RedisWrapper $redis;

    public function __construct()
    {
        $this->redis = new RedisWrapper();
    }

    public function setUserFd(string $userId, int $fd): void
    {
        // First remove any existing mapping for this user
        $existingFd = $this->redis->hGet(RedisWrapper::FD_USER_MAP, $userId);
        if ($existingFd) {
            $this->redis->hDel(RedisWrapper::USER_FD, [$existingFd]);
        }
        if (json_decode($userId, true) !== null) {
            Console::warn("Attempted to store JSON-encoded userId: {$userId}. Converting to string.");
            $userId = (string) $userId;
        }
        Console::debug("Storing userId: {$userId} for FD: {$fd} in ws:user_fd");
        $this->redis->hSet(RedisWrapper::FD_USER_MAP, $userId, $fd);
        $this->redis->hSet(RedisWrapper::USER_FD, $fd, $userId);
    }


    public function getUserFd(string $userId): ?int
    {
        $fd = $this->redis->hGet(RedisWrapper::USER_FD, $userId);
        $uId = json_encode($userId);
        $_fd = json_encode($fd);
        Console::debug("Fetching FD for user ID: {$uId} found in Redis: " . ($_fd === null ? 'null' : $_fd));
        if ($fd === null) {
            Console::log("No FD found for user ID {$userId}, cannot send notification.");
            return null;
        }
        $decoded = json_decode($fd, true);
        if (is_array($decoded)) {
            Console::warn("Unexpected JSON array for user ID {$userId}: {$fd}. Using first value.");
            return is_numeric($decoded[0]) ? (int) $decoded[0] : null;
        }
        return is_numeric($fd) ? (int) $fd : null;
    }

    public function getUserIdByFd(int $fd): ?string
    {
        $userId = $this->redis->hGet(RedisWrapper::USER_FD, $fd);
        Console::debug("Fetching user ID for FD: {$fd} found in Redis: " . ($userId === null ? 'null' : $userId));
        if ($userId === null) {
            return null;
        }
        $decoded = json_decode($userId, true);
        if (is_array($decoded) && !empty($decoded)) {
            Console::warn("Unexpected JSON array for FD {$fd}: {$userId}. Using first value.");
            return (string) $decoded[0];
        }
        return is_string($userId) ? $userId : null;
    }

    public function removeUser(string $userId, int $fd): void
    {
        $this->redis->hDel(RedisWrapper::FD_USER_MAP, [$userId ?: '']);
        $this->redis->hDel(RedisWrapper::USER_FD, [$fd]);
    }

    public function removeUserByFd(int $fd): void
    {
        $this->redis->hDel(RedisWrapper::USER_FD, [$fd]);
    }

    public function removeUserByUserId(int $userId): void
    {
        $this->redis->hDel(RedisWrapper::FD_USER_MAP, [(string) $userId]);
    }

    public function getAllUserFds(): array
    {
        return $this->redis->hGetAll(RedisWrapper::FD_USER_MAP);
    }

    public function getAllUserFdsKeys(): array
    {
        return array_keys($this->redis->hGetAll(RedisWrapper::USER_FD));
    }

    public function getAllFdsPairUserKeys(): array
    {
        return array_keys($this->redis->hGetAll(RedisWrapper::FD_USER_MAP));
    }

    public function cleanupUserConnections(string $userId): void
    {
        $fds = $this->redis->hGet(RedisWrapper::FD_USER_MAP, $userId);

        if (is_array($fds)) {
            foreach ($fds as $fd) {
                $this->redis->hDel(RedisWrapper::USER_FD, [$fd]);
            }
            $this->redis->hDel(RedisWrapper::FD_USER_MAP, [$userId]);
        } elseif (is_string($fds)) {
            $this->redis->hDel(RedisWrapper::USER_FD, [$fds]);
            $this->redis->hDel(RedisWrapper::FD_USER_MAP, [$userId]);
        }
    }

    public function getNotificationList(string $userId): array
    {
        return $this->redis->lRange("notifications:{$userId}", 0, -1);
    }

    public function deleteNotificationList(string $userId): void
    {
        $this->redis->del("notifications:{$userId}");
    }

    public function addNotification(string $userId, array $notification): void
    {
        $this->redis->rPush("notifications:{$userId}", json_encode($notification));
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