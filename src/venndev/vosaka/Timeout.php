<?php

namespace venndev\vosaka;

final class Timeout
{
    private float $startTime;
    private int $timeoutSeconds;

    public function __construct(int $timeoutSeconds)
    {
        $this->startTime = microtime(true);
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function isTimeout(): bool
    {
        return (microtime(true) - $this->startTime) >= $this->timeoutSeconds;
    }
}