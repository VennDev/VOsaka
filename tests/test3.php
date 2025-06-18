<?php

require '../vendor/autoload.php';

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