<?php

require_once '../vendor/autoload.php';

use venndev\vosaka\VOsaka;
use venndev\vosaka\VSocket;

function simpleTcpExample(): Generator
{
    $socket = new VSocket('httpbin.org', 80, 'tcp', 30);
    
    $socket->on('connected', function($data) {
        echo $data . "\n";
    });
    
    $socket->on('data_received', function($data) {
        echo "Received: " . substr($data, 0, 100) . "...\n";
    });
    
    $socket->on('error', function($data) {
        echo "Error: " . $data . "\n";
    });
    
    try {
        yield from $socket->connect();
        
        $httpRequest = "GET /ip HTTP/1.1\r\nHost: httpbin.org\r\nConnection: close\r\n\r\n";
        yield from $socket->send($httpRequest);
        
        yield from $socket->handleTCP();
        
    } catch (Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
    } finally {
        yield from $socket->disconnect();
    }
}

function multipleConnectionsExample(): Generator
{
    $sockets = [
        new VSocket('httpbin.org', 80, 'tcp'),
        new VSocket('jsonplaceholder.typicode.com', 80, 'tcp'),
        new VSocket('api.github.com', 443, 'tcp')
    ];
    
    foreach ($sockets as $i => $socket) {
        $socket->on('connected', function($data) use ($i) {
            echo "Socket {$i}: {$data}\n";
        });
        
        $socket->on('error', function($data) use ($i) {
            echo "Socket {$i} Error: {$data}\n";
        });
    }
    
    try {
        yield from VSocket::connectMultiple($sockets);
        echo "All sockets connected successfully!\n";
        
        $disconnectTasks = [];
        foreach ($sockets as $socket) {
            $disconnectTasks[] = $socket->disconnect();
        }
        yield VOsaka::join(...$disconnectTasks);
        
    } catch (Exception $e) {
        echo "Multiple connections error: " . $e->getMessage() . "\n";
    }
}

function autoReconnectExample(): Generator
{
    $socket = new VSocket('localhost', 9999, 'tcp');
    
    $socket->enableAutoReconnect(3, 1);
    
    $socket->on('reconnecting', function($data) {
        echo $data . "\n";
    });
    
    $socket->on('reconnect_failed', function($data) {
        echo $data . "\n";
    });
    
    $socket->on('connection_failed', function($data) {
        echo $data . "\n";
    });
    
    try {
        yield from $socket->connect();
    } catch (Exception $e) {
        echo "Final connection failure: " . $e->getMessage() . "\n";
    }
}

function socketRacingExample(): Generator
{
    $sockets = [
        new VSocket('httpbin.org', 80, 'tcp'),
        new VSocket('jsonplaceholder.typicode.com', 80, 'tcp'),
        new VSocket('api.github.com', 80, 'tcp')
    ];
    
    try {
        echo "Racing socket connections...\n";
        $winner = yield from VSocket::raceConnect($sockets);
        echo "Winner connected first!\n";
        
    } catch (Exception $e) {
        echo "Racing error: " . $e->getMessage() . "\n";
    }
}

function pingExample(): Generator
{
    $socket = new VSocket('httpbin.org', 80, 'tcp');
    
    $socket->on('ping_response', function($data) {
        echo "Ping response - Latency: {$data['latency']}ms\n";
    });
    
    $socket->on('ping_failed', function($data) {
        echo "Ping failed: {$data}\n";
    });
    
    try {
        yield from $socket->connect();
        
        for ($i = 0; $i < 5; $i++) {
            yield from $socket->ping();
            yield from VOsaka::sleep(1);
        }
        
    } catch (Exception $e) {
        echo "Ping example error: " . $e->getMessage() . "\n";
    } finally {
        yield from $socket->disconnect();
    }
}

function udpExample(): Generator
{
    $socket = new VSocket('8.8.8.8', 53, 'udp');
    
    $socket->on('connected', function($data) {
        echo "UDP: " . $data . "\n";
    });
    
    $socket->on('data_received', function($data) {
        echo "UDP received from {$data['peer']}: " . bin2hex($data['data']) . "\n";
    });
    
    try {
        yield from $socket->connect();
        
        $dnsQuery = "\x12\x34\x01\x00\x00\x01\x00\x00\x00\x00\x00\x00\x06google\x03com\x00\x00\x01\x00\x01";
        yield from $socket->send($dnsQuery);
        
        VOsaka::spawn(function() use ($socket): Generator {
            yield from VOsaka::sleep(5);
            yield from $socket->disconnect();
        });
        
        yield from $socket->handleUDP();
        
    } catch (Exception $e) {
        echo "UDP example error: " . $e->getMessage() . "\n";
    }
}

function socketPoolExample(): Generator
{
    $socketPool = [];
    
    for ($i = 0; $i < 5; $i++) {
        $socket = new VSocket('httpbin.org', 80, 'tcp');
        $socket->enableAutoReconnect(3, 1);
        
        $socket->on('connected', function($data) use ($i) {
            echo "Pool Socket {$i}: Connected\n";
        });
        
        $socketPool[] = $socket;
    }
    
    yield from VSocket::connectMultiple($socketPool);
    
    $tasks = [];
    foreach ($socketPool as $i => $socket) {
        $tasks[] = (function() use ($socket, $i): Generator {
            $request = "GET /delay/" . ($i + 1) . " HTTP/1.1\r\nHost: httpbin.org\r\nConnection: close\r\n\r\n";
            yield from $socket->send($request);
            
            $startTime = microtime(true);
            while ($socket->isConnected() && (microtime(true) - $startTime) < 10) {
                yield from VOsaka::sleep(0.1);
            }
            
            echo "Task {$i} completed\n";
        })();
    }
    
    yield VOsaka::join(...$tasks);
    
    $disconnectTasks = [];
    foreach ($socketPool as $socket) {
        $disconnectTasks[] = $socket->disconnect();
    }
    yield VOsaka::join(...$disconnectTasks);
}

function main(): Generator
{
    echo "=== VSocket with VOsaka Examples ===\n\n";
    
    echo "1. Simple TCP Example:\n";
    yield from simpleTcpExample();
    echo "\n";
    
    echo "2. Multiple Connections Example:\n";
    yield from multipleConnectionsExample();
    echo "\n";
    
    echo "3. Auto-reconnect Example:\n";
    yield from autoReconnectExample();
    echo "\n";
    
    echo "4. Socket Racing Example:\n";
    yield from socketRacingExample();
    echo "\n";
    
    echo "5. Ping Example:\n";
    yield from pingExample();
    echo "\n";
    
    echo "6. UDP Example:\n";
    yield from udpExample();
    echo "\n";
    
    echo "7. Socket Pool Example:\n";
    yield from socketPoolExample();
    echo "\n";
    
    echo "=== All examples completed ===\n";
}

VOsaka::spawn(main());
VOsaka::run();