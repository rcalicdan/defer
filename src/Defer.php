<?php

namespace Library\Defer;

use Library\Defer\Handlers\ProcessDeferHandler;
use Library\Defer\Utilities\DeferInstance;

/**
 * Static defer utility focused on task scheduling
 */
class Defer
{
    private static ?ProcessDeferHandler $globalHandler = null;

    /**
     * Create a new function-scoped defer instance
     */
    public static function scope(): DeferInstance
    {
        return new DeferInstance;
    }

    /**
     * Global-scoped defer - executes at script shutdown
     *
     * @param callable $callback The callback to defer
     */
    public static function global(callable $callback): void
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        self::$globalHandler->defer($callback);
    }

    /**
     * Terminate-scoped defer - executes after response is sent
     *
     * @param callable $callback The callback to execute
     * @param bool $always Whether to execute even on 4xx/5xx status codes
     */
    public static function terminate(callable $callback, bool $always = false): void
    {
        self::getHandler()->terminate($callback, $always);
    }

    /**
     * Reset state (useful for testing)
     */
    public static function reset(): void
    {
        self::$globalHandler = null;
    }

    public static function getHandler(): ProcessDeferHandler
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler;
        }

        return self::$globalHandler;
    }
}