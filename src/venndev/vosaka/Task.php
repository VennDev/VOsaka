<?php

namespace venndev\vosaka;

use Generator;

final class Task {

    public readonly int $time;
    public bool $isRunning = false;

    public function __construct(
        public readonly Generator $task,
        public readonly int $id = 0
    ) {
        if (!$this->task instanceof Generator) {
            throw new \InvalidArgumentException('Task must be a Generator');
        }

        $this->time = time();
    }
}