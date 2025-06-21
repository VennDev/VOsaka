<?php

declare(strict_types=1);

namespace venndev\vosaka;

use Generator;
use RuntimeException;
use SplQueue;
use InvalidArgumentException;
use Closure;
use Throwable;

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
    private static int $maxConcurrentTasks = 100; // Limit concurrent tasks
    private static bool $enableLogging = false; // Enable/disable error logging

    public static ?MemoryManager $memoryManager = null;

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
        self::enqueueTasks($tasks);
        self::run();
    }

    /**
     * Spawn a task to run in background without blocking
     */
    public static function spawn(Generator|Closure $task): void
    {
        self::initializeTaskQueue();
        $generator = self::convertToGenerator($task);
        self::enqueueTask($generator);
    }

    /**
     * Await a single task completion
     */
    public static function await(Generator|Closure $task): Result
    {
        self::initializeTaskQueue();
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
        return new Result($fn());
    }

    /**
     * Execute the first task that completes
     */
    public static function select(Generator ...$tasks): void
    {
        self::initializeTaskQueue();
        self::enqueueTasks($tasks);
        self::executeOneTask();
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

    // Private helper methods
    private static function generateTaskId(): int
    {
        return self::$nextTaskId >= PHP_INT_MAX ? self::$nextTaskId = 0 : self::$nextTaskId++;
    }

    private static function initializeTaskQueue(): void
    {
        if (self::$taskQueue === null) {
            self::$taskQueue = new SplQueue();
        }
    }

    private static function initializeMemoryManager(): void
    {
        if (self::$memoryManager === null) {
            self::$memoryManager = new MemoryManager();
        }
    }

    private static function addErrorToTask(Task $task, Throwable $error): void
    {
        self::$errorsTasks[$task->id] = $error;
        if (self::$enableLogging) {
            error_log("VOsaka Task {$task->id} failed: " . $error->getMessage());
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
        $taskCount = 0;

        while (!$stopAfterFirst || count($runningTasks) > 0 || !$runUntilEmpty || !self::$taskQueue->isEmpty()) {
            while (count($runningTasks) < self::$maxConcurrentTasks && !self::$taskQueue->isEmpty()) {
                $taskWrapper = self::$taskQueue->dequeue();
                if ($taskWrapper instanceof Task || $taskWrapper instanceof RepeatTask) {
                    $runningTasks[$taskWrapper->id] = $taskWrapper;
                    $taskCount++;
                }
            }

            foreach ($runningTasks as $id => $taskWrapper) {
                if ($taskWrapper instanceof Task) {
                    if ($taskWrapper->isRunning) {
                        continue;
                    }

                    $taskWrapper->isRunning = true;

                    try {
                        if ($taskWrapper->task->valid()) {
                            $yieldedValue = $taskWrapper->task->current();
                            $taskWrapper->task->next();
                            self::handleYieldedValue($taskWrapper, $yieldedValue);
                            self::checkForTimeouts($taskWrapper->id);
                        }

                        $taskWrapper->isRunning = false;
                        if ($taskWrapper->task->valid()) {
                            self::$taskQueue->enqueue($taskWrapper);
                        } else {
                            self::executeCleanupTasks($taskWrapper->id);
                            unset($runningTasks[$id]);
                        }
                    } catch (Throwable $e) {
                        $taskWrapper->isRunning = false;
                        self::executeCleanupTasks($taskWrapper->id);
                        unset($runningTasks[$id]);
                        if ($taskWrapper->await) {
                            self::addErrorToTask($taskWrapper, $e);
                        } else {
                            self::$errorsTasks[$id] = $e;
                            if (self::$enableLogging) {
                                error_log("VOsaka Task {$id} failed: " . $e->getMessage());
                            }
                        }
                    }
                } elseif ($taskWrapper instanceof RepeatTask) {
                    if ($taskWrapper->canRun()) {
                        self::handleRepeatTask($taskWrapper);
                        $taskWrapper->resetTime();
                    }
                    self::$taskQueue->enqueue($taskWrapper);
                    unset($runningTasks[$id]);
                }

                if (self::$enableMaximumPeriod && $taskCount >= self::$maximumPeriod) {
                    $taskCount = 0;
                    self::$memoryManager->collectGarbage(); // Force GC
                    break 2;
                }
            }

            if (!self::$memoryManager->checkMemoryUsage()) {
                error_log("VOsaka: Memory limit exceeded, stopping execution");
                break;
            }
            self::$memoryManager->collectGarbage();

            if ($stopAfterFirst && count($runningTasks) === 0) {
                break;
            }

            if ($runUntilEmpty && self::$taskQueue->isEmpty() && count($runningTasks) === 0) {
                break;
            }

            usleep(100);
        }

        self::$memoryManager->collectGarbage();
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
