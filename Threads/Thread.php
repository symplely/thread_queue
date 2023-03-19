<?php

declare(strict_types=1);

namespace Async\Threads;

use Async\Threads\TWorker;

final class Thread
{
    /** @var array[string|int => int|\UVAsync] */
    protected $threads = [];

    /** @var array[string|int => mixed] */
    protected $result = [];

    /** @var array[string|int => Throwable] */
    protected $exception = [];

    /** @var callable[] */
    protected $successCallbacks = [];

    /** @var callable[] */
    protected $errorCallbacks = [];

    /**
     * State Detection.
     *
     * @var array[string|int => mixed]
     */
    protected $status = [];

    /** @var callable */
    protected $success;

    /** @var callable */
    protected $failed;

    /** @var ?object */
    protected $loop;
    protected $hasLoop = false;

    /** @var boolean for **Coroutine** `yield` usage */
    protected $isYield = false;
    protected $isClosed = false;
    protected $releaseQueue = false;
    protected $tid = 0;

    protected static $uv = null;

    public static function get_uv(): ?\UVLoop
    {
        return self::$uv;
    }

    public function __destruct()
    {
        if (!$this->isClosed)
            $this->close();

        if (!$this->hasLoop && self::$uv instanceof \UVLoop) {
            @\uv_stop(self::$uv);
            @\uv_run(self::$uv);
            self::$uv = null;
        }

        $this->successCallbacks = [];
        $this->errorCallbacks = [];
        $this->threads = null;
    }

    public function close()
    {
        if (!$this->isClosed) {
            $this->success = null;
            $this->failed = null;
            $this->loop = null;
            $this->isYield = false;
            $this->isClosed = true;
            $this->releaseQueue = true;
        }
    }

    protected static function isCoroutine($object): bool
    {
        return (\class_exists('Coroutine', false)
            && \is_object($object)
            && \method_exists($object, 'addFuture')
            && \method_exists($object, 'execute')
            && \method_exists($object, 'executeTask')
            && \method_exists($object, 'run')
            && \method_exists($object, 'isPcntl')
            && \method_exists($object, 'createTask')
            && \method_exists($object, 'getUV')
            && \method_exists($object, 'schedule')
            && \method_exists($object, 'scheduleFiber')
            && \method_exists($object, 'ioStop')
            && \method_exists($object, 'fiberOn')
            && \method_exists($object, 'fiberOff')
        );
    }

    /**
     * @param object $loop
     * @param \UVLoop|null $uv
     * @param boolean $yielding
     */
    public function __construct(object $loop = null, ?\UVLoop $uv = null, bool $yielding = false)
    {
        if (!\ZEND_THREAD_SAFE && !\function_exists('uv_loop_new'))
            throw new \InvalidArgumentException('This `Thread` class requires PHP `ZTS` and the libuv `ext-uv` extension!');

        $this->lock = \uv_mutex_init();
        \uv_mutex_lock($this->lock);
        $this->isYield = $yielding;
        $this->hasLoop = \is_object($loop) && \method_exists($loop, 'executeTask') && \method_exists($loop, 'run');
        if (self::isCoroutine($loop)) {
            $this->loop = $loop;
            $this->isYield = true;
            $uv = $loop->getUV();
        } elseif ($this->hasLoop) {
            $this->loop = $loop;
        }

        $uvLoop = $uv instanceof \UVLoop ? $uv : self::$uv;
        self::$uv = $uvLoop instanceof \UVLoop ? $uvLoop : \uv_loop_new();
        $this->success = $this->isYield ? [$this, 'yieldAsFinished'] : [$this, 'triggerSuccess'];
        $this->failed = $this->isYield ? [$this, 'yieldAsFailed'] : [$this, 'triggerError'];
        \uv_mutex_unlock($this->lock);
    }

    /**
     * @codeCoverageIgnore
     */
    public function create_ex(callable $task, ...$args): ?TWorker
    {
        return $this->create(\bin2hex(\random_bytes(6)), $task, $args);
    }

