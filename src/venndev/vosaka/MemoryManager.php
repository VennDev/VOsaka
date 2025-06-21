<?php

declare(strict_types=1);

namespace venndev\vosaka;

final class MemoryManager
{
    private bool $isInitialized = false;
    private int $maxMemoryUsage = 0;

    /**
     * Initializes the MemoryManager with a specified maximum memory usage.
     * If no value is provided, it defaults to the PHP memory limit.
     *
     * @param int $maxMemoryUsage Maximum memory usage in bytes (0 for default PHP limit).
     */
    public function init(int $maxMemoryUsage = 0): void
    {
        if ($this->isInitialized) {
            return;
        }

        if ($maxMemoryUsage > 0) {
            $this->maxMemoryUsage = $maxMemoryUsage;
        } else {
            $limit = ini_get('memory_limit') ?: '128M';
            $this->maxMemoryUsage = MemoryUtils::parseIniMemory($limit);
        }

        $this->isInitialized = true;
    }

    public function setMaxMemoryUsage(int $bytes): void
    {
        $this->maxMemoryUsage = $bytes;
    }

    public function checkMemoryUsage(): bool
    {
        $currentUsage = memory_get_usage();

        if ($currentUsage > $this->maxMemoryUsage) {
            $this->forceGarbageCollection();

            if (memory_get_usage() > $this->maxMemoryUsage) {
                return false; // Memory limit exceeded
            }
        }

        return true; // Memory usage is within limits
    }

    private function forceGarbageCollection(): void
    {
        gc_collect_cycles();
    }
}