<?php

namespace TQueue\Tests;

use Async\Threads\Thread;
use PHPUnit\Framework\TestCase;

class ThreadTestMulti extends TestCase
{
    protected function setUp(): void
    {
        if (!\IS_THREADED_UV)
            $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    }

    public function testIt_can_handle_multi()
    {
        $this->markTestSkipped('Test skipped "currently buggy - zend_mm_heap corrupted');
        $thread = new Thread();
        $counter = 0;
        $t5 = $thread->create_ex(function () {
            usleep(50000);
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        })->catch(function (\Throwable $e) {
            var_dump($e->getMessage());
        });

        $t6 = $thread->create_ex(function () {
            usleep(50);
            return 4;
        })->then(function (int $result) use (&$counter) {
            $counter += $result;
        })->catch(function (\Throwable $e) {
            var_dump($e->getMessage());
        });

        $t7 = $thread->create_ex(function () {
            usleep(50000000);
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        })->catch(function (\Throwable $exception) {
            $this->assertIsString($exception->getMessage());
        });

        $t6->join();
        $this->assertEquals(4, $t6->result());

        $t7->cancel();
        $this->expectExceptionObject($t7->exception());
        $thread->join();
    }
}
