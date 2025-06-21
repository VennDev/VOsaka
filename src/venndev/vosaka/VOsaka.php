<?php

declare(strict_types=1);

namespace venndev\vosaka;

use Generator;
use RuntimeException;
use SplQueue;
use InvalidArgumentException;
use Closure;
use Throwable;
use WeakMap;

final class VOsaka
{
    private static ?SplQueue $taskQueue = null;
    private static array $deferredTasks = [];
    private static array $timeoutTasks = [];
    private static array $errorsTasks = [];
    private static int $nextTaskId = 0;

    // Maximum tasks to run per period
    private static int $maximumPeriod = 20;
    private static bool $enableMaximumPeriod = false;
    private static int $maxConcurrentTasks = 100; // Increased back to 100
    private static bool $enableLogging = false;

    public static ?MemoryManager $memoryManager = null;

    // Memory optimization additions
    private static WeakMap $taskReferences;
    private static int $cleanupCounter = 0;
    private static int $forceCleanupThreshold = 50; // Reduced for more frequent cleanup

    /**
     * Initialize static properties to prevent memory leaks
     */
    private static function initializeStatic(): void
    {
        if (!isset(self::$taskReferences)) {
            self::$taskReferences = new WeakMap();
        }
    }

    /**
     * Enable or disable error logging
     */
    public static function setEnableLogging(bool $enable): void
    {
        self::$enableLogging = $enable;
    }

    /**
     * Execute multiple tasks concurrently and wait for all to complete
     */
    public static function join(Generator ...$tasks): void
    {
        self::initializeTaskQueue();
        self::initializeStatic();
        self::enqueueTasks($tasks);
        self::run();
        self::cleanup();
    }

    /**
     * Spawn a task to run in background without blocking
     */
    public static function spawn(Generator|Closure $task): void
    {
        self::initializeTaskQueue();
        self::initializeStatic();
        $generator = self::convertToGenerator($task);
        self::enqueueTask($generator);
    }

    /**
     * Await a single task completion
     */
    public static function await(Generator|Closure $task): Result
    {
        self::initializeTaskQueue();
        self::initializeStatic();
        $fn = function () use ($task): Generator {
            $generator = self::convertToGenerator($task);
            $taskWrapper = self::enqueueTask($generator, true);

            while ($taskWrapper->task->valid()) {
                yield;
            }

            try {
                return $taskWrapper->task->getReturn();
            } catch (Throwable $e) {
                $result = self::getErrorFromTaskAndRemove($taskWrapper);
                return $result ?: $e;
            }
        };

        $result = new Result($fn());
        self::performPeriodicCleanup();
        return $result;
    }

    /**
     * Execute the first task that completes
     */
    public static function select(Generator ...$tasks): void
    {
        self::initializeTaskQueue();
        self::initializeStatic();
        self::enqueueTasks($tasks);
        self::executeOneTask();
        self::cleanup();
    }

    /**
     * Set the maximum number of tasks to run per period
     */
    public static function setMaximumPeriod(int $maxTasks): void
    {
        if ($maxTasks <= 0) {
            throw new InvalidArgumentException('Maximum tasks must be a positive integer');
        }
        self::$maximumPeriod = $maxTasks;
    }

    /**
     * Enable or disable the maximum period limit
     */
    public static function setEnableMaximumPeriod(bool $enable): void
    {
        self::$enableMaximumPeriod = $enable;
    }

    /**
     * Set maximum concurrent tasks
     */
    public static function setMaxConcurrentTasks(int $maxConcurrent): void
    {
        if ($maxConcurrent <= 0) {
            throw new InvalidArgumentException('Maximum concurrent tasks must be a positive integer');
        }
        self::$maxConcurrentTasks = $maxConcurrent;
    }

    /**
     * Sleep for specified seconds (non-blocking)
     */
    public static function sleep(float $seconds): Generator
    {
        if ($seconds <= 0) {
            return;
        }

        $endTime = microtime(true) + $seconds;
        while (microtime(true) < $endTime) {
            yield;
        }
    }

    /**
     * Retry a task with exponential backoff
     */
    public static function retry(
        callable $taskFactory,
        int $maxRetries = 3,
        int $delaySeconds = 1,
        int $backOffMultiplier = 2,
        ?callable $shouldRetry = null
    ): Generator {
        $retries = 0;

        while ($retries < $maxRetries) {
            try {
                $task = $taskFactory();
                if (!$task instanceof Generator) {
                    throw new InvalidArgumentException('Task must return a Generator');
                }
                yield from $task;
                return;
            } catch (Throwable $e) {
                if ($shouldRetry && !$shouldRetry($e)) {
                    throw $e;
                }
                $retries++;
                if ($retries >= $maxRetries) {
                    throw new RuntimeException("Task failed after {$maxRetries} retries", 0, $e);
                }
                $delay = (int) ($delaySeconds * pow($backOffMultiplier, $retries - 1));
                yield from self::sleep($delay);
            }
        }
    }

