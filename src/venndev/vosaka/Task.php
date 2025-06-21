<?php

namespace venndev\vosaka;

use Generator;

final class Task
{
    public Generator $task;
    public bool $await;
    public int $id;
    public bool $isRunning = false;

    public function __construct(Generator $task, bool $await, int $id)
    {
        $this->task = $task;
        $this->await = $await;
        $this->id = $id;
    }
}