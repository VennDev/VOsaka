<?php

require '../vendor/autoload.php';

use venndev\vosaka\VOsaka;

// This is an example to apply to systems or software 
// that have a similar structure to an event scheduling 
//      repeater such as PocketMine-PMMP

function works(): void
{
    // This is a placeholder for the actual task you want to run.
    // For example, you might want to run a task every second.
    for ($i = 0; $i < 100; $i++) {
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
