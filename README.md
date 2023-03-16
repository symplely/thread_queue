# thread_queue

[![Linux](https://github.com/symplely/thread_queue/workflows/Linux/badge.svg)](https://github.com/symplely/thread_queue/actions?query=workflow%3ALinux)[![Windows](https://github.com/symplely/thread_queue/workflows/Windows/badge.svg)](https://github.com/symplely/thread_queue/actions?query=workflow%3AWindows)[![macOS](https://github.com/symplely/thread_queue/workflows/macOS/badge.svg)](https://github.com/symplely/thread_queue/actions?query=workflow%3AmacOS)[![codecov](https://codecov.io/gh/symplely/thread_queue/branch/master/graph/badge.svg)](https://codecov.io/gh/symplely/thread_queue)[![Codacy Badge](https://api.codacy.com/project/badge/Grade/56a6036fa1c849c88b6e52827cad32a8)](https://www.codacy.com/gh/symplely/thread_queue?utm_source=github.com&amp;utm_medium=referral&amp;utm_content=symplely/thread_queue&amp;utm_campaign=Badge_Grade)[![Maintainability](https://api.codeclimate.com/v1/badges/7604b17b9ebf310ec94b/maintainability)](https://codeclimate.com/github/symplely/thread_queue/maintainability)

An simply __`uv_queue_work`__ wrapper API to _manage_ a **pool** of **threads**, for parallel PHP _execution_.

## Table of Contents

* [Installation](#installation)
* [Usage](#usage)
* [Event hooks](#event-hooks)
* [Contributing](#contributing)
* [License](#license)

This package uses features of [`libuv`](https://github.com/libuv/libuv), the PHP extension [ext-uv](https://github.com/amphp/ext-uv) of the  **Node.js**  library. It's `uv_queue_work` function is used to create Threads.

## Installation

```cmd
composer require symplely/thread_queue
```

This package will require **libuv** features, do one of the following to install.

For **Debian** like distributions, Ubuntu...

```bash
apt-get install libuv1-dev php-pear -y
```

For **RedHat** like distributions, CentOS...

```bash
yum install libuv-devel php-pear -y
```

Now have **Pecl** auto compile, install, and setup.

```bash
pecl channel-update pecl.php.net
pecl install uv-beta
```

For **Windows**, stable PHP versions are available [from PECL](https://pecl.php.net/package/uv).

Directly download latest from <https://windows.php.net/downloads/pecl/releases/uv/>

Extract `libuv.dll` to same directory as `PHP` binary executable, and extract `php_uv.dll` to `ext\` directory.

Enable extension `php_uv.dll` in php.ini

```powershell
cd C:\Php
Invoke-WebRequest "https://windows.php.net/downloads/pecl/releases/uv/0.2.4/php_uv-0.2.4-7.2-ts-vc15-x64.zip" -OutFile "php_uv-0.2.4.zip"
#Invoke-WebRequest "https://windows.php.net/downloads/pecl/releases/uv/0.2.4/php_uv-0.2.4-7.4-ts-vc15-x64.zip" -OutFile "php_uv-0.2.4.zip"
7z x -y php_uv-0.2.4.zip libuv.dll php_uv.dll
copy php_uv.dll ext\php_uv.dll
del php_uv.dll
del php_uv-0.2.4.zip
echo extension=uv >> php.ini
```

## Usage

```php
include 'vendor/autoload.php';

use Async\Threads\Thread;

$thread = new Thread();
$counter = 0;
$t1 = $thread->create_ex(function () {
  print "Running Thread: 1\n";
  return 2;
})->then(function (int $output) use (&$counter) {
  $counter += $output;
})->catch(function (\Throwable $e) {
  print $e->getMessage() . PHP_EOL;
});

$t1->join();
// Or
$t1->cancel();

print_r($t1->result());
// Or
print_r($t1->exception());
```

## Event hooks

When creating Threads processes, you'll get an instance of `TWorker` returned.
You can add the following event **callback** hooks on a `Thread` instance.

```php
$thread = new Thread($function, ...$args)

$worker = $thread->create($thread_id /* string or int */, function () {
    }, ...$arguments)
    ->then(function ($result) {
        // On success, `$result` is returned by the thread.
    })
    ->catch(function ($exception) {
        // When an exception is thrown from within a thread, it's caught and passed here.
    });

$worker = $thread->create_ex(function () {
    }, ...$arguments)
    ->then(function ($result) {
        // On success, `$result` is returned by the thread.
    })
    ->catch(function ($exception) {
        // When an exception is thrown from within a thread, it's caught and passed here.
    });
```

## Contributing

Contributions are encouraged and welcome; I am always happy to get feedback or pull requests on Github :) Create [Github Issues](https://github.com/symplely/thread_queue/issues) for bugs and new features and comment on the ones you are interested in.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