    /**
     * Create a timeout constraint
     */
    public static function timeout(int $seconds): Timeout
    {
        return new Timeout($seconds);
    }

    /**
     * Create a deferred task
     */
    public static function defer(Closure $task, mixed ...$args): Defer
    {
        return new Defer($task, ...$args);
    }

    /**
     * Create and enqueue a repeating task
     */
    public static function repeat(Closure $task, int $interval = 1): RepeatTask
    {
        $taskWrapper = new RepeatTask(fn() => $task, self::generateTaskId(), $interval);
        self::initializeTaskQueue();
        self::$taskQueue->enqueue($taskWrapper);
        return $taskWrapper;
    }

    /**
     * Run all pending tasks
     */
    public static function run(): void
    {
        self::executeAllTasks();
    }

    /**
     * Force cleanup of all static resources
     */
    public static function cleanup(): void
    {
        self::$deferredTasks = [];
        self::$timeoutTasks = [];
        self::$errorsTasks = [];
        self::$taskReferences = new WeakMap();

        if (self::$taskQueue !== null) {
            while (!self::$taskQueue->isEmpty()) {
                self::$taskQueue->dequeue();
            }
            self::$taskQueue = null;
        }

        if (self::$memoryManager !== null) {
            self::$memoryManager->forceGarbageCollection();
        }

        self::$cleanupCounter = 0;
        gc_collect_cycles();
    }

    /**
     * Periodic cleanup to prevent memory accumulation
     */
    private static function performPeriodicCleanup(): void
    {
        self::$cleanupCounter++;

        if (self::$cleanupCounter >= self::$forceCleanupThreshold) {
            $activeTaskIds = [];
            if (self::$taskQueue !== null) {
                $tempQueue = new SplQueue();
                while (!self::$taskQueue->isEmpty()) {
                    $task = self::$taskQueue->dequeue();
                    $activeTaskIds[$task->id] = true;
                    $tempQueue->enqueue($task);
                }
                self::$taskQueue = $tempQueue;
            }

            self::$deferredTasks = array_intersect_key(self::$deferredTasks, $activeTaskIds);
            self::$timeoutTasks = array_intersect_key(self::$timeoutTasks, $activeTaskIds);
            self::$errorsTasks = array_slice(self::$errorsTasks, -10, null, true);

            self::$cleanupCounter = 0;
            gc_collect_cycles();

            if (self::$enableLogging) {
                error_log("VOsaka: Periodic cleanup performed, memory: " . (memory_get_usage(true) / 1024 / 1024) . " MB");
            }
        }
    }

    // Private helper methods
    private static function generateTaskId(): int
    {
        return self::$nextTaskId >= PHP_INT_MAX ? self::$nextTaskId = 0 : self::$nextTaskId++;
    }

    private static function initializeTaskQueue(): void
    {
        if (self::$taskQueue === null) {
            self::$taskQueue = new SplQueue();
            gc_collect_cycles();
        }
    }

    private static function initializeMemoryManager(): void
    {
        if (self::$memoryManager === null) {
            self::$memoryManager = new MemoryManager(32, 25);
        }
    }

    private static function addErrorToTask(Task $task, Throwable $error): void
    {
        self::$errorsTasks[$task->id] = $error;
        if (self::$enableLogging) {
            error_log("VOsaka Task {$task->id} failed: " . $error->getMessage());
        }

        if (count(self::$errorsTasks) > 25) {
            self::$errorsTasks = array_slice(self::$errorsTasks, -10, null, true);
        }
    }

    private static function getErrorFromTaskAndRemove(Task $task): mixed
    {
        if (isset(self::$errorsTasks[$task->id])) {
            $error = self::$errorsTasks[$task->id];
            unset(self::$errorsTasks[$task->id]);
            return $error;
        }
        return null;
    }

    private static function enqueueTasks(array $tasks): void
    {
        foreach ($tasks as $task) {
            self::enqueueTask($task);
        }
    }

    public static function enqueueTask(Generator $generator, bool $await = false): Task
    {
        $taskWrapper = new Task($generator, $await, self::generateTaskId());
        self::$taskQueue->enqueue($taskWrapper);
        self::$taskReferences[$taskWrapper] = $taskWrapper->id;
        return $taskWrapper;
    }

    private static function executeAllTasks(): void
    {
        self::initializeTaskQueue();
        self::executeTasks(false, true);
    }

    private static function executeOneTask(): void
    {
        self::initializeTaskQueue();
        self::executeTasks(true);
    }

