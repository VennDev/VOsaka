<?php

declare(strict_types=1);

namespace venndev\vosaka;

use Generator;
use RuntimeException;
use SplQueue;
use InvalidArgumentException;
use Closure;
use SplObjectStorage;
use stdClass;
use Throwable;

/**
 * Async task scheduler for PHP using generators
 */
final class VOsaka
{
    private static ?SplQueue $taskQueue = null;
    private static ?SplObjectStorage $deferredTasks = null;
    private static ?SplObjectStorage $timeoutTasks = null;
    private static array $errorsTasks = [];
    private static int $nextTaskId = 0;

    // Maximum tasks to run per period
    private static int $maximumPeriod = 20;
    private static bool $enableMaximumPeriod = false;

    public static MemoryManager $memoryManager = new MemoryManager();

    /**
     * Execute multiple tasks concurrently and wait for all to complete
     */
    public static function join(Generator ...$tasks): void
    {
        self::initializeTaskQueue();
        self::enqueueTasks($tasks);
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
    public static function sleep(float $seconds): Generator
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
     * Retry a task with exponential backoff
     * 
     * @param callable $taskFactory A callable that returns a Generator
     * @param int $maxRetries Maximum number of retries
     * @param int $delaySeconds Initial delay in seconds
     * @param int $backOffMultiplier Multiplier for exponential backoff
     * @param callable|null $shouldRetry Optional callback to determine if the task should be retried
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
                    throw new InvalidArgumentException(
                        'Task must return a Generator'
                    );
                }
                yield from $task;
                return;
            } catch (Throwable $e) {
                if ($shouldRetry && !$shouldRetry($e)) {
                    throw $e;
                }
                $retries++;
                if ($retries >= $maxRetries) {
                    throw new RuntimeException(
                        "Task failed after {$maxRetries} retries",
                        0,
                        $e
                    );
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
        self::initializeTimeoutQueue();
        return new Timeout($seconds);
    }

    /**
     * Create a deferred task
     */
    public static function defer(Closure $task, mixed ...$args): Defer
    {
        return new Defer($task, ...$args);
    }

    private static function createRepeatTask(Generator|Closure $task, int $interval): Task|RepeatTask
    {
        return self::buildTask(
            callable: $task,
            repeat: true,
            interval: $interval
        );
    }

    public static function repeat(Closure $task, int $interval = 1): RepeatTask
    {
        $taskWrapper = self::createRepeatTask($task, $interval);
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

    private static function addErrorToTask(Task $task, Throwable $error): void
    {
        self::$errorsTasks[$task->id] = $error;
    }

    private static function getErrorFromTaskAndRemove(Task $task): mixed
    {
        if (isset(self::$errorsTasks[$task->id])) {
            /**
             * @var Throwable $error
             */
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

    private static function buildTask(
        Generator|Closure $callable,
        bool $await = false,
        bool $repeat = false,
        int $interval = 0
    ): Task|RepeatTask {
        if ($repeat) {
            return new RepeatTask(
                fn() => $callable,
                self::generateTaskId(),
                $interval
            );
        }
        return new Task($callable, $await, self::generateTaskId());
    }

    public static function enqueueTask(Generator $generator, bool $await = false): Task
    {
        $taskWrapper = self::buildTask($generator, $await);
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
        self::$memoryManager->init(); // Initialize memory manager

        while (true) {
            if (self::$taskQueue->isEmpty()) {
                if (!$runUntilEmpty) {
                    return;
                }
                continue;
            }

            $taskWrapper = self::$taskQueue->dequeue();

            if ($taskWrapper instanceof Task) {
                if ($taskWrapper->isRunning) {
                    self::$taskQueue->enqueue($taskWrapper);
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
                    }
                } catch (Throwable $e) {
                    $taskWrapper->isRunning = false;
                    self::executeCleanupTasks($taskWrapper->id);
                    if ($taskWrapper->await) {
                        self::addErrorToTask($taskWrapper, $e);
                    } else {
                        throw $e;
                    }
                }
            }

            if ($taskWrapper instanceof RepeatTask) {
                if ($taskWrapper->canRun()) {
                    self::handleRepeatTask($taskWrapper);
                    $taskWrapper->resetTime();
                }
                self::$taskQueue->enqueue($taskWrapper);
            }

            // Check memory usage and stop if needed
            if (!self::$memoryManager->checkMemoryUsage()) {
                return;
            }

            if ($stopAfterFirst) {
                return;
            }
        }
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
        $deferWrapper->args = $defer->args;

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
            $result = $deferredTask(...$deferWrapper->args);

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
