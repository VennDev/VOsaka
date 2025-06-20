<?php

namespace venndev\vosaka;

use Closure;

final class RepeatTask
{

    private int $time;

    public function __construct(
        public readonly Closure $task,
        public readonly int $id = 0,
        public readonly int $interval = 0,
    ) {
        $this->time = time();
    }

    public function canRun(): bool
    {
        return $this->interval > 0 && (time() - $this->time) >= $this->interval;
    }

    public function resetTime(): void
    {
        $this->time = time();
    }
}