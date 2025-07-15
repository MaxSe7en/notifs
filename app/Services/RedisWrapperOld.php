<?php
namespace App\Services;

use App\Exceptions\Console;
use Predis\Client;
use function Swoole\Coroutine\Http\get;

class RedisWrapperOld
{
    private Client $redisClient;

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

    public function __construct()
    {
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        try {
            $this->redisClient = new Client([
                'scheme' => 'tcp',
                'host' => '127.0.0.1',
                'port' => 6379,
                'timeout' => 5.0, // Connection timeout
                'read_write_timeout' => 0, // Disable read/write timeout for pub/sub
                'persistent' => true, // Use persistent connections
            ]);
            $this->redisClient->ping(); // Test connection
            Console::info("Connected to Redis ✅");
        } catch (\Exception $e) {
            Console::error("Redis connection failed: " . $e->getMessage());
            throw $e;
        }
    }

    public function getClient(): Client
    {
        try {
            $this->redisClient->ping();
        } catch (\Exception $e) {
            Console::warn("Redis connection lost, reconnecting: " . $e->getMessage());
            $this->initializeClient();
        }
        return $this->redisClient;
    }

    private function handleResponse($response)
    {
        if ($response instanceof \Predis\Response\Status) {
            return $response->getPayload();
        }
        if ($response instanceof \Predis\Response\ObjectInterface) {
            return (array) $response;
        }
        return $response;
    }

    public function set(string $key, $value): bool
    {
        try {
            $response = $this->getClient()->set($key, $value);
            return $response->getPayload() === 'OK';
        } catch (\Exception $e) {
            Console::error("Redis set failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function get(string $key)
    {
        try {
            return $this->getClient()->get($key);
        } catch (\Exception $e) {
            Console::error("Redis get failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function keys(string $pattern = '*'): array
    {
        try {
            return $this->getClient()->keys($pattern);
        } catch (\Exception $e) {
            Console::error("Redis keys failed for pattern {$pattern}: " . $e->getMessage());
            throw $e;
        }
    }

    public function mGet(array $keys): array
    {
        try {
            if (empty($keys)) {
                Console::warn("mGet called with empty keys array, returning empty result.");
                return [];
            }
            return $this->getClient()->mget($keys);
        } catch (\Exception $e) {
            Console::error("Redis mGet failed for keys: " . implode(', ', $keys) . ": " . $e->getMessage());
            throw $e;
        }
    }

    public function del(string $key): int
    {
        try {
            // $response = $this->getClient()->del($key);
            // Console::log("Redis del response for key {$key}: " . json_encode($response.getPayload()));
            return $this->getClient()->del($key);
        } catch (\Exception $e) {
            Console::error("Redis del failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function exists(string $key): bool
    {
        try {
            return $this->getClient()->exists($key) > 0;
        } catch (\Exception $e) {
            Console::error("Redis exists failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function lPush(string $key, $value): int
    {
        try {
            return $this->getClient()->lpush($key, $value);
        } catch (\Exception $e) {
            Console::error("Redis lPush failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function rPush(string $key, $value): int
    {
        try {
            $response = $this->getClient()->rpush($key, $value);
            Console::log("Redis rPush response for key {$key}: " . json_encode($response));
            if ($response === null) {
                Console::error("Redis rPush returned null for key {$key}");
                throw new \RuntimeException("Redis rPush failed for key {$key}");
            }
            return $response;
        } catch (\Exception $e) {
            Console::error("Redis rPush failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function lPop(string $key)
    {
        try {
            return $this->getClient()->lpop($key);
        } catch (\Exception $e) {
            Console::error("Redis lPop failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function rPop(string $key)
    {
        try {
            return $this->getClient()->rpop($key);
        } catch (\Exception $e) {
            Console::error("Redis rPop failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function lRange(string $key, int $start, int $stop): array
    {
        try {
            return $this->getClient()->lrange($key, $start, $stop);
        } catch (\Exception $e) {
            Console::error("Redis lRange failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function lLen(string $key): int
    {
        try {
            return $this->getClient()->llen($key);
        } catch (\Exception $e) {
            Console::error("Redis lLen failed for key {$key}: " . $e->getMessage());
            throw $e;
        }
    }

    public function publish(string $channel, string $message): int
    {
        try {
            return $this->getClient()->publish($channel, $message);
        } catch (\Exception $e) {
            Console::error("Redis publish failed for channel {$channel}: " . $e->getMessage());
            throw $e;
        }
    }

    public function subscribe(array $channels, callable $callback): void
    {
        try {
            $pubSub = $this->getClient()->pubSubLoop();
            $pubSub->subscribe(...$channels);
            foreach ($pubSub as $message) {
                if ($message->kind === 'message') {
                    $callback($this->getClient(), $message->channel, $message->payload);
                }
            }
            $pubSub->unsubscribe();
        } catch (\Exception $e) {
            Console::error("Redis subscribe failed: " . $e->getMessage());
            throw $e;
        }
    }
}