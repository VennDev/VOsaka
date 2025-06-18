<?php

namespace venndev\vosaka;

use Closure;

final class Defer {

    public function __construct(
        public readonly Closure $task
    ) {
        if (!$this->task instanceof Closure) {
            throw new \InvalidArgumentException('Task must be a Closure');
        }
    }
}