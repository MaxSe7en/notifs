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
    private Client $redisClient;
    private int $maxAttempts;
    private array $config;
    /**
     * Redis key prefixes
     */
    public const KEY_PREFIX = 'ws:';
    public const CONNECTION_PREFIX = self::KEY_PREFIX . 'connection:';
    public const QUEUE_PREFIX = self::KEY_PREFIX . 'notification_queue:';
    public const SERVER_REGISTRY = self::KEY_PREFIX . 'servers';
    public const USER_CONNECTION_MAP = self::KEY_PREFIX . 'user_connections';
    public const FD_USER_MAP = self::KEY_PREFIX . 'fd_user_map';
    public const USER_FD = self::KEY_PREFIX . 'user_fd';

    public function __construct(int $maxAttempts = 3)
    {
        $this->config = [
            'cluster' => filter_var(getenv('REDIS_CLUSTER') ?: false, FILTER_VALIDATE_BOOLEAN),
            'scheme' => getenv('REDIS_SCHEME') ?: 'tcp',
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'password' => getenv('REDIS_PASSWORD') ?: null,
            'read_write_timeout' => 0,
            'failover' => 'distribute',
        ];
        $this->maxAttempts = $maxAttempts;
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        try {
            if ($this->config['cluster']) {
                $this->redisClient = new Client([
                    'cluster' => 'redis',
                    'parameters' => [
                        'password' => $this->config['password'],
                    ],
                    'nodes' => [

                        $this->config['scheme'] . '://' . $this->config['host'] . ':' . $this->config['port'],
                    ],
                ]);
            } else {
                $this->redisClient = new Client([
                    'scheme' => $this->config['scheme'],
                    'host' => $this->config['host'],
                    'port' => $this->config['port'],
                    'password' => $this->config['password'],
                    'read_write_timeout' => $this->config['read_write_timeout'],
                ]);
            }

            // Test connection with actual command that verifies connectivity
            $this->redisClient->time();
            Console::info("Connected to Redis ✅");
        } catch (\Exception $e) {
            Console::error("Redis connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function getClient(): Client
    {
        try {
            // Use a simple command that doesn't modify data to test connection
            $this->redisClient->time();
        } catch (\Exception $e) {
            Console::warn("Redis connection lost, reconnecting... " . $e->getMessage());
            $this->initializeClient();
        }

        return $this->redisClient;
    }
    /**
     * Retry logic for Redis operations.
     *
     * @param callable $operation The Redis operation to perform.
     * @param string $operationName Name of the operation for logging.
     * @return mixed The result of the Redis operation.
     * @throws ConnectionException If the operation fails after all retries.
     */
    private function withRetry(callable $operation, string $operationName = 'unknown')
    {
        $attempts = 0;
        do {
            try {
                $result = $operation($this->getClient());
                    Console::warn("Redis operation '{$operationName}' .");
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
        return $this->withRetry(fn() => $this->redisClient->hSet($hash, $key, $value), "hSet($hash, $key)");
    }

    public function hGet(string $hash, string $key)
    {
        return $this->withRetry(fn() => $this->redisClient->hGet($hash, $key), "hGet($hash, $key)");
    }

    public function hDel(string $hash, array $keys): int
    {
        return $this->withRetry(fn() => $this->redisClient->hDel($hash, ...$keys), "hDel($hash)");
    }

    public function hGetAll(string $hash): array
    {
        return $this->withRetry(fn() => $this->redisClient->hGetAll($hash), "hGetAll($hash)");
    }

    public function publish(string $channel, string $message): int
    {
        return $this->withRetry(fn() => $this->redisClient->publish($channel, $message), "publish($channel)");
    }

    /**
     * Subscribe to Redis channels using pubSubLoop
     * This is the correct way to handle pub/sub with callbacks in Predis
     * Note: This method blocks and runs continuously
     */
    public function subscribe(array $channels, callable $callback): void
    {
        // Directly subscribe without retry wrapper
        $pubSub = $this->redisClient->pubSubLoop();

        $pubSub->subscribe(...$channels);

        foreach ($pubSub as $message) {
            if ($message->kind === 'message') {
                $callback($this->redisClient, $message->channel, $message->payload);
            }
        }

        $pubSub->unsubscribe();
    }

    public function lRange(string $key, int $start, int $stop): array
    {
        return $this->withRetry(fn() => $this->redisClient->lRange($key, $start, $stop), "lRange($key)");
    }

    public function del(string $key): int
    {
        return $this->withRetry(fn() => $this->redisClient->del($key), "del($key)");
    }

    /**
     * Get the length of a list.
     *
     * @param string $key
     * @return int
     */
    public function lLen(string $key): int
    {
        return $this->withRetry(fn() => $this->redisClient->lLen($key), "lLen($key)");
    }

    public function set(string $key, $value): bool
    {
        return $this->withRetry(fn() => $this->redisClient->set($key, $value), "set($key)");
    }

    public function get(string $key)
    {
        return $this->withRetry(fn() => $this->redisClient->get($key), "get($key)");
    }

    public function exists(string $key): bool
    {
        return $this->withRetry(fn() => $this->redisClient->exists($key), "exists($key)") > 0;
    }
    /**
     * Push a value onto the end of a list.
     *
     * @param string $key
     * @param mixed $value
     * @return int The length of the list after the push operation.
     */
    public function rPush(string $key, $value): int
    {
        return $this->withRetry(fn() => $this->redisClient->rPush($key, $value), "rPush($key)");
    }

    /**
     * Pop a value from the end of a list.
     *
     * @param string $key
     * @return mixed The popped value, or null if the list is empty.
     */
    public function rPop(string $key)
    {
        return $this->withRetry(fn() => $this->redisClient->rPop($key), "rPop($key)");
    }

    // lpush
    /**
     * Push a value onto the start of a list.
     *
     * @param string $key
     * @param mixed $value
     * @return int The length of the list after the push operation.
     */
    public function lPush(string $key, $value): int
    {
        return $this->withRetry(fn() => $this->redisClient->lPush($key, $value), "lPush($key)");
    }

    /**
     * Pop a value from the start of a list.
     *
     * @param string $key
     * @return mixed The popped value, or null if the list is empty.
     */
    public function lPop(string $key)
    {
        return $this->withRetry(fn() => $this->redisClient->lPop($key), "lPop($key)");
    }
    
}
