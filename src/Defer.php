<?php

declare(strict_types=1);

namespace Rcalicdan\Defer;

use Rcalicdan\Defer\Handlers\ProcessDeferHandler;
use Rcalicdan\Defer\Utilities\DeferInstance;

class Defer
{
    private static ?ProcessDeferHandler $globalHandler = null;

    /**
     * Create a new function-scoped defer instance.
     */
    public static function scope(): DeferInstance
    {
        return new DeferInstance();
    }

    /**
     * Global-scoped defer — executes at script shutdown.
     */
    public static function global(callable $callback): void
    {
        self::getHandler()->defer($callback);
    }

    /**
     * Terminate-scoped defer — executes after response is sent.
     *
     * @param bool $always Whether to execute even on 4xx/5xx status codes
     */
    public static function terminate(callable $callback, bool $always = false): void
    {
        self::getHandler()->terminate($callback, $always);
    }

    /**
     * Opt-in: register OS signal handlers (SIGTERM, SIGINT, SIGHUP, Ctrl-C, etc.)
     * so that deferred callbacks run even when the process is interrupted.
     *
     * Disabled by default to avoid unexpected behaviour. Call this once, early
     * in your script, when you need graceful shutdown on interruption.
     *
     * Only meaningful in CLI; silently no-ops in other SAPIs.
     *
     * Example:
     *   Defer::enableSignals();
     *   Defer::global(fn() => $db->close());
     */
    public static function enableSignals(): void
    {
        ProcessDeferHandler::enableSignals();
    }

    /**
     * Whether signal handling is currently active.
     */
    public static function signalsEnabled(): bool
    {
        return ProcessDeferHandler::signalsEnabled();
    }

    /**
     * Reset all state (useful for testing).
     */
    public static function reset(): void
    {
        self::$globalHandler = null;
        ProcessDeferHandler::reset();
    }

    public static function getHandler(): ProcessDeferHandler
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler();
        }

        return self::$globalHandler;
    }
}
