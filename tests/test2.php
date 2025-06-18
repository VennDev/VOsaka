<?php

require '../vendor/autoload.php';

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