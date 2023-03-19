<?php

include 'vendor/autoload.php';

use Async\Threads\Thread;

$thread = new Thread();
[$_read, $_write] = \stream_socket_pair((\stripos(\PHP_OS, "win") === 0 ? \STREAM_PF_INET : \STREAM_PF_UNIX),
  \STREAM_SOCK_STREAM,
  \STREAM_IPPROTO_IP
);

$thread->create_ex(function ($write) {
  echo "[queue1] ";
  $result = \fwrite($write, "Thread 1\n");
  \usleep(1);
  return $result;
}, $_write)->then(function (int $output) {
  print "Thread 1 returned: " . $output . PHP_EOL;
})->catch(function (\Throwable $e) {
  print $e->getMessage() . PHP_EOL;
});

$t2 = $thread->create_ex(function ($read) {
  echo "[queue2] ";
  echo "Thread 2 Got " . \fgets($read);
  return 'finish';
}, $_read)->then(function (string $output) {
  print "Thread 2 returned: " . $output . PHP_EOL;
})->catch(function (\Throwable $exception) {
  print $exception->getMessage() . PHP_EOL;
});

$thread->join();
