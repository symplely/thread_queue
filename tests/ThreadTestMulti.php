<?php

namespace Async\Tests;

use Async\Threads\Thread;
use PHPUnit\Framework\TestCase;

class ThreadTestMulti extends TestCase
{
    protected function setUp(): void
    {
        if (!\ZEND_THREAD_SAFE && !\function_exists('uv_loop_new'))
            $this->markTestSkipped('Test skipped "uv_loop_new" and "PHP ZTS" missing. currently buggy - zend_mm_heap corrupted');
    }

    /**
     * @runInSeparateProcess
     */
    public function testIt_can_handle_multi()
    {
        $thread = new Thread();
        $counter = 0;
        $t5 = $thread->create_ex(function () {
            usleep(100);
            return 2;
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        })->catch(function (\Throwable $e) {
            var_dump($e->getMessage());
        });

        $t6 = $thread->create_ex(function () {
            return 4;
        })->then(function (int $result) use (&$counter) {
            $counter += $result;
        })->catch(function (\Throwable $e) {
            var_dump($e->getMessage());
        });

        $t7 = $thread->create_ex(function () {
            sleep(1);
        })->then(function (int $output) use (&$counter) {
            $counter += $output;
        })->catch(function (\Throwable $exception) {
            $this->assertIsString($exception->getMessage());
        });

        $t6->join();
        $this->assertEquals(4, $t6->result());

        $t7->cancel();
        $this->assertInstanceOf('RuntimeException', $t7->exception());
        $this->assertCount(1, $thread->getFailed());
        $thread->join();
    }
}
