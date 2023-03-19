<?php

declare(strict_types=1);

namespace Async\Threads;

/**
 * @codeCoverageIgnore
 */
final class TChannel
{
    /** @var array<resource,resource> */
    protected $resource = [];

    public function __destruct()
    {
        \fclose($this->resource[1]);
        \fclose($this->resource[0]);
        $this->resource = null;
    }

    public function __construct()
    {
        $this->resource = \stream_socket_pair((\stripos(\PHP_OS, "win") === 0 ? \STREAM_PF_INET : \STREAM_PF_UNIX),
            \STREAM_SOCK_STREAM,
            \STREAM_IPPROTO_IP
        );
    }

    public function send(string $message)
    {
        $result = \fwrite($this->resource[1], $message . "\n", \strlen($message . "\n"));
        \usleep(1);
        return $result;
    }

    public function recv()
    {
        return \trim(\fgets($this->resource[0]));
    }
}
