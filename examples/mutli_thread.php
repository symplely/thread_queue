<?php

include 'vendor/autoload.php';

use Async\Threads\Thread;

$thread = new Thread();
$counter = 0;
$t5 = $thread->create(5, function () {
  usleep(50000);
  print "Running Thread: 5\n";
  return 2;
})->then(function (int $output) use (&$counter) {
  $counter += $output;
})->catch(function (\Throwable $e) {
  print $e->getMessage() . PHP_EOL;
});

$t6 = $thread->create_ex(function () {
  print "Running Thread: 6\n";
  usleep(500);
  return 4;
})->then(function (int $output) use (&$counter) {
  $counter += $output;
})->catch(function (\Throwable $e) {
  print $e->getMessage() . PHP_EOL;
});

$t7 = $thread->create_ex(function () {
  usleep(5000000);
  print "Running Thread: 7\n";
})->then(function (int $output) use (&$counter) {
  $counter += $output;
})->catch(function (\Throwable $exception) {
  print $exception->getMessage() . PHP_EOL;
});

$t8 = $thread->create(8, function () {
  usleep(50000);
  print "Running Thread: 8\n";
})->then(function (int $output) use (&$counter) {
  $counter += $output;
})->catch(function (\Throwable $exception) {
  print $exception->getMessage() . PHP_EOL;
});

$t6->join();
print "Thread 6 returned: " . $t6->result() . PHP_EOL;
$t7->cancel();
$t5->join();

print "Thread 5 returned: " . $t5->result() . PHP_EOL;
$t8->join();
