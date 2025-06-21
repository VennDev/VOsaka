<?php

declare(strict_types=1);

namespace venndev\vosaka;

use Generator;
use InvalidArgumentException;
use Throwable;

final class VSocket
{
    protected mixed $socket = null;
    protected bool $isConnected = false;
    protected array $eventHandlers = [];
    protected bool $shouldReconnect = false;
    protected int $reconnectAttempts = 0;
    protected int $maxReconnectAttempts = 5;
    protected int $reconnectDelay = 1;

    public function __construct(
        protected readonly string $host,
        protected readonly int $port,
        protected readonly string $protocol = 'tcp',
        protected readonly int $timeout = 30,
        protected readonly int $bufferSize = 8192
    ) {
        $this->validateParameters();
    }

    private function validateParameters(): void
    {
        if (!filter_var($this->host, FILTER_VALIDATE_IP) && !filter_var($this->host, FILTER_VALIDATE_DOMAIN)) {
            throw new InvalidArgumentException("Invalid host: {$this->host}");
        }
        if ($this->port < 1 || $this->port > 65535) {
            throw new InvalidArgumentException("Port must be between 1 and 65535: {$this->port}");
        }
        if (!in_array($this->protocol, ['tcp', 'udp', 'websocket'])) {
            throw new InvalidArgumentException("Invalid protocol: {$this->protocol}. Supported: 'tcp', 'udp', 'websocket'.");
        }
    }

    public function connect(): Generator
    {
        if ($this->isConnected) {
            yield "Already connected to {$this->host}:{$this->port}";
            return true;
        }

        try {
            $this->socket = @stream_socket_client(
                "{$this->protocol}://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                $this->timeout
            );

            if (!$this->socket) {
                throw new InvalidArgumentException("Failed to connect to {$this->host}:{$this->port} - $errstr ($errno)");
            }

            stream_set_timeout($this->socket, $this->timeout);
            stream_set_blocking($this->socket, false);

            $this->isConnected = true;
            $this->reconnectAttempts = 0;

            yield $this->triggerEvent('connected', "Connected to {$this->host}:{$this->port} using {$this->protocol} protocol.");
            return true;

        } catch (Throwable $e) {
            yield $this->triggerEvent('connection_failed', $e->getMessage());

            if ($this->shouldReconnect && $this->reconnectAttempts < $this->maxReconnectAttempts) {
                yield from $this->attemptReconnect();
            }

            throw $e;
        }
    }

    public function disconnect(): Generator
    {
        if (!$this->isConnected) {
            yield "No active connection to close.";
            return;
        }

        try {
            if ($this->socket) {
                fclose($this->socket);
                $this->socket = null;
            }

            $this->isConnected = false;
            $this->shouldReconnect = false;

            yield $this->triggerEvent('disconnected', "Connection to {$this->host}:{$this->port} closed.");

        } catch (Throwable $e) {
            yield $this->triggerEvent('error', "Error during disconnect: " . $e->getMessage());
        }
    }

    public function handleTCP(): Generator
    {
        if ($this->protocol !== 'tcp') {
            throw new InvalidArgumentException("handleTCP can only be used with TCP protocol.");
        }

        if (!$this->isConnected) {
            yield from $this->connect();
        }

        while ($this->isConnected) {
            try {
                $data = yield from $this->readDataAsync();

                if ($data === false || $data === '') {
                    if (feof($this->socket)) {
                        yield $this->triggerEvent('connection_lost', "Connection lost");

                        if ($this->shouldReconnect) {
                            yield from $this->attemptReconnect();
                            continue;
                        }
                        break;
                    }

                    yield from VOsaka::sleep(0.01);
                    continue;
                }

                yield $this->triggerEvent('data_received', $data);

            } catch (Throwable $e) {
                yield $this->triggerEvent('error', "TCP handling error: " . $e->getMessage());
                break;
            }
        }
    }

    public function handleUDP(): Generator
    {
        if ($this->protocol !== 'udp') {
            throw new InvalidArgumentException("handleUDP can only be used with UDP protocol.");
        }

        if (!$this->isConnected) {
            yield from $this->connect();
        }

        while ($this->isConnected) {
            try {
                $data = @stream_socket_recvfrom($this->socket, $this->bufferSize, 0, $peer);

                if ($data === false || $data === '') {
                    yield from VOsaka::sleep(0.01);
                    continue;
                }

                yield $this->triggerEvent('data_received', ['data' => $data, 'peer' => $peer]);

            } catch (Throwable $e) {
                yield $this->triggerEvent('error', "UDP handling error: " . $e->getMessage());
                break;
            }
        }
    }

    private function readDataAsync(): Generator
    {
        $startTime = microtime(true);

        while (microtime(true) - $startTime < $this->timeout) {
            $data = @fread($this->socket, $this->bufferSize);

            if ($data !== false && $data !== '') {
                return $data;
            }

            if (feof($this->socket)) {
                return false;
            }

            yield from VOsaka::sleep(0.001);
        }

        throw new InvalidArgumentException("Read timeout exceeded");
    }

