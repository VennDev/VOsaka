<?php

declare(strict_types=1);

namespace venndev\vosaka;

use Generator;
use SplQueue;
use InvalidArgumentException;
use Closure;
use SplObjectStorage;
use stdClass;

/**
 * Async task scheduler for PHP using generators
 */
final class VOsaka
{
    private static ?SplQueue $taskQueue = null;
    private static ?SplObjectStorage $deferredTasks = null;
    private static ?SplObjectStorage $timeoutTasks = null;
    private static int $nextTaskId = 0;

    // Maximum tasks to run per period
    private static int $maximumPeriod = 20;
    private static bool $enableMaximumPeriod = false;

    /**
     * Execute multiple tasks concurrently and wait for all to complete
     */
    public static function join(Generator ...$tasks): void
    {
        self::initializeTaskQueue();
        self::enqueueTasks($tasks);
        self::executeAllTasks();
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
    public static function await(Generator|Closure $task): Generator
    {
        self::initializeTaskQueue();
        $generator = self::convertToGenerator($task);
        $taskWrapper = self::enqueueTask($generator);

        while ($taskWrapper->task->valid()) {
            yield;
        }

        return $taskWrapper->task->getReturn();
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
     * Default is 20 tasks per period.
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
     * This function is used for when you need to apply 
     *      to a system or software that was originally built 
     *      not to be built asynchronously with this library.
     * To make it easy for you to visualize, go to `test4.php`
     */
    public static function setEnableMaximumPeriod(bool $enable): void
    {
        self::$enableMaximumPeriod = $enable;
    }

    /**
     * Sleep for specified seconds (non-blocking)
     */
    public static function sleep(int $seconds): Generator
    {
        if ($seconds <= 0) {
            return;
        }

        $endTime = time() + $seconds;

        while (time() < $endTime) {
            yield;
        }
    }

    /**
     * Create a timeout constraint
     */
    public static function timeout(int $seconds): Timeout
    {
        self::initializeTimeoutQueue();
        return new Timeout($seconds);
    }

    /**
     * Create a deferred task
     */
    public static function defer(Closure $task): Defer
    {
        return new Defer($task);
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
        return self::$nextTaskId++;
    }

    private static function initializeTaskQueue(): void
    {
        if (self::$taskQueue === null) {
            self::$taskQueue = new SplQueue();
        }
    }

    private static function initializeDeferredQueue(): void
    {
        if (self::$deferredTasks === null) {
            self::$deferredTasks = new SplObjectStorage();
        }
    }

    private static function initializeTimeoutQueue(): void
    {
        if (self::$timeoutTasks === null) {
            self::$timeoutTasks = new SplObjectStorage();
        }
    }

    private static function enqueueTasks(array $tasks): void
    {
        foreach ($tasks as $task) {
            self::enqueueTask($task);
        }
    }

    private static function enqueueTask(Generator $generator): Task
    {
        $taskWrapper = new Task($generator, self::generateTaskId());
        self::$taskQueue->enqueue($taskWrapper);
        return $taskWrapper;
    }

    private static function executeAllTasks(): void
    {
        self::initializeTaskQueue();
        self::executeTasks(false);
    }

    private static function executeOneTask(): void
    {
        self::initializeTaskQueue();
        self::executeTasks(true);
    }

    private static function executeTasks(bool $stopAfterFirst = false, bool $runUntilEmpty = false): void
    {
        $i = 0;
        while (!self::$taskQueue->isEmpty()) {
            $taskWrapper = self::$taskQueue->dequeue();

            if (!$taskWrapper->task->valid()) {
                self::executeCleanupTasks($taskWrapper->id);
                if ($stopAfterFirst) {
                    return;
                }
                continue;
            }

            $yieldedValue = $taskWrapper->task->current();
            $taskWrapper->task->next();

            self::handleYieldedValue($taskWrapper, $yieldedValue);
            self::checkForTimeouts($taskWrapper->id);

            if ($taskWrapper->task->valid()) {
                self::$taskQueue->enqueue($taskWrapper);
            } else {
                self::executeCleanupTasks($taskWrapper->id);
                if ($stopAfterFirst) {
                    return;
                }
            }

            if (self::$enableMaximumPeriod && ++$i >= self::$maximumPeriod) {
                if ($runUntilEmpty) {
                    continue;
                }
                return;
            }
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
        self::initializeTimeoutQueue();

        $timeoutWrapper = new stdClass();
        $timeoutWrapper->id = $taskWrapper->id;

        self::$timeoutTasks->attach($timeoutWrapper, $timeout);
    }

    private static function registerDeferredTask(Task $taskWrapper, Defer $defer): void
    {
        self::initializeDeferredQueue();

        $deferWrapper = new stdClass();
        $deferWrapper->id = $taskWrapper->id;
        $deferWrapper->task = $defer->task;

        self::$deferredTasks->attach($deferWrapper, $defer->task);
    }

    private static function checkForTimeouts(int $taskId): void
    {
        if (self::$timeoutTasks === null) {
            return;
        }

        foreach (self::$timeoutTasks as $timeoutWrapper) {
            if ($timeoutWrapper->id !== $taskId) {
                continue;
            }

            $timeout = self::$timeoutTasks[$timeoutWrapper];
            if ($timeout->isTimeout()) {
                self::$timeoutTasks->detach($timeoutWrapper);
                self::executeCleanupTasks($taskId);

                throw new InvalidArgumentException(
                    "Task with ID {$taskId} has timed out."
                );
            }
        }
    }

    private static function executeCleanupTasks(int $taskId): void
    {
        self::executeDeferredTasks($taskId);
        self::cleanupTimeouts($taskId);
    }

    private static function executeDeferredTasks(int $taskId): void
    {
        if (self::$deferredTasks === null) {
            return;
        }

        foreach (self::$deferredTasks as $deferWrapper) {
            if ($deferWrapper->id !== $taskId) {
                continue;
            }

            $deferredTask = $deferWrapper->task;
            $result = $deferredTask();

            if ($result instanceof Generator) {
                self::exhaustGenerator($result);
            }

            self::$deferredTasks->detach($deferWrapper);
        }
    }

    private static function cleanupTimeouts(int $taskId): void
    {
        if (self::$timeoutTasks === null) {
            return;
        }

        foreach (self::$timeoutTasks as $timeoutWrapper) {
            if ($timeoutWrapper->id === $taskId) {
                self::$timeoutTasks->detach($timeoutWrapper);
            }
        }
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
                throw new InvalidArgumentException(
                    'Closure must return a Generator instance'
                );
            }

            return $result;
        }

        throw new InvalidArgumentException(
            'Task must be a Generator or Closure that returns a Generator'
        );
    }
}
