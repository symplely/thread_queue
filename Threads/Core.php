<?php

declare(strict_types=1);

if (!\defined('IS_UV'))
  \define('IS_UV', \function_exists('uv_loop_new'));

if (!\defined('IS_ZTS'))
  \define('IS_ZTS', \ZEND_THREAD_SAFE);

if (!\defined('IS_THREADED_UV'))
  \define('IS_THREADED_UV', \IS_ZTS && \IS_UV);

if (!\function_exists('mutex_lock')) {
  /**
   * Initialize mutex handle and lock mutex.
   *
   * @return \UVLock
   */
  function mutex_lock(): \UVLock
  {
    $lock = \uv_mutex_init();
    \uv_mutex_lock($lock);

    return $lock;
  }

  /**
   * Unlock mutex and destroy.
   *
   * @param \UVLock $lock
   * @return void
   */
  function mutex_unlock(\UVLock &$lock)
  {
    \uv_mutex_unlock($lock);
    unset($lock);
  }
}