    private static function executeTasks(bool $stopAfterFirst = false, bool $runUntilEmpty = false): void
    {
        self::initializeMemoryManager();
        self::$memoryManager->init();

        $runningTasks = [];
        $dynamicConcurrent = self::$maxConcurrentTasks;

        while (!$stopAfterFirst || !empty($runningTasks) || !$runUntilEmpty || !self::$taskQueue->isEmpty()) {
            $memoryUsage = memory_get_usage(true) / 1024 / 1024;
            while (count($runningTasks) < $dynamicConcurrent && !self::$taskQueue->isEmpty()) {
                $taskWrapper = self::$taskQueue->dequeue();
                $runningTasks[$taskWrapper->id] = $taskWrapper;
            }

            foreach ($runningTasks as $id => $taskWrapper) {
                try {
                    if ($taskWrapper instanceof Task) {
                        if ($taskWrapper->task->valid()) {
                            $yieldedValue = $taskWrapper->task->current();
                            $taskWrapper->task->next();
                            self::handleYieldedValue($taskWrapper, $yieldedValue);
                            self::checkForTimeouts($taskWrapper->id);
                        }

                        if (!$taskWrapper->task->valid()) {
                            self::executeCleanupTasks($taskWrapper->id);
                            unset($runningTasks[$id], self::$taskReferences[$taskWrapper]);
                        } else {
                            self::$taskQueue->enqueue($taskWrapper);
                        }
                    } elseif ($taskWrapper instanceof RepeatTask && $taskWrapper->canRun()) {
                        self::handleRepeatTask($taskWrapper);
                        $taskWrapper->resetTime();
                        self::$taskQueue->enqueue($taskWrapper);
                    }
                } catch (Throwable $e) {
                    self::executeCleanupTasks($taskWrapper->id);
                    unset($runningTasks[$id], self::$taskReferences[$taskWrapper]);
                    self::addErrorToTask($taskWrapper, $e);
                }
            }

            self::performPeriodicCleanup();
            if (!self::$memoryManager->checkMemoryUsage()) {
                error_log("VOsaka: Memory limit exceeded ($memoryUsage MB), stopping execution");
                break;
            }

            if ($stopAfterFirst && empty($runningTasks)) {
                break;
            }

            if ($runUntilEmpty && self::$taskQueue->isEmpty() && empty($runningTasks)) {
                break;
            }
        }

        self::$memoryManager->forceGarbageCollection();
        self::cleanup();
    }

    private static function handleRepeatTask(RepeatTask $taskWrapper): void
    {
        $result = ($taskWrapper->task)();
        self::resolveAndSpawn($result);
    }

    private static function resolveAndSpawn(mixed $result): void
    {
        if ($result instanceof Closure) {
            self::resolveAndSpawn($result());
            return;
        }

        if ($result instanceof Generator) {
            self::spawn($result);
        }
    }

    private static function handleYieldedValue(Task $taskWrapper, mixed $yieldedValue): void
    {
        if ($yieldedValue instanceof Timeout) {
            self::registerTimeout($taskWrapper, $yieldedValue);
        } elseif ($yieldedValue instanceof Defer) {
            self::registerDeferredTask($taskWrapper, $yieldedValue);
        }
    }

    private static function registerTimeout(Task $taskWrapper, Timeout $timeout): void
    {
        self::$timeoutTasks[$taskWrapper->id] = $timeout;
    }

    private static function registerDeferredTask(Task $taskWrapper, Defer $defer): void
    {
        self::$deferredTasks[$taskWrapper->id] = (object) [
            'task' => $defer->task,
            'args' => $defer->args
        ];
    }

    private static function checkForTimeouts(int $taskId): void
    {
        if (!isset(self::$timeoutTasks[$taskId])) {
            return;
        }

        $timeout = self::$timeoutTasks[$taskId];
        if ($timeout->isTimeout()) {
            unset(self::$timeoutTasks[$taskId]);
            self::executeCleanupTasks($taskId);
            throw new InvalidArgumentException("Task with ID {$taskId} has timed out.");
        }
    }

    private static function executeCleanupTasks(int $taskId): void
    {
        self::executeDeferredTasks($taskId);
        self::cleanupTimeouts($taskId);
    }

    private static function executeDeferredTasks(int $taskId): void
    {
        if (!isset(self::$deferredTasks[$taskId])) {
            return;
        }

        $deferWrapper = self::$deferredTasks[$taskId];
        $deferredTask = $deferWrapper->task;
        $result = $deferredTask(...$deferWrapper->args);

        if ($result instanceof Generator) {
            self::exhaustGenerator($result);
        }

        unset(self::$deferredTasks[$taskId]);
    }

    private static function cleanupTimeouts(int $taskId): void
    {
        unset(self::$timeoutTasks[$taskId]);
    }

    private static function exhaustGenerator(Generator $generator): void
    {
        while ($generator->valid()) {
            $generator->next();
        }
        unset($generator);
    }

    private static function convertToGenerator(Generator|Closure $task): Generator
    {
        if ($task instanceof Generator) {
            return $task;
        }

        if ($task instanceof Closure) {
            $result = $task();
            if (!$result instanceof Generator) {
                throw new InvalidArgumentException('Closure must return a Generator instance');
            }
            return $result;
        }

        throw new InvalidArgumentException('Task must be a Generator or Closure that returns a Generator');
    }
}