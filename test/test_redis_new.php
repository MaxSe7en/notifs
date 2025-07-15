<?php
require 'vendor/autoload.php';

use App\Exceptions\Console;
use App\Services\RedisWrapper;// Import the Console class

// 1. Connect to Redis
$redisConn = new RedisWrapper('127.0.0.1', 6379, 0);

if ($redisConn->isConnected()) {
    Console::log("\n--- Basic Operations (from previous example) ---");
    $redisConn->set("mykey", "Hello Predis!");
    Console::log("Value for 'mykey': " . ($redisConn->get("mykey") ?? 'null'));
    $redisConn->delete("mykey"); // Use delete for consistency, or del() works too

    Console::log("\n--- New Method: keys() ---");
    $redisConn->set("test:key1", "value1");
    $redisConn->set("test:key2", "value2");
    $redisConn->set("another:key", "value3");
    $allKeys = $redisConn->keys('test:*');
    Console::log("Keys matching 'test:*': " . implode(', ', $allKeys));
    $redisConn->del('test:key1', 'test:key2', 'another:key'); // Using del alias

    Console::log("\n--- New Method: mGet() ---");
    $redisConn->set("user:1:name", "Alice");
    $redisConn->set("user:1:email", "alice@example.com");
    $redisConn->set("user:2:name", "Bob");
    $userKeys = ["user:1:name", "user:1:email", "user:2:name", "user:3:name"];
    $userValues = $redisConn->mGet($userKeys);
    Console::log("Values for " . implode(', ', $userKeys) . ": " . json_encode($userValues));
    $redisConn->del("user:1:name", "user:1:email", "user:2:name");

    Console::log("\n--- New Method: lPush() and rPush() ---");
    $redisConn->del("mylist"); // Clean up
    Console::log("lPush 'mylist', 'valueC', 'valueB', 'valueA': " . $redisConn->lPush("mylist", "valueC", "valueB", "valueA")); // list: [valueA, valueB, valueC]
    Console::log("rPush 'mylist', 'valueD', 'valueE': " . $redisConn->rPush("mylist", "valueD", "valueE")); // list: [valueA, valueB, valueC, valueD, valueE]
    Console::log("List elements (lrange 0 -1): " . json_encode($redisConn->getClient()->lrange('mylist', 0, -1)));

    Console::log("\n--- New Method: lPop() ---");
    $popped = $redisConn->lPop("mylist");
    Console::log("Popped from 'mylist': " . ($popped ?? 'null')); // Should be valueA
    Console::log("List elements after lPop: " . json_encode($redisConn->getClient()->lrange('mylist', 0, -1)));
    $redisConn->del("mylist");

    Console::log("\n--- New Method: publish() and subscribe() ---");
    Console::info("NOTE: To test publish/subscribe, you need two separate PHP processes/scripts.");
    Console::info("One to run the subscriber (which blocks), and another to publish messages.");

    // Example of how to run a publisher (you'd run this in a separate script or after the subscriber is active)
    // Uncomment and run this in a *separate terminal*:
    /*
    // Publisher script (e.g., publisher.php)
    // require 'vendor/autoload.php';
    // require 'RedisWrapper.php';
    // require 'App/Exceptions/Console.php';
    // use App\Exceptions\Console;
    // $publisherRedis = new RedisWrapper();
    // if ($publisherRedis->isConnected()) {
    //     Console::log("Publishing 'Hello World' to 'my_channel'...");
    //     $clients = $publisherRedis->publish("my_channel", "Hello World " . time());
    //     Console::log("Message sent to {$clients} clients.");
    //     Console::log("Publishing 'Another Message' to 'another_channel'...");
    //     $clients = $publisherRedis->publish("another_channel", "Another Message " . time());
    //     Console::log("Message sent to {$clients} clients.");
    // }
    */

    // Subscriber example (this will block execution)
    // You'd typically run this in a daemonized script or a long-running worker process.
    // For demonstration, uncomment the block below and run this file.
    // In a *separate terminal*, run the publisher script or another Redis CLI to publish.

    /*
    Console::info("\nStarting subscriber. This will block execution until killed (Ctrl+C).");
    $redisConn->subscribe(['my_channel', 'another_channel'], function($channel, $message) {
        Console::log2("Received message on '{$channel}': ", $message);
    });
    */

} else {
    Console::error("Redis server is not reachable. Please ensure it's running.");
}


