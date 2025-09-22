<?php

namespace Rcalicdan\Defer\Handlers;

class ProcessDeferHandler
{
    /**
     * @var array<callable> Global defers
     */
    private static array $globalStack = [];

    /**
     * @var bool Whether handlers are registered
     */
    private static bool $handlersRegistered = false;

    /**
     * @var SignalRegistryHandler|null Signal handler registry instance
     */
    private static ?SignalRegistryHandler $signalHandler = null;

    /**
     * @var TerminateHandler Terminate handler instance
     */
    private TerminateHandler $terminateHandler;

    public function __construct()
    {
        $this->registerShutdownHandlers();
        $this->terminateHandler = new TerminateHandler;
    }

    /**
     * Create a function-scoped defer handler
     */
    public static function createFunctionDefer(): FunctionScopeHandler
    {
        return new FunctionScopeHandler;
    }

    /**
     * Add a global defer
     */
    public function defer(callable $callback): void
    {
        $this->addToGlobalStack($callback);
    }

    /**
     * Add a terminate callback (executes after response is sent)
     *
     * @param  callable  $callback  The callback to execute
     * @param  bool  $always  Whether to execute even on 4xx/5xx status codes
     */
    public function terminate(callable $callback, bool $always = false): void
    {
        $this->terminateHandler->addCallback($callback, $always);
    }

    /**
     * Manual execution of terminate callbacks (for testing)
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
     * Execute stack in LIFO order
     */
    private function executeStack(array $stack): void
    {
        for ($i = count($stack) - 1; $i >= 0; $i--) {
            try {
                if (is_callable($stack[$i])) {
                    $stack[$i]();
                }
            } catch (\Throwable $e) {
                error_log('Defer error: '.$e->getMessage());
            } finally {
                unset($stack[$i]);
            }
        }
    }

    /**
     * Execute all pending global defers (shutdown handler)
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
     * Register shutdown handlers
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
                error_log('Defer shutdown error: '.$e->getMessage());
            }
        });

        if (PHP_SAPI === 'cli') {
            self::$signalHandler = new SignalRegistryHandler([$this, 'executeAll']);
            self::$signalHandler->register();
        }

        self::$handlersRegistered = true;
    }

    /**
     * Get signal handling capabilities info
     */
    public function getSignalHandlingInfo(): array
    {
        if (self::$signalHandler) {
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
     * Test signal handling (for debugging)
     */
    public function testSignalHandling(): void
    {
        echo "Testing defer signal handling capabilities...\n";

        $info = $this->getSignalHandlingInfo();

        echo "Platform: {$info['platform']} ({$info['sapi']})\n";
        echo "Available methods:\n";

        foreach ($info['methods'] as $method) {
            echo "  ✅ {$method}\n";
        }

        echo "\nCapabilities:\n";
        foreach ($info['capabilities'] as $capability => $available) {
            $status = $available ? '✅' : '❌';
            echo "  {$status} {$capability}\n";
        }

        $this->defer(function () {
            echo "\n🎯 Test defer executed successfully!\n";
        });

        echo "\nDefer test registered. Try Ctrl+C or let script finish normally.\n";
    }
}
