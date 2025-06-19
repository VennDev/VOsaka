<?php

declare(strict_types=1);

namespace venndev\vosaka;

use RuntimeException;


final class VChannel {

    private static array $channels = [];
    private int $id;
    private static int $nextId = 0;

    public function __construct() {
        $this->id = self::$nextId++;
        self::$channels[$this->id] = [];
    }

    public function send(mixed $data): void {
        if (!isset(self::$channels[$this->id])) {
            throw new RuntimeException("Channel {$this->id} does not exist");
        }
        self::$channels[$this->id][] = $data;
    }

    public function receive(): array {
        if (!isset(self::$channels[$this->id]) || empty(self::$channels[$this->id])) {
            throw new RuntimeException("No data available in channel {$this->id}");
        }
        $result = array_shift(self::$channels[$this->id]);
        if (!is_array($result)) {
            $result = [$result];
        }
        return $result;
    }

    public function hasData(): bool {
        return isset(self::$channels[$this->id]) && !empty(self::$channels[$this->id]);
    }

    public function close(): void {
        unset(self::$channels[$this->id]);
    }
}