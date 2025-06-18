<?php

require '../vendor/autoload.php';

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