<?php

require '../vendor/autoload.php';

use venndev\vosaka\VOsaka;

function work(): Generator
{
    yield from VOsaka::sleep(1);
    yield var_dump('Work completed after 1 seconds');
    return 'Work result';
}

function workError(): Generator
{
    yield from VOsaka::sleep(1);
    yield var_dump('Work Error completed after 1 seconds');
    throw new Exception('Work Error occurred');
}

function main(): Generator
{
    $result = yield from VOsaka::await(work())();
    var_dump('Work result: ' . $result);

    // Await with error handling
    $resultError = yield from VOsaka::await(workError())();
    var_dump('Work Error result: ' . $resultError);

    // Await with default value
    $resultOrDefault = yield from VOsaka::await(workError())->unwrapOr('Default Value');
    var_dump('Work Error result with default: ' . $resultOrDefault);

    // Await with panic handling
    try {
        $resultPanic = yield from VOsaka::await(workError())->unwrap();
        var_dump($resultPanic);
    } catch (Throwable $e) {
        var_dump('Caught exception: ' . $e->getMessage());
    }

    // Await with expect handling
    try {
        $resultExpect = yield from VOsaka::await(workError())->expect('An error occurred during work');
        var_dump($resultExpect);
    } catch (RuntimeException $e) {
        var_dump('Caught RuntimeException: ' . $e->getMessage());
    }
}

VOsaka::spawn(main());
VOsaka::run();