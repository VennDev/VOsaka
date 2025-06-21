<?php

namespace venndev\vosaka;

use Closure;

final class Defer
{
    public Closure $task;
    public array $args;

    public function __construct(Closure $task, mixed ...$args)
    {
        $this->task = $task;
        $this->args = $args;
    }
}