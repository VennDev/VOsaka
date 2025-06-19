<?php

namespace venndev\vosaka;

use Closure;

final class Defer {

    public mixed $args = [];

    public function __construct(
        public readonly Closure $task,
        ...$args
    ) {
        if (!$this->task instanceof Closure) {
            throw new \InvalidArgumentException('Task must be a Closure');
        }
        $this->args = $args;
    }
}