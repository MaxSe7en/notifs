<?php
// public/start_server.php
require_once __DIR__ . '/../vendor/autoload.php';

use App\Workers\WebSocketServer;

$server = new WebSocketServer();
$server->start();
