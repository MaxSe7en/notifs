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

    /**
     * Set a value in Redis with an optional expiration time.
     * @param string $key
     * @param mixed $value
     * @param int|null $expirationSeconds
     * @return bool
     */
    public function set(string $key, $value, ?int $expirationSeconds = null): bool
    {
        return $this->redis->set($key, $value, $expirationSeconds);
    }

    /**
     * Get a value from Redis.
     * @param string $key
     * @return string|null
     */
    public function get(string $key): ?string
    {
        return $this->redis->get($key);
    }

    public function setUserFdOld(string $userId, int $fd): void
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

    /**
     * Delete one or more keys from Redis.
     * @param string ...$keys
     * @return int Number of keys deleted
     */
    public function del(string ...$keys): int
    {
        return $this->redis->del(...$keys);
    }

    /**
     * Set a connection ID for an FD.
     * @param int $fd
     * @param string $connectionId
     * @return bool
     */
    public function setConnectionId(int $fd, string $connectionId): bool
    {
        return $this->redis->set(RedisWrapper::FD_USER_MAP . $fd . ':conn_id', $connectionId);
    }

    /**
     * Get the connection ID for an FD.
     * @param int $fd
     * @return string|null
     */
    public function getConnectionId(int $fd): ?string
    {
        return $this->redis->get(RedisWrapper::FD_USER_MAP . $fd . ':conn_id');
    }

    /**
     * Set FD-user mappings atomically.
     * @param string $userId
     * @param int $fd
     * @return bool
     */
    public function setUserFd(string $userId, int $fd): bool
    {
        $client = $this->redis->getClient();
        if (!$client) {
            Console::error("Redis client unavailable in setUserFd");
            return false;
        }

        try {
            $client->multi();
            $existingFd = $this->getUserFd($userId);
            if ($existingFd) {
                $client->del(RedisWrapper::FD_USER_MAP . $existingFd);
                $client->del(RedisWrapper::FD_USER_MAP . $existingFd . ':conn_id');
            }
            $client->set(RedisWrapper::FD_USER_MAP . $fd, $userId);
            $client->set(RedisWrapper::USER_FD . $userId, $fd);
            $client->exec();
            // Console::debug("Set FD {$fd} for user {$userId} in Redis");
            return true;
        } catch (\Exception $e) {
            Console::error("Failed to set FD {$fd} for user {$userId}: " . $e->getMessage());
            $client->discard();
            return false;
        }
    }

    /**
     * Get the FD for a user.
     * @param string $userId
     * @return int|null
     */
    public function getUserFd(string $userId): ?int
    {
        $fd = $this->get(RedisWrapper::USER_FD . $userId);
        if ($fd === null) {
            // Console::debug("No FD found for user ID {$userId}");
            return null;
        }
        if (!is_numeric($fd)) {
            // Console::warn("Invalid FD format for user {$userId}: {$fd}");
            return null;
        }
        return (int) $fd;
    }

    public function getUserFdOld(string $userId): ?int
    {
        $fd = $this->redis->get(RedisWrapper::USER_FD . $userId);
        // $userFdKeys = $this->redis->keys(RedisWrapper::USER_FD . '*');
        // $userFdKeyValues = $this->redis->mget($userFdKeys);
        // $uId = json_encode($userId);
        // $_fd = json_encode($fd);

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

    /**
     * Get the user ID for an FD.
     * @param int $fd
     * @return string|null
     */
    public function getUserIdByFd(int $fd): ?string
    {
        $userId = $this->get(RedisWrapper::FD_USER_MAP . $fd);
        // Console::debug("Fetching user ID for FD: {$fd} found in Redis: " . ($userId === null ? 'null' : $userId));
        if ($userId === null) {
            return null;
        }
        return is_string($userId) ? $userId : null;
    }

    public function getUserIdByFdOld(int $fd): ?string
    {
        $userId = $this->redis->get(RedisWrapper::FD_USER_MAP . $fd);
        // Console::debug("Fetching user ID for FD: {$fd} found in Redis: " . ($userId === null ? 'null' : $userId));
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

    /**
     * Remove user mappings for a specific user and FD.
     * @param string $userId
     * @param int $fd
     * @return void
     */
    public function removeUser(string $userId, int $fd): void
    {
        $client = $this->redis->getClient();
        if (!$client) {
            // Console::error("Redis client unavailable in removeUser");
            return;
        }

        try {
            $client->multi();
            $client->del(RedisWrapper::FD_USER_MAP . $fd);
            $client->del(RedisWrapper::FD_USER_MAP . $fd . ':conn_id');
            $client->del(RedisWrapper::USER_FD . $userId);
            $client->exec();
            // Console::debug("Removed user {$userId} and FD {$fd} from Redis");
        } catch (\Exception $e) {
            Console::error("Failed to remove user {$userId} and FD {$fd}: " . $e->getMessage());
            $client->discard();
        }
    }

    /**
     * Clean up all connections for a user.
     * @param string $userId
     * @return void
     */
    public function cleanupUserConnections(string $userId): void
    {
        $fd = $this->getUserFd($userId);
        if ($fd) {
            $this->removeUser($userId, $fd);
        }
        // Console::debug("Cleaned up connections for user {$userId}");
    }

    // /**
    //  * Add a notification to Redis queue.
    //  * @param string $userId
    //  * @param array $notification
    //  * @return void
    //  */
    // public function addNotification(string $userId, array $notification): void
    // {
    //     $this->redis->set(RedisWrapper::QUEUE_PREFIX . $userId, json_encode($notification));
    // }

    // public function removeUser(string $userId, int $fd): void
    // {
    //     $this->redis->del(RedisWrapper::FD_USER_MAP . $fd);
    //     $this->redis->del(RedisWrapper::USER_FD . ($userId ?: ''));
    // }

    public function removeUserByFdOld(int $fd): void
    {
        $this->redis->del(RedisWrapper::FD_USER_MAP . $fd);
    }

        /**
     * Remove user mappings by FD.
     * @param int $fd
     * @return void
     */
    public function removeUserByFd(int $fd): void
    {
        $client = $this->redis->getClient();
        if (!$client) {
            // Console::error("Redis client unavailable in removeUserByFd");
            return;
        }

        try {
            $client->multi();
            $client->del(RedisWrapper::FD_USER_MAP . $fd);
            $client->del(RedisWrapper::FD_USER_MAP . $fd . ':conn_id');
            $client->exec();
            // Console::debug("Removed FD {$fd} from Redis");
        } catch (\Exception $e) {
            Console::error("Failed to remove FD {$fd}: " . $e->getMessage());
            $client->discard();
        }
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

    // public function cleanupUserConnections(string $userId): void
    // {
    //     $fd = $this->redis->get(RedisWrapper::USER_FD . $userId);
    //     if ($fd) {
    //         $this->redis->del(RedisWrapper::FD_USER_MAP . $fd);
    //         $this->redis->del(RedisWrapper::USER_FD . $userId);
    //     }
    // }

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