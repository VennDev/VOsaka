<?php

declare(strict_types=1);

namespace venndev\vosaka;

/**
 * Optimized MemoryManager class with better performance characteristics
 */
class MemoryManager
{
    private float $memoryLimit; // MB
    private int $gcInterval; // Collect garbage every X tasks
    private int $taskCounter = 0;
    private float $lastMemoryUsage = 0;
    private int $memoryCheckCounter = 0;
    private const AGGRESSIVE_GC_THRESHOLD = 0.8; // 80% of memory limit

    public function __construct(float $memoryLimit = 128, int $gcInterval = 200)
    {
        $this->memoryLimit = $memoryLimit;
        $this->gcInterval = $gcInterval;
    }

    public function init(): void
    {
        gc_enable();
        gc_collect_cycles();
        $this->taskCounter = 0;
        $this->lastMemoryUsage = memory_get_usage(true) / 1024 / 1024;
        $this->memoryCheckCounter = 0;
    }

    public function checkMemoryUsage(): bool
    {
        $this->memoryCheckCounter++;

        // Check memory less frequently for better performance
        if ($this->memoryCheckCounter % 50 === 0) {
            $currentUsage = memory_get_usage(true) / 1024 / 1024;

            // Aggressive GC if approaching limit
            if ($currentUsage > ($this->memoryLimit * self::AGGRESSIVE_GC_THRESHOLD)) {
                $this->forceGarbageCollection();
                $currentUsage = memory_get_usage(true) / 1024 / 1024;
            }

            $this->lastMemoryUsage = $currentUsage;
            return $currentUsage < $this->memoryLimit;
        }

        // Use cached value for better performance
        return $this->lastMemoryUsage < $this->memoryLimit;
    }

    public function collectGarbage(): void
    {
        $this->taskCounter++;
        if ($this->taskCounter >= $this->gcInterval) {
            $this->performGarbageCollection();
            $this->taskCounter = 0;
        }
    }

    public function forceGarbageCollection(): void
    {
        $this->performGarbageCollection();
        $this->taskCounter = 0;
    }

    private function performGarbageCollection(): void
    {
        // Clear any circular references
        gc_collect_cycles();

        // Update memory usage after GC
        $this->lastMemoryUsage = memory_get_usage(true) / 1024 / 1024;
    }

    public function getCurrentMemoryUsage(): float
    {
        return memory_get_usage(true) / 1024 / 1024;
    }

    public function getMemoryLimit(): float
    {
        return $this->memoryLimit;
    }

    public function getMemoryPercentage(): float
    {
        return ($this->getCurrentMemoryUsage() / $this->memoryLimit) * 100;
    }
}