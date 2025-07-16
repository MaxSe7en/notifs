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
        $existingFd = $this->redis->get(RedisWrapper::USER_FD . $userId);
        if ($existingFd) {
            $this->redis->del(RedisWrapper::FD_USER_MAP . $existingFd);
        }
        if (json_decode($userId, true) !== null) {
            //Console::warn("Attempted to store JSON-encoded userId: {$userId}. Converting to string.");
            $userId = (string) $userId;
        }
        //Console::debug("Storing userId: {$userId} for FD: {$fd} in ws:user_fd");
        $this->redis->set(RedisWrapper::FD_USER_MAP . $fd, $userId);
        $this->redis->set(RedisWrapper::USER_FD . $userId, $fd);
    }

    public function getUserFd(string $userId): ?int
    {
        $fd = $this->redis->get(RedisWrapper::USER_FD . $userId);
        $userFdKeys = $this->redis->keys(RedisWrapper::USER_FD . '*');
        $userFdKeyValues = $this->redis->mget($userFdKeys);
        $uId = json_encode($userId);
        $_fd = json_encode($fd);
        
        //Console::debug("Fetching FD for user ID: {$uId} found in Redis: " . $_fd ." keys". json_encode($userFdKeys) ." key val" .json_encode($userFdKeyValues). " fd=>". $_fd);
        if ($fd === null) {
            //Console::log("No FD found for user ID {$userId}, cannot send notification.");
            return null;
        }
        $decoded = json_decode($fd, true);
        if (is_array($decoded)) {
            //Console::warn("Unexpected JSON array for user ID {$userId}: {$fd}. Using first value.");
            return is_numeric($decoded[0]) ? (int) $decoded[0] : null;
        }
        return is_numeric($fd) ? (int) $fd : null;
    }

    public function getUserIdByFd(int $fd): ?string
    {
        $userId = $this->redis->get(RedisWrapper::FD_USER_MAP . $fd);
        //Console::debug("Fetching user ID for FD: {$fd} found in Redis: " . ($userId === null ? 'null' : $userId));
        if ($userId === null) {
            return null;
        }
        $decoded = json_decode($userId, true);
        if (is_array($decoded) && !empty($decoded)) {
            //Console::warn("Unexpected JSON array for FD {$fd}: {$userId}. Using first value.");
            return (string) $decoded[0];
        }
        return is_string($userId) ? $userId : null;
    }

    public function removeUser(string $userId, int $fd): void
    {
        $this->redis->del(RedisWrapper::FD_USER_MAP . $fd);
        $this->redis->del(RedisWrapper::USER_FD . ($userId ?: ''));
    }

    public function removeUserByFd(int $fd): void
    {
        $this->redis->del(RedisWrapper::FD_USER_MAP . $fd);
    }

    public function removeUserByUserId(int $userId): void
    {
        $this->redis->del(RedisWrapper::USER_FD . (string) $userId);
    }

    public function getAllUserFds(): array
    {
        // Since we're not using hashes, we'll need to implement this differently
        // This would require maintaining a separate set of all FDs or scanning keys
        // For simplicity, we'll return an empty array as this functionality may need redesign
        //Console::warn("getAllUserFds not fully supported with non-hash implementation");
        return [];
    }

    public function getAllUserFdsKeys(): array
    {
        // Similar limitation as getAllUserFds
        //Console::warn("getAllUserFdsKeys not fully supported with non-hash implementation");
        return [];
    }

    public function getAllFdsPairUserKeys(): array
    {
        // Similar limitation as getAllUserFds
        //Console::warn("getAllFdsPairUserKeys not fully supported with non-hash implementation");
        return [];
    }

    public function cleanupUserConnections(string $userId): void
    {
        $fd = $this->redis->get(RedisWrapper::USER_FD . $userId);
        if ($fd) {
            $this->redis->del(RedisWrapper::FD_USER_MAP . $fd);
            $this->redis->del(RedisWrapper::USER_FD . $userId);
        }
    }

    public function getNotificationList(string $userId): array
    {
        return $this->redis->lRange(RedisWrapper::QUEUE_PREFIX . "{$userId}", 0, -1);
    }

    public function deleteNotificationList(string $userId): void
    {
        $this->redis->del(RedisWrapper::QUEUE_PREFIX . "{$userId}");
    }

    public function addNotification(string $userId, array $notification): void
    {
        $this->redis->rPush(RedisWrapper::QUEUE_PREFIX . "{$userId}", json_encode($notification));
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
        return $this->redis->lLen(RedisWrapper::QUEUE_PREFIX . "{$userId}");
    }
}