# VOsaka
- A synchronous runtime library for PHP

# Basic processing methods
- Handle tasks with Sleep, Await, Spawn, Run
```php
<?php
use venndev\vosaka\VOsaka;

function work(): Generator
{
    yield from VOsaka::sleep(3);
    yield var_dump('Work completed after 3 seconds');
    return 'Work result';
}

function main(): Generator
{
    $result = yield from VOsaka::await(work());
    var_dump('Work result: ' . $result); 
}

VOsaka::spawn(main());
VOsaka::run();
```
- Handle tasks with Defer, Sleep, Join functions
```php
<?php
use venndev\vosaka\VOsaka;

function send(string $message): void
{
    var_dump($message);
}

function task1(): Generator
{
    yield VOsaka::defer(fn() => send('Deferred Task 1 executed'));
    yield var_dump('Start Task 1');
    yield from VOsaka::sleep(1);
    yield var_dump('Task 1 completed after 1 seconds');
}

function task2(): Generator
{
    yield VOsaka::defer(fn() => send('Deferred Task 2 executed'));
    yield var_dump('Start Task 2');
    yield from VOsaka::sleep(1);
    yield var_dump('Task 2 completed after 1 seconds');
}

VOsaka::join(
    task1(),
    task2()
);
```
- Handle tasks with sleep, select
```php
use venndev\vosaka\VOsaka;

function workA(): Generator
{
    yield from VOsaka::sleep(1);
    yield var_dump('Work A completed after 1 second');
    return 'Work result';
}

function workB(): Generator
{
    yield from VOsaka::sleep(2);
    yield var_dump('Work B completed after 2 seconds');
    return 'Work result';
}

VOsaka::select(
    workA(),
    workB()
);
```
- Handle more advanced tasks.
```php
<?php

use venndev\vosaka\VOsaka;

// This is an example to apply to systems or software 
// that have a similar structure to an event scheduling 
//      repeater such as PocketMine-PMMP

function works(): void
{
    // This is a placeholder for the actual task you want to run.
    // For example, you might want to run a task every second.
    for ($i = 0; $i < 10000; $i++) {
        VOsaka::spawn(function (): Generator {
            yield from VOsaka::sleep(1); // Simulate a task that takes 1 second
            yield var_dump('Task executed at: ' . date('H:i:s'));
        });
    }
}

// Imagine this while(true) loop as a schedule repeater 
//      that handles tasks per second or a certain batch.
// Call this bad because if there are too many asynchronous 
//      tasks processing functions in the queue, it will cause 
//      you to have to wait for asynchronous tasks to finish 
//      processing before moving on to the next task.
function mainBad(): void
{
    // Imagine this while(true) loop as a schedule 
    //      repeater that handles tasks per second or a certain batch.
    while (true) {
        works();

        // Yield control back to the event loop
        VOsaka::run();

        var_dump('Always run last. After 1000 tasks completed, this task will be released.');
    }
}

function mainGood(): void
{
    // Set the maximum number of tasks to run per period
    VOsaka::setMaximumPeriod(10);

    // Enable the maximum period limit
    VOsaka::setEnableMaximumPeriod(true);

    while (true) {
        works();

        // Yield control back to the event loop
        VOsaka::run();

        var_dump('After 10 tasks completed, this task will be released.');
    }
}

mainGood();
```
- Handle tasks with Steam and Channel
```php
<?php
use venndev\vosaka\VChannel;
use venndev\vosaka\VOsaka;
use venndev\vosaka\VStream;

function main(): Generator
{
    $url = 'https://jsonplaceholder.typicode.com/albums/1';
    $data = new VChannel();
    yield VOsaka::defer(function (VChannel $data) {
        var_dump('Deferred: Data fetched from URL');
        foreach ($data->receive() as $chunk) {
            var_dump($chunk);
        }
        $data->close();
    }, $data);
    foreach (VStream::read($url) as $chunk) {
        yield $data->send($chunk);
    }
}

VOsaka::spawn(main());
VOsaka::run();
```
