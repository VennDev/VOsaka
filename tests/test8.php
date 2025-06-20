<?php

require '../vendor/autoload.php';

use venndev\vosaka\VOsaka;

function task1(): Generator
{
    yield VOsaka::defer(fn() => var_dump('Deferred Task 1 executed'));
    yield var_dump('Start Task 1');
    yield from VOsaka::sleep(1);
    yield var_dump('Task 1 completed after 1 seconds');
}

VOsaka::repeat(fn() => task1());
VOsaka::run();