<?php

namespace venndev\vosaka;

use Closure;
use Generator;

final class Task
{

    public readonly int $time;
    public bool $isRunning = false;

    public function __construct(
        public Generator $task,
        public readonly bool $await = false,
        public readonly int $id = 0,
    ) {
        $this->time = time();
    }
}