    /**
     * This will cause a _new thread_ to be **created** and **executed** for the associated `Thread` object,
     * where its _internal_ task `queue` will begin to be processed.
     *
     * @param string|int $tid Thread ID
     * @param callable $task
     * @param mixed ...$args
     * @return TWorker|null
     */
    public function create($tid, callable $task, ...$args): ?TWorker
    {
        $tid = \is_scalar($tid) ? $tid : (int) $tid;
        if (!isset($this->threads[$tid])) {
            \uv_mutex_lock($this->lock);
            $this->tid = $tid;
            $this->status[$tid] = 'queued';
            $thread = $this;
            $this->threads[$tid] = \uv_async_init(self::$uv, static function ($async) use (&$thread, &$tid) {
                $thread->handlers($tid);
                $thread->remove($tid);
                \uv_close($async);
            });

            \uv_queue_work(self::$uv, static function () use (&$thread, &$task, &$tid, &$args) {
                include 'vendor/autoload.php';
                $lock = \uv_mutex_init();
                $isCancelled = false;
                try {
                    $arguments = \reset($args);
                    if (!\is_array($arguments))
                        $result = $task($arguments);
                    else
                        $result = $task(...$arguments);

                    if ($thread->isCancelled($tid)) {
                        $isCancelled = true;
                        \uv_mutex_lock($lock);
                        $thread->releaseQueue = true;
                        \uv_mutex_unlock($lock);
                    } else {
                        $thread->setResult($tid, $result, $lock);
                    }
                } catch (\Throwable $exception) {
                    $thread->setException($tid, $exception, $lock);
                }

                if (isset($thread->threads[$tid]) && $thread->threads[$tid] instanceof \UVAsync && \uv_is_active($thread->threads[$tid]) && !$isCancelled) {
                    \uv_async_send($thread->threads[$tid]);
                    do {
                        \usleep($thread->count() * 25000);
                    } while (!$thread->releaseQueue);
                }

                unset($lock);
            }, function () {
            });
            \uv_mutex_unlock($this->lock);

            return new TWorker($this, $tid);
        }

        return null;
    }

    /**
     * This method will sends a cancellation request to the thread.
     * - WILL SKIP `then` callback handlers, _immediately_ execute `catch` handlers.
     * - WILL NOT stop a thread execution, `uv_cancel` not implemented.
     *
     * @param string|int $tid Thread ID
     * @return void
     */
    public function cancel($tid = null): void
    {
        if (isset($this->status[$tid])) {
            \uv_mutex_lock($this->lock);
            $this->status[$tid] = ['cancelled'];
            $this->exception[$tid] = new \RuntimeException(\sprintf('Thread %s cancelled!', (string)$tid));
            $this->releaseQueue = true;
            \uv_mutex_unlock($this->lock);
            if (isset($this->threads[$tid]) && $this->threads[$tid] instanceof \UVAsync && \uv_is_active($this->threads[$tid]) && !\uv_is_closing($this->threads[$tid])) {
                \uv_async_send($this->threads[$tid]);
                if (!self::isCoroutine($this->loop))
                    $this->join($tid);
            }
        }
    }

    /**
     * This method will join a single thread by `tid` or `all` threads.
     * - It will first wait for that thread's internal task queue to finish.
     *
     * @param string|int $tid Thread ID
     * @return void
     */
    public function join($tid = null): void
    {
        $isCoroutine = self::isCoroutine($this->loop);
        $isCancelling = !empty($tid) && $this->isCancelled($tid);
        while (!empty($tid) ? ($this->isRunning($tid) || $this->isCancelled($tid)) : !$this->isEmpty()) {
            if ($isCoroutine) { // @codeCoverageIgnoreStart
                $this->loop->futureOn();
                $this->loop->run();
                $this->loop->futureOff();
            } elseif ($this->hasLoop) {
                $this->loop->run(); // @codeCoverageIgnoreEnd
            } else {
                \uv_run(self::$uv, !empty($tid) ? \UV::RUN_ONCE : \UV::RUN_NOWAIT);
            }

            if ($isCancelling)
                break;
        }

        if (!empty($tid))
            $this->releaseQueue = true;
    }

    /**
     * @internal
     *
     * @param string|int $tid Thread ID
     * @return void
     */
    protected function handlers($tid): void
    {
        if ($this->isSuccessful($tid)) {
            if ($this->hasLoop) // @codeCoverageIgnoreStart
                $this->loop->executeTask($this->success, $tid);
            elseif ($this->isYield)
                $this->yieldAsFinished($tid);  // @codeCoverageIgnoreEnd
            else
                $this->triggerSuccess($tid);
        } else {
            if ($this->hasLoop)  // @codeCoverageIgnoreStart
                $this->loop->executeTask($this->failed, $tid);
            elseif ($this->isYield)
                $this->yieldAsFailed($tid);  // @codeCoverageIgnoreEnd
            else
                $this->triggerError($tid);
        }
    }

    /**
     * Get `Thread`'s task count.
     *
     * @return integer
     */
    public function count(): int
    {
        return \is_array($this->threads) ? \count($this->threads) : 0;
    }

    public function isEmpty(): bool
    {
        return empty($this->threads);
    }

    /**
     * Tell if the referenced `tid` is cancelled.
     *
     * @param string|int $tid Thread ID
     * @return bool
     */
    public function isCancelled($tid): bool
    {
        return isset($this->status[$tid]) && \is_array($this->status[$tid]);
    }

