<?php
namespace App\Services;

use Redis;
use RedisException;
use Predis\Client;
use Predis\Connection\ConnectionException;
use Predis\Connection\ConnectionTimeoutException;
use Predis\Connection\StreamConnection;
use Predis\Connection\PhpiredisConnection;
use App\Exceptions\Console;

class RedisWrapper
{
    private Client $redis;
    private int $maxAttempts;

    public function __construct(int $maxAttempts = 3)
    {
        $this->redis = new Client();
        $this->maxAttempts = $maxAttempts;

        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->redis->connect('127.0.0.1', 6379, 3.0); // 3s timeout
            Console::info("Connected to Redis ✅");
        } catch (ConnectionException $e) {
            Console::error("Failed to connect to Redis: {$e->getMessage()}");
            throw $e;
        }
    }

    private function withRetry(callable $operation, string $operationName = 'unknown')
    {
        $attempts = 0;
        do {
            try {
                $result = $operation();
                if ($attempts > 0) {
                    Console::warn("Redis operation '{$operationName}' succeeded after {$attempts} retries.");
                }
                return $result;
            } catch (ConnectionException $e) {
                $attempts++;
                Console::error("Redis error during '{$operationName}' attempt #{$attempts}: {$e->getMessage()}");
                usleep(200_000); // 200ms delay
                if ($attempts >= $this->maxAttempts) {
                    throw $e;
                }
            }
        } while ($attempts < $this->maxAttempts);
    }

    // Example wrappers
    public function hSet(string $hash, string $key, $value): bool
    {
        return $this->withRetry(fn() => $this->redis->hSet($hash, $key, $value), "hSet($hash, $key)");
    }

    public function hGet(string $hash, string $key)
    {
        return $this->withRetry(fn() => $this->redis->hGet($hash, $key), "hGet($hash, $key)");
    }

    public function hDel(string $hash, array $keys): int
    {
        return $this->withRetry(fn() => $this->redis->hDel($hash, ...$keys), "hDel($hash)");
    }

    public function hGetAll(string $hash): array
    {
        return $this->withRetry(fn() => $this->redis->hGetAll($hash), "hGetAll($hash)");
    }

    public function publish(string $channel, string $message): int
    {
        return $this->withRetry(fn() => $this->redis->publish($channel, $message), "publish($channel)");
    }

    public function subscribe(array $channels, callable $callback): void
    {
        $this->withRetry(fn() => $this->redis->subscribe($channels, $callback), "subscribe()");
    }

    public function lRange(string $key, int $start, int $stop): array
    {
        return $this->withRetry(fn() => $this->redis->lRange($key, $start, $stop), "lRange($key)");
    }

    public function del(string $key): int
    {
        return $this->withRetry(fn() => $this->redis->del($key), "del($key)");
    }

    /**
     * Get the length of a list.
     *
     * @param string $key
     * @return int
     */
    public function lLen(string $key): int
    {
        return $this->redis->lLen($key);
    }
}
