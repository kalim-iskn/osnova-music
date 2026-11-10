<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Keeps one shutdown callback per long-lived queue worker process.
 * Registering a callback from every job retains every old callback until the
 * worker exits, so the active handler is replaced and cleared per job.
 */
final class QueueWorkerFatalMonitor
{
    private static bool $registered = false;

    private static ?Closure $activeHandler = null;

    public static function watch(callable $handler): void
    {
        if (! self::$registered) {
            register_shutdown_function([self::class, 'handleShutdown']);
            self::$registered = true;
        }

        self::$activeHandler = Closure::fromCallable($handler);
    }

    public static function clear(): void
    {
        self::$activeHandler = null;
    }

    public static function handleShutdown(?array $error = null): void
    {
        $handler = self::$activeHandler;
        self::$activeHandler = null;

        if (! $handler) {
            return;
        }

        $error ??= error_get_last();
        if (! is_array($error) || ! in_array($error['type'] ?? null, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        try {
            $handler($error);
        } catch (Throwable $exception) {
            error_log('Queue fatal monitor could not finish recovery: ' . $exception->getMessage());
        }
    }
}
