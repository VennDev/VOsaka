<?php

namespace venndev\vosaka;

final class Timeout {

    private readonly int $timeout;

    public function __construct(
        public readonly int $seconds = 0
    ) {
        if ($seconds < 0) {
            throw new \InvalidArgumentException('Timeout seconds must be a non-negative integer');
        }

        $this->timeout = time();
    }

    public function isTimeout(): bool
    {
        if ($this->seconds <= 0) {
            return false;
        }

        if ($this->timeout === 0) {
            $this->timeout = time() + $this->seconds;
        }

        return time() >= $this->timeout; 
    }
}