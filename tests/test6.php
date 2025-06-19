<?php

require '../vendor/autoload.php';

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