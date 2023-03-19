<?php

namespace Async\Tests;

use Async\Threads\Thread;
use Async\Threads\TChannel;
use PHPUnit\Framework\TestCase;

class ThreadTestMultiChannel extends TestCase
{
    protected function setUp(): void
    {
        if (!\ZEND_THREAD_SAFE && !\function_exists('uv_loop_new'))
            $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    }

    /**
     * @runInSeparateProcess
     */
    public function testIt_can_handle_multi_channel()
    {
        $this->markTestSkipped('buggy, long stall before segmentation fault');

        $thread = new Thread();
        $channel = new TChannel();

        $thread->create_ex(function ($write) {
            return $write->send('Thread 1');
        }, $channel)->then(function (int $result) {
            $this->assertEquals(9, $result);
        })->catch(function (\Throwable $e) {
            print $e->getMessage() . PHP_EOL;
        });

        $t2 = $thread->create_ex(function ($read) {
            return $read->recv();
        }, $channel)->then(function (string $result) {
            $this->assertEquals('Thread 1', $result);
        })->catch(function (\Throwable $exception) {
            print $exception->getMessage() . PHP_EOL;
        });

        $thread->join();
    }
}
