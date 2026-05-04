<?php

declare(strict_types=1);

namespace Rcalicdan\Defer\Handlers;

class ProcessDeferHandler
{
    /**
     * @var array<callable> Global defers
     */
    private static array $globalStack = [];

    /**
     * @var bool Whether shutdown handler is registered
     */
    private static bool $handlersRegistered = false;

    /**
     * @var bool Whether signal handling has been enabled
     */
    private static bool $signalsEnabled = false;

    /**
     * @var bool Whether signal handlers have been registered
     */
    private static bool $signalsRegistered = false;

    /**
     * @var SignalRegistryHandler|null Signal handler registry instance
     */
    private static ?SignalRegistryHandler $signalHandler = null;

    /**
     * Held so enableSignals() can register against a real instance
     * even when called after construction.
     */
    private static ?self $instance = null;

    /**
     * @var TerminateHandler Terminate handler instance
     */
    private TerminateHandler $terminateHandler;

    public function __construct()
    {
        self::$instance = $this;
        $this->registerShutdownHandlers();
        $this->terminateHandler = new TerminateHandler();
    }

    /**
     * Opt-in: enable signal handling (SIGTERM, SIGINT, SIGHUP, Ctrl-C, etc.).
     * Safe to call multiple times — signals are only registered once.
     * Must be called before the first defer is added for CLI scripts that
     * need graceful shutdown on interruption.
     */
    public static function enableSignals(): void
    {
        self::$signalsEnabled = true;

        if (self::$signalsRegistered || PHP_SAPI !== 'cli') {
            return;
        }

        if (self::$instance === null) {
            new self();
        }

        $instance = self::$instance;
        assert($instance instanceof self);

        self::$signalHandler = new SignalRegistryHandler([$instance, 'executeAll']);
        self::$signalHandler->register();
        self::$signalsRegistered = true;
    }

    /**
     * Create a function-scoped defer handler.
     */
    public static function createFunctionDefer(): FunctionScopeHandler
    {
        return new FunctionScopeHandler();
    }

    /**
     * Add a global defer.
     */
    public function defer(callable $callback): void
    {
        $this->addToGlobalStack($callback);
    }

    /**
     * Add a terminate callback (executes after response is sent).
     *
     * @param callable $callback The callback to execute
     * @param bool $always Whether to execute even on 4xx/5xx status codes
     */
    public function terminate(callable $callback, bool $always = false): void
    {
        $this->terminateHandler->addCallback($callback, $always);
    }

    /**
     * Manual execution of terminate callbacks (for testing).
     */
    public function executeTerminate(): void
    {
        $this->terminateHandler->executeCallbacks();
    }

    /**
     * Add callback to global stack
     */
    private function addToGlobalStack(callable $callback): void
    {
        if (count(self::$globalStack) >= 100) {
            array_shift(self::$globalStack);
        }

        self::$globalStack[] = $callback;
    }

    /**
     * @param array<callable> $stack
     */
    private function executeStack(array $stack): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            try {
                if (is_callable($stack[$i])) {
                    $stack[$i]();
                }
            } catch (\Throwable $e) {
                error_log('Defer error: ' . $e->getMessage());
            } finally {
                unset($stack[$i]);
            }
        }
    }

    /**
     * Execute all pending global defers (called by shutdown/signal handlers).
     */
    public function executeAll(): void
    {
        try {
            $this->executeStack(self::$globalStack);
        } finally {
            self::$globalStack = [];
        }
    }

    /**
     * Only registers the shutdown function. Signal handling is opt-in via enableSignals().
     */
    private function registerShutdownHandlers(): void
    {
        if (self::$handlersRegistered) {
            return;
        }

        register_shutdown_function(function () {
            try {
                $this->executeAll();
            } catch (\Throwable $e) {
                error_log('Defer shutdown error: ' . $e->getMessage());
            }
        });

        self::$handlersRegistered = true;
    }

    /**
     * Reset all static state (useful for testing).
     */
    public static function reset(): void
    {
        self::$globalStack = [];
        self::$handlersRegistered = false;
        self::$signalsEnabled = false;
        self::$signalsRegistered = false;
        self::$signalHandler = null;
        self::$instance = null;
    }

    /**
     * Whether signal handling is currently active.
     */
    public static function signalsEnabled(): bool
    {
        return self::$signalsEnabled;
    }

    /**
     * @return array{platform: string, sapi: string, methods: array<string>, capabilities: array<string, mixed>}
     */
    public function getSignalHandlingInfo(): array
    {
        if (self::$signalHandler !== null) {
            return self::$signalHandler->getCapabilities();
        }

        return [
            'platform' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'methods' => ['Generic fallback (shutdown function)'],
            'capabilities' => ['shutdown_function' => true],
        ];
    }

    /**
     * @return array{sapi: string, fastcgi: bool, fastcgi_finish_request: bool, output_buffering: bool, current_response_code: int}
     */
    public function getEnvironmentInfo(): array
    {
        return $this->terminateHandler->getEnvironmentInfo();
    }

    public function testSignalHandling(): void
    {
        echo "Testing defer signal handling capabilities...\n";

        $info = $this->getSignalHandlingInfo();

        echo "Platform: {$info['platform']} ({$info['sapi']})\n";
        echo 'Signal handling: ' . (self::$signalsEnabled ? 'enabled' : 'disabled (call Defer::enableSignals() to opt in)') . "\n";
        echo "Available methods:\n";

        foreach ($info['methods'] as $method) {
            echo "  ✅ {$method}\n";
        }

        echo "\nCapabilities:\n";
        foreach ($info['capabilities'] as $capability => $available) {
            $isAvailable = is_bool($available) ? $available : (bool) $available;
            echo '  ' . ($isAvailable ? '✅' : '❌') . " {$capability}\n";
        }

        $this->defer(function () {
            echo "\n🎯 Test defer executed successfully!\n";
        });

        echo "\nDefer test registered. Try Ctrl+C or let script finish normally.\n";
    }
}
