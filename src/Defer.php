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
        self::handler()->defer($callback);
    }

    /**
     * Terminate-scoped defer — executes after response is sent.
     *
     * @param bool $always Whether to execute even on 4xx/5xx status codes
     */
    public static function terminate(callable $callback, bool $always = false): void
    {
        self::handler()->terminate($callback, $always);
    }

    /**
     * Opt-in: register OS signal handlers so that global defers also run
     * on SIGTERM, SIGINT, SIGHUP, Ctrl-C, etc.
     *
     * Disabled by default. Call once, early in your CLI entry point.
     * No-op in non-CLI environments.
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
     * Return signal handling capabilities for the current platform.
     *
     * @return array{platform: string, sapi: string, methods: array<string>, capabilities: array<string, mixed>}
     */
    public static function signalInfo(): array
    {
        return self::handler()->getSignalHandlingInfo();
    }

    /**
     * Print a diagnostic summary of signal handling capabilities.
     */
    public static function testSignals(): void
    {
        self::handler()->testSignalHandling();
    }

    /**
     * Return environment info relevant to terminate defer behaviour
     * (SAPI, FastCGI availability, output buffering, current response code).
     *
     * @return array{sapi: string, fastcgi: bool, fastcgi_finish_request: bool, output_buffering: bool, current_response_code: int}
     */
    public static function environmentInfo(): array
    {
        return self::handler()->getEnvironmentInfo();
    }

    /**
     * Manually flush all pending global defers (useful in tests).
     */
    public static function executeAll(): void
    {
        self::handler()->executeAll();
    }

    /**
     * Manually flush all pending terminate callbacks (useful in tests).
     */
    public static function executeTerminate(): void
    {
        self::handler()->executeTerminate();
    }

    /**
     * Reset all static state (useful between tests).
     */
    public static function reset(): void
    {
        self::$globalHandler = null;
        ProcessDeferHandler::reset();
    }

    private static function handler(): ProcessDeferHandler
    {
        if (self::$globalHandler === null) {
            self::$globalHandler = new ProcessDeferHandler();
        }

        return self::$globalHandler;
    }
}