    public function send(string $data): Generator
    {
        if (!$this->isConnected) {
            throw new InvalidArgumentException("Not connected to any socket");
        }

        try {
            $bytesWritten = @fwrite($this->socket, $data);

            if ($bytesWritten === false) {
                throw new InvalidArgumentException("Failed to send data");
            }

            yield $this->triggerEvent('data_sent', "Sent {$bytesWritten} bytes");
            return $bytesWritten;

        } catch (Throwable $e) {
            yield $this->triggerEvent('error', "Send error: " . $e->getMessage());
            throw $e;
        }
    }

    private function attemptReconnect(): Generator
    {
        $this->reconnectAttempts++;
        $delay = min($this->reconnectDelay * pow(2, $this->reconnectAttempts - 1), 60);

        yield $this->triggerEvent('reconnecting', "Attempting reconnection {$this->reconnectAttempts}/{$this->maxReconnectAttempts} in {$delay}s");

        yield from VOsaka::sleep($delay);

        try {
            $this->isConnected = false;
            yield from $this->connect();
        } catch (Throwable $e) {
            yield $this->triggerEvent('reconnect_failed', "Reconnection failed: " . $e->getMessage());

            if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
                $this->shouldReconnect = false;
                throw new InvalidArgumentException("Max reconnection attempts reached");
            }
        }
    }

    public function handleWebSocket(): Generator
    {
        if ($this->protocol !== 'websocket') {
            throw new InvalidArgumentException("handleWebSocket can only be used with WebSocket protocol.");
        }

        yield $this->triggerEvent('websocket_ready', "WebSocket handler initialized (implementation pending)");

        while ($this->isConnected) {
            try {
                yield from VOsaka::sleep(0.1);

            } catch (Throwable $e) {
                yield $this->triggerEvent('error', "WebSocket error: " . $e->getMessage());
                break;
            }
        }
    }

    public function on(string $event, callable $handler): void
    {
        if (!isset($this->eventHandlers[$event])) {
            $this->eventHandlers[$event] = [];
        }
        $this->eventHandlers[$event][] = $handler;
    }

    public function off(string $event, ?callable $handler = null): void
    {
        if (!isset($this->eventHandlers[$event])) {
            return;
        }

        if ($handler === null) {
            unset($this->eventHandlers[$event]);
            return;
        }

        $this->eventHandlers[$event] = array_filter(
            $this->eventHandlers[$event],
            fn($h) => $h !== $handler
        );
    }

    private function triggerEvent(string $event, mixed $data = null): string
    {
        if (isset($this->eventHandlers[$event])) {
            foreach ($this->eventHandlers[$event] as $handler) {
                $handler($data, $this);
            }
        }
        if (is_array($data)) {
            return json_encode($data);
        }
        return $data;
    }

    public function enableAutoReconnect(int $maxAttempts = 5, int $delay = 1): void
    {
        $this->shouldReconnect = true;
        $this->maxReconnectAttempts = $maxAttempts;
        $this->reconnectDelay = $delay;
    }

    public function disableAutoReconnect(): void
    {
        $this->shouldReconnect = false;
    }

    public static function connectMultiple(array $sockets): Generator
    {
        $tasks = [];

        foreach ($sockets as $socket) {
            if (!$socket instanceof self) {
                throw new InvalidArgumentException("All items must be VSocket instances");
            }

            $tasks[] = $socket->connect();
        }

        yield VOsaka::join(...$tasks);
    }

    public static function raceConnect(array $sockets): Generator
    {
        $tasks = [];

        foreach ($sockets as $socket) {
            if (!$socket instanceof self) {
                throw new InvalidArgumentException("All items must be VSocket instances");
            }

            $tasks[] = $socket->connect();
        }

        return yield VOsaka::select(...$tasks);
    }

    public function ping(): Generator
    {
        if ($this->protocol !== 'tcp') {
            throw new InvalidArgumentException("Ping is only supported for TCP sockets");
        }

        $startTime = microtime(true);

        try {
            yield from $this->send("PING\n");

            $response = yield from $this->readDataAsync();
            $endTime = microtime(true);

            $latency = ($endTime - $startTime) * 1000;

            yield $this->triggerEvent('ping_response', [
                'response' => $response,
                'latency' => $latency
            ]);

            return $latency;

        } catch (Throwable $e) {
            yield $this->triggerEvent('ping_failed', $e->getMessage());
            throw $e;
        }
    }

    public function isConnected(): bool
    {
        return $this->isConnected;
    }

    public function getSocket(): mixed
    {
        return $this->socket;
    }

    public function getConnectionInfo(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'protocol' => $this->protocol,
            'timeout' => $this->timeout,
            'connected' => $this->isConnected,
            'auto_reconnect' => $this->shouldReconnect,
            'reconnect_attempts' => $this->reconnectAttempts,
            'max_reconnect_attempts' => $this->maxReconnectAttempts
        ];
    }
}