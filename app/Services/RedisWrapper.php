<?php
namespace App\Services;

use Predis\Client as PredisClient;
use Predis\Connection\ConnectionException;
use App\Exceptions\Console;

class RedisWrapper
{
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

    private PredisClient $redisClient;
    private array $connectionParameters;

    /**
     * Initializes the RedisWrapper with connection parameters.
     *
     * @param string $host The hostname or IP address of the Redis server.
     * @param int $port The port number of the Redis server.
     * @param int $database The database number to connect to.
     * @param string|null $password The password for Redis authentication. Defaults to null.
     */
    public function __construct(string $host = '127.0.0.1', int $port = 6379, int $database = 0, ?string $password = null)
    {
        $this->connectionParameters = [
            'scheme' => 'tcp',
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'timeout' => 5.0, // Connection timeout
            'read_write_timeout' => 0, // Disable read/write timeout for pub/sub
            'persistent' => true
        ];

        if ($password !== null) {
            $this->connectionParameters['password'] = $password;
        }

        $this->connect();
    }

    /**
     * Establishes a connection to the Redis server.
     * Prints an error message if connection fails.
     */
    private function connect(): void
    {
        try {
            $this->redisClient = new PredisClient($this->connectionParameters);
            // Predis connects lazily, so we'll ping to ensure connection
            $this->redisClient->ping();
            //Console::info("Successfully connected to Redis at {$this->connectionParameters['host']}:{$this->connectionParameters['port']}/{$this->connectionParameters['database']}");
        } catch (ConnectionException $e) {
            Console::error("Could not connect to Redis: " . $e->getMessage());
            // Optionally, you might want to log the error or throw a custom exception
            // to be handled by your application's error reporting system.
        } catch (\Exception $e) {
            Console::error("An unexpected error occurred during Redis connection: " . $e->getMessage());
        }
    }

    /**
     * Returns the underlying Predis client object.
     *
     * @return PredisClient|null
     */
    public function getClient(): ?PredisClient
    {
        // Predis connects lazily. If ping fails, it means the connection might have dropped.
        // We'll try to reconnect if the client is not in a connected state.
        if (!isset($this->redisClient) || !$this->isConnected()) {
            //Console::warn("Redis client not connected or connection dropped. Attempting to reconnect...");
            $this->connect();
        }
        return $this->redisClient ?? null;
    }

    /**
     * Checks if the Redis client is currently connected.
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        try {
            return isset($this->redisClient) && $this->redisClient->ping();
        } catch (ConnectionException $e) {
            // Log connection errors during isConnected check, but don't stop execution
            Console::debug("Connection check failed: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            Console::debug("Unexpected error during connection check: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Set the value at key $key to $value.
     *
     * @param string $key The key to set.
     * @param mixed $value The value to store.
     * @param int|null $expirationSeconds Set the specified expire time, in seconds.
     * @return bool True on success, false on failure.
     */
    public function set(string $key, $value, ?int $expirationSeconds = null): bool
    {
        $client = $this->getClient();
        if ($client) {
            try {
                if ($expirationSeconds !== null) {
                    return (bool) $client->setex($key, $expirationSeconds, $value);
                }
                return (bool) $client->set($key, $value);
            } catch (\Exception $e) {
                Console::error("Failed to set key '{$key}': " . $e->getMessage());
                return false;
            }
        }
        //Console::warn("Attempted to set key '{$key}' but Redis client is not available.");
        return false;
    }

    /**
     * Return the value at key $key, or null if the key doesn't exist.
     *
     * @param string $key The key to retrieve.
     * @return string|null
     */
    public function get(string $key): ?string
    {
        $client = $this->getClient();
        if ($client) {
            try {
                $value = $client->get($key);
                return ($value === false) ? null : $value; // Predis returns false if key doesn't exist
            } catch (\Exception $e) {
                Console::error("Failed to get key '{$key}': " . $e->getMessage());
                return null;
            }
        }
        //Console::warn("Attempted to get key '{$key}' but Redis client is not available.");
        return null;
    }

    /**
     * Delete one or more keys specified by $keys.
     * Note: I've updated the signature based on your request. `del` is an alias for `delete`.
     *
     * @param string ...$keys One or more keys to delete.
     * @return int The number of keys deleted.
     */
    public function delete(string ...$keys): int
    {
        $client = $this->getClient();
        if ($client) {
            try {
                return $client->del($keys);
            } catch (\Exception $e) {
                Console::error("Failed to delete keys " . implode(', ', $keys) . ": " . $e->getMessage());
                return 0;
            }
        }
        //Console::warn("Attempted to delete keys but Redis client is not available.");
        return 0;
    }

    /**
     * Alias for delete(). Deletes one or more keys.
     *
     * @param string ...$keys One or more keys to delete.
     * @return int The number of keys deleted.
     */
    public function del(string ...$keys): int
    {
        return $this->delete(...$keys);
    }


    /**
     * Returns the number of keys that exist.
     *
     * @param string ...$keys One or more keys to check.
     * @return int
     */
    public function exists(string ...$keys): int
    {
        $client = $this->getClient();
        if ($client) {
            try {
                return $client->exists($keys);
            } catch (\Exception $e) {
                Console::error("Failed to check existence of keys " . implode(', ', $keys) . ": " . $e->getMessage());
                return 0;
            }
        }
        //Console::warn("Attempted to check existence of keys but Redis client is not available.");
        return 0;
    }

    /**
     * Increments the integer value of $key by $amount.
     *
     * @param string $key The key to increment.
     * @param int $amount The amount to increment by. Defaults to 1.
     * @return int|null The new value of the key, or null on failure.
     */
    public function incr(string $key, int $amount = 1): ?int
    {
        $client = $this->getClient();
        if ($client) {
            try {
                return $client->incrby($key, $amount);
            } catch (\Exception $e) {
                Console::error("Failed to increment key '{$key}': " . $e->getMessage());
                return null;
            }
        }
        //Console::warn("Attempted to increment key '{$key}' but Redis client is not available.");
        return null;
    }

    /**
     * Decrements the integer value of $key by $amount.
     *
     * @param string $key The key to decrement.
     * @param int $amount The amount to decrement by. Defaults to 1.
     * @return int|null The new value of the key, or null on failure.
     */
    public function decr(string $key, int $amount = 1): ?int
    {
        $client = $this->getClient();
        if ($client) {
            try {
                return $client->decrby($key, $amount);
            } catch (\Exception $e) {
                Console::error("Failed to decrement key '{$key}': " . $e->getMessage());
                return null;
            }
        }
        //Console::warn("Attempted to decrement key '{$key}' but Redis client is not available.");
        return null;
    }

    /**
     * Serializes a PHP object/array to a JSON string and stores it in Redis.
     *
     * @param string $key The key to set.
     * @param mixed $data The PHP object or array to store.
     * @param int|null $expirationSeconds Set the specified expire time, in seconds.
     * @return bool True on success, false on failure.
     */
    public function setJson(string $key, $data, ?int $expirationSeconds = null): bool
    {
        try {
            $jsonData = json_encode($data, JSON_THROW_ON_ERROR);
            return $this->set($key, $jsonData, $expirationSeconds);
        } catch (JsonException $e) {
            Console::error("Error serializing data to JSON for key '{$key}': " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            Console::error("Unexpected error during JSON serialization for key '{$key}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Retrieves a JSON string from Redis and deserializes it back into a PHP object/array.
     *
     * @param string $key The key to retrieve.
     * @return mixed|null The deserialized PHP object/array, or null if the key does not exist or if deserialization fails.
     */
    public function getJson(string $key)
    {
        $jsonData = $this->get($key);
        if ($jsonData !== null) {
            try {
                return json_decode($jsonData, true, 512, JSON_THROW_ON_ERROR); // true for associative array
            } catch (JsonException $e) {
                //Console::error("Error deserializing JSON for key '{$key}': " . $e->getMessage());
                return null;
            } catch (\Exception $e) {
                //Console::error("Unexpected error during JSON deserialization for key '{$key}': " . $e->getMessage());
                return null;
            }
        }
        return null;
    }

    /**
     * Returns all keys matching $pattern. Use with caution on production servers.
     *
     * @param string $pattern The glob-style pattern to match. Defaults to '*'.
     * @return array An array of matching keys.
     */
    public function keys(string $pattern = '*'): array
    {
        $client = $this->getClient();
        if ($client) {
            try {
                return $client->keys($pattern);
            } catch (\Exception $e) {
                Console::error("Failed to retrieve keys with pattern '{$pattern}': " . $e->getMessage());
                return [];
            }
        }
        //Console::warn("Attempted to retrieve keys but Redis client is not available.");
        return [];
    }

    /**
     * Returns the values of all specified keys.
     * For every key that does not hold a string value or does not exist,
     * a nil value is returned at the corresponding position.
     *
     * @param array $keys An array of keys to retrieve.
     * @return array An array of values, or null for non-existent/non-string keys.
     */
    public function mGet(array $keys): array
    {
        $client = $this->getClient();
        if ($client) {
            try {
                // Predis's mget returns an array where null represents non-existent/wrong type keys
                return $client->mget($keys);
            } catch (\Exception $e) {
                Console::error("Failed to retrieve multiple keys " . implode(', ', $keys) . ": " . $e->getMessage());
                return array_fill(0, count($keys), null);
            }
        }
        //Console::warn("Attempted to retrieve multiple keys but Redis client is not available.");
        return array_fill(0, count($keys), null); // Return array of nulls if not connected
    }

    /**
     * Inserts all the specified values at the head of the list stored at $key.
     * If $key does not exist, it is created as empty list before performing the push operation.
     *
     * @param string $key The key of the list.
     * @param mixed ...$values One or more values to push.
     * @return int|null The length of the list after the push operation, or null on failure.
     */
    public function lPush(string $key, ...$values): ?int
    {
        $client = $this->getClient();
        if ($client) {
            try {
                // Predis lpush accepts a variadic number of arguments
                return $client->lpush($key, ...$values);
            } catch (\Exception $e) {
                Console::error("Failed to lPush to list '{$key}': " . $e->getMessage());
                return null;
            }
        }
        //Console::warn("Attempted to lPush to list '{$key}' but Redis client is not available.");
        return null;
    }

    /**
     * Inserts all the specified values at the tail of the list stored at $key.
     * If $key does not exist, it is created as empty list before performing the push operation.
     *
     * @param string $key The key of the list.
     * @param mixed ...$values One or more values to push.
     * @return int|null The length of the list after the push operation, or null on failure.
     */
    public function rPush(string $key, ...$values): ?int
    {
        $client = $this->getClient();
        if ($client) {
            try {
                // Predis rpush accepts a variadic number of arguments
                return $client->rpush($key, ...$values);
            } catch (\Exception $e) {
                Console::error("Failed to rPush to list '{$key}': " . $e->getMessage());
                return null;
            }
        }
        //Console::warn("Attempted to rPush to list '{$key}' but Redis client is not available.");
        return null;
    }

    /**
     * Removes and returns the first element of the list stored at $key.
     *
     * @param string $key The key of the list.
     * @return string|null The first element of the list, or null if the list is empty or doesn't exist.
     */
    public function lPop(string $key): ?string
    {
        $client = $this->getClient();
        if ($client) {
            try {
                return $client->lpop($key);
            } catch (\Exception $e) {
                Console::error("Failed to lPop from list '{$key}': " . $e->getMessage());
                return null;
            }
        }
        //Console::warn("Attempted to lPop from list '{$key}' but Redis client is not available.");
        return null;
    }

    /**
     * Posts a message to the given channel.
     *
     * @param string $channel The channel to publish to.
     * @param string $message The message to publish.
     * @return int|null The number of clients that received the message, or null on failure.
     */
    public function publish(string $channel, string $message): ?int
    {
        $client = $this->getClient();
        if ($client) {
            try {
                return $client->publish($channel, $message);
            } catch (\Exception $e) {
                Console::error("Failed to publish message to channel '{$channel}': " . $e->getMessage());
                return null;
            }
        }
        //Console::warn("Attempted to publish to channel '{$channel}' but Redis client is not available.");
        return null;
    }

    /**
     * Listens for messages published to the given channels.
     * This method blocks execution. The callback function will be invoked
     * for each message received.
     *
     * IMPORTANT: This method blocks execution. It's typically run in a dedicated process.
     *
     * @param array $channels An array of channel names to subscribe to.
     * @param callable $callback A callable function that receives ($channel, $message) as arguments.
     * @return void
     */
    public function subscribe2(array $channels, callable $callback): void
    {
        $client = $this->getClient();
        if (!$client) {
            //Console::error("Cannot subscribe: Redis client not connected.");
            return;
        }

        try {
            $pubsub = $client->pubsub();
            $pubsub->subscribe($channels);

            //Console::info("Subscribing to channels: " . implode(', ', $channels) . ". Listening for messages...");

            foreach ($pubsub as $message) {
                switch ($message->kind) {
                    case 'subscribe':
                        //Console::info("Subscribed to channel: {$message->channel}");
                        break;
                    case 'message':
                        // Invoke the provided callback with channel and message
                        //Console::log2("Received message on '{$message->channel}': ", $message->payload);
                        call_user_func($callback, $message->channel, $message->payload);
                        break;
                    case 'unsubscribe':
                        Console::info("Unsubscribed from channel: {$message->channel}");
                        break;
                    default:
                        Console::debug("Unhandled Pub/Sub message kind: {$message->kind}");
                        break;
                }
            }
        } catch (\Exception $e) {
            Console::error("Error during subscription: " . $e->getMessage());
            // Handle reconnection logic for pub/sub if necessary in a real application
        }
    }
    /**
     * Listens for messages published to the given channels.
     * This method blocks execution. The callback function will be invoked
     * for each message received.
     *
     * IMPORTANT: This method blocks execution. It's typically run in a dedicated process.
     *
     * @param array $channels An array of channel names to subscribe to.
     * @param callable $callback A callable function that receives ($client, $channel, $message) as arguments.
     * @return void
     */
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