    /**
     * Tell if the referenced `tid` is executing.
     *
     * @param string|int $tid Thread ID
     * @return bool
     */
    public function isRunning($tid): bool
    {
        return isset($this->status[$tid]) && \is_string($this->status[$tid]);
    }

    /**
     * Tell if the referenced `tid` has completed execution successfully.
     *
     * @param string|int $tid Thread ID
     * @return bool
     */
    public function isSuccessful($tid): bool
    {
        return isset($this->status[$tid]) && $this->status[$tid] === true;
    }

    /**
     * Tell if the referenced `tid` was terminated during execution; suffered fatal errors, or threw uncaught exceptions.
     *
     * @param string|int $tid Thread ID
     * @return bool
     */
    public function isTerminated($tid): bool
    {
        return isset($this->status[$tid]) && $this->status[$tid] === false;
    }

    /**
     * @param string|int $tid Thread ID
     * @param \Throwable|null $exception
     * @return void
     */
    protected function setException($tid, \Throwable $exception, \UVLock $lock): void
    {
        if (isset($this->status[$tid])) {
            \uv_mutex_lock($lock);
            $this->status[$tid] = false;
            $this->exception[$tid] = $exception;
            \uv_mutex_unlock($lock);
        }
    }

    /**
     * @param string|int $tid Thread ID
     * @param mixed $result
     * @return void
     */
    protected function setResult($tid, $result, \UVLock $lock): void
    {
        if (isset($this->status[$tid])) {
            \uv_mutex_lock($lock);
            $this->status[$tid] = true;
            $this->result[$tid] = $result;
            \uv_mutex_unlock($lock);
        }
    }

    public function getResult($tid)
    {
        if (isset($this->result[$tid]))
            return $this->result[$tid];
    }

    public function getException($tid): \Throwable
    {
        if (isset($this->exception[$tid]))
            return $this->exception[$tid];
    }

    public function getSuccess(): array
    {
        return $this->result;
    }

    public function getFailed(): array
    {
        return $this->exception;
    }

    /**
     * Add handlers to be called when the `Thread` execution is _successful_, or _erred_.
     *
     * @param callable $thenCallback
     * @param callable|null $failCallback
     * @return self
     */
    public function then(callable $thenCallback, callable $failCallback = null, $tid = null): self
    {
        \uv_mutex_lock($this->lock);
        $this->successCallbacks[(\is_null($tid) ? $this->tid : $tid)][] = $thenCallback;
        \uv_mutex_unlock($this->lock);
        if ($failCallback !== null) {
            $this->catch($failCallback, $tid);
        }

        return $this;
    }

    /**
     * Add handlers to be called when the `Thread` execution has _errors_.
     *
     * @param callable $callback
     * @return self
     */
    public function catch(callable $callback, $tid = null): self
    {
        \uv_mutex_lock($this->lock);
        $this->errorCallbacks[(\is_null($tid) ? $this->tid : $tid)][] = $callback;
        \uv_mutex_unlock($this->lock);

        return $this;
    }

    /**
     * Call the success callbacks.
     *
     * @param string|int $tid Thread ID
     * @return mixed
     */
    public function triggerSuccess($tid)
    {
        if (isset($this->result[$tid])) {
            $result = $this->result[$tid];
            if ($this->isYield)
                return $this->yieldSuccess($result, $tid);

            foreach ($this->successCallbacks[$tid] as $callback)
                $callback($result);

            return $result;
        }
    }

    /**
     * Call the error callbacks.
     *
     * @param string|int $tid Thread ID
     * @return mixed
     */
    public function triggerError($tid)
    {
        if (isset($this->exception[$tid])) {
            $exception = $this->exception[$tid];
            if ($this->isYield)
                return $this->yieldError($exception, $tid);

            foreach ($this->errorCallbacks[$tid] as $callback)
                $callback($exception);

            if (!$this->errorCallbacks)
                throw $exception;
        }
    }

    protected function yieldSuccess($output, $tid)
    {
        foreach ($this->successCallbacks[$tid] as $callback)
            yield $callback($output);

        return $output;
    }

    protected function yieldError($exception, $tid)
    {
        foreach ($this->errorCallbacks[$tid] as $callback)
            yield $callback($exception);

        if (!$this->errorCallbacks) {
            throw $exception;
        }
    }

    /**
     * @param string|int $tid Thread ID
     * @return void
     */
    protected function remove($tid): void
    {
        if (isset($this->threads[$tid]) && $this->threads[$tid] instanceof \UVAsync) {
            \uv_mutex_lock($this->lock);
            unset($this->threads[$tid]);
            \uv_mutex_unlock($this->lock);
        }
    }

    public function yieldAsFinished($tid)
    {
        return yield from $this->triggerSuccess($tid);
    }

    public function yieldAsFailed($tid)
    {
        return yield $this->triggerError($tid);
    }
}
