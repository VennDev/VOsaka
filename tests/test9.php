<?php

require '../vendor/autoload.php';

use venndev\vosaka\VOsaka;
use venndev\vosaka\VSocket;

function clientA(): Generator
{
    $socket = new VSocket('httpbin.org', 80, 'tcp');

    $socket->on('connected', function ($data) {
        echo "[Client A] Connected: {$data}\n";
    });

    $socket->on('data_received', function ($data) {
        echo "[Client A] Received: " . substr($data, 0, 50) . "...\n";
    });

    try {
        yield from $socket->connect();

        $request = "GET /get?client=A HTTP/1.1\r\nHost: httpbin.org\r\nConnection: close\r\n\r\n";
        yield from $socket->send($request);
        yield from $socket->handleTCP();

    } catch (Exception $e) {
        echo "[Client A] Error: " . $e->getMessage() . "\n";
    } finally {
        yield from $socket->disconnect();
    }
}

function clientB(): Generator
{
    yield from VOsaka::sleep(1);

    $socket = new VSocket('httpbin.org', 80, 'tcp');

    $socket->on('connected', function ($data) {
        echo "[Client B] Connected: {$data}\n";
    });

    $socket->on('data_received', function ($data) {
        echo "[Client B] Received: " . substr($data, 0, 50) . "...\n";
    });

    try {
        yield from $socket->connect();

        $request = "GET /get?client=B HTTP/1.1\r\nHost: httpbin.org\r\nConnection: close\r\n\r\n";
        yield from $socket->send($request);
        yield from $socket->handleTCP();

    } catch (Exception $e) {
        echo "[Client B] Error: " . $e->getMessage() . "\n";
    } finally {
        yield from $socket->disconnect();
    }
}

function selfCommunicationExample(): Generator
{
    echo "=== VSocket Self Communication Example ===\n";
    echo "Starting two clients that communicate with the same server...\n";

    $tasks = [
        clientA(),
        clientB()
    ];

    yield VOsaka::join(...$tasks);

    echo "\n=== Communication completed ===\n";
}

VOsaka::spawn(selfCommunicationExample());
VOsaka::run();