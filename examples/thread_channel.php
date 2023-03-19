<?php

include 'vendor/autoload.php';

use Async\Threads\Thread;
use Async\Threads\TChannel;

$thread = new Thread();
$channel = new TChannel();

$t2 = $thread->create_ex(function ($read) {
    return $read->recv();
}, $channel)->then(function (string $result) {
    print $result . PHP_EOL;;
})->catch(function (\Throwable $exception) {
    print $exception->getMessage() . PHP_EOL;
});

$thread->create_ex(function ($write) {
    return $write->send('Thread 1');
}, $channel)->then(function (int $result) {
    print $result . PHP_EOL;
})->catch(function (\Throwable $e) {
    print $e->getMessage() . PHP_EOL;
});

$thread->join();
