<?php

namespace Library\Defer\Handlers;

class SignalRegistryHandler
{
    /**
     * @var callable Callback to execute on signal
     */
    private $callback;

    /**
     * @var bool Whether tick functions are registered
     */
    private static bool $tickRegistered = false;

    /**
     * @var array Signal handling capabilities
     */
    private array $capabilities = [];

    /**
     * @var array Available methods
     */
    private array $methods = [];

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
        $this->detectCapabilities();
    }

    /**
     * Register all available signal handlers
     */
    public function register(): void
    {
        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            $this->registerWindowsHandler();
            return;
        }

        if (function_exists('pcntl_signal')) {
            $this->registerPcntlHandler();
            return;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            $this->registerUnixFallbackHandler();
            return;
        }

        $this->registerGenericFallbackHandler();
    }

    /**
     * Windows signal handler using sapi_windows_set_ctrl_handler
     */
    private function registerWindowsHandler(): void
    {
        sapi_windows_set_ctrl_handler(function (int $event) {
            // CTRL_C_EVENT = 0, CTRL_BREAK_EVENT = 1, CTRL_CLOSE_EVENT = 2
            if (in_array($event, [0, 1, 2], true)) {
                call_user_func($this->callback);
                exit(0);
            }

            return true;
        });
    }

    /**
     * Unix signal handler using pcntl
     */
    private function registerPcntlHandler(): void
    {
        $handler = function (int $signal) {
            call_user_func($this->callback);
            exit(0);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);

        // Enable signal handling
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }
    }

    /**
     * Unix fallback handler without pcntl
     */
    private function registerUnixFallbackHandler(): void
    {
        if ($this->canMonitorProcess()) {
            $this->registerProcessMonitoring();
        }

        $this->registerErrorHandlerSignalCatch();

        if ($this->canMonitorStdin()) {
            $this->registerStdinMonitoring();
        }
    }

    /**
     * Check if we can monitor process state
     *
     * @return bool True if process monitoring is available
     */
    private function canMonitorProcess(): bool
    {
        return function_exists('posix_getppid') && function_exists('getmypid');
    }

    /**
     * Monitor for process state changes
     */
    private function registerProcessMonitoring(): void
    {
        if (self::$tickRegistered) {
            return;
        }

        $initialParentPid = function_exists('posix_getppid') ? posix_getppid() : null;

        register_tick_function(function () use ($initialParentPid) {
            static $checkCount = 0;
            $checkCount++;

            if ($checkCount % 100 !== 0) {
                return;
            }

            if ($initialParentPid && function_exists('posix_getppid')) {
                $currentParent = posix_getppid();

                if ($currentParent === 1 && $initialParentPid !== 1) {
                    call_user_func($this->callback);
                    exit(0);
                }
            }
        });

        self::$tickRegistered = true;

        declare(ticks=1000);
    }

    /**
     * Check if we can monitor STDIN
     *
     * @return bool True if STDIN monitoring is available
     */
    private function canMonitorStdin(): bool
    {
        return defined('STDIN') &&
            is_resource(STDIN) &&
            function_exists('stream_get_meta_data') &&
            function_exists('stream_set_blocking');
    }

    /**
     * Monitor STDIN for closure/interruption signals
     */
    private function registerStdinMonitoring(): void
    {
        stream_set_blocking(STDIN, false);

        register_tick_function(function () {
            static $lastCheck = 0;
            static $checkCount = 0;

            $checkCount++;

            if ($checkCount % 50 !== 0) {
                return;
            }

            $now = microtime(true);

            if (($now - $lastCheck) < 0.2) {
                return;
            }

            $lastCheck = $now;

            if (!is_resource(STDIN)) {
                call_user_func($this->callback);
                exit(0);
            }

            $currentMeta = stream_get_meta_data(STDIN);
            if ($currentMeta['eof'] || $currentMeta['timed_out']) {
                call_user_func($this->callback);
                exit(0);
            }
        });
    }

    /**
     * Use error handlers to catch interruption scenarios
     */
    private function registerErrorHandlerSignalCatch(): void
    {
        // Custom exception handler for uncaught exceptions
        set_exception_handler(function (\Throwable $exception) {
            call_user_func($this->callback);
            restore_exception_handler();
            throw $exception;
        });

        // Enhanced error handler
        set_error_handler(function ($severity, $message, $file, $line) {
            if ($severity === E_ERROR || $severity === E_CORE_ERROR || $severity === E_COMPILE_ERROR) {
                call_user_func($this->callback);
            }

            // Get and restore original error handler
            $original = set_error_handler(function () {});
            restore_error_handler();

            if ($original && is_callable($original)) {
                return call_user_func($original, $severity, $message, $file, $line);
            }

            return false;
        });
    }

    /**
     * Registers generic fallback handlers for connection abort and memory limit monitoring.
     */
    private function registerGenericFallbackHandler(): void
    {
        $this->registerConnectionAbortHandler();
        $this->registerMemoryLimitHandler();
    }

    /**
     * Registers a tick function to monitor for aborted HTTP connections.
     *
     * Only active in web contexts (non-CLI) where connection_aborted() is available.
     * Checks every 500 ticks if the client connection has been aborted and executes
     * the registered callback if so.
     */
    private function registerConnectionAbortHandler(): void
    {
        if (PHP_SAPI === 'cli' || !function_exists('connection_aborted')) {
            return;
        }

        register_tick_function(function () {
            static $checkCount = 0;
            $checkCount++;

            if ($checkCount % 500 === 0 && connection_aborted()) {
                call_user_func($this->callback);
                exit(0);
            }
        });

        declare(ticks=1000);
    }

    /**
     * Registers a tick function to monitor memory usage approaching configured limits.
     *
     * Checks every 1000 ticks if memory usage exceeds 95% of the configured limit.
     * Logs a warning when the threshold is approached but does not execute callbacks.
     * Skips registration if memory_limit is set to unlimited (-1).
     */
    private function registerMemoryLimitHandler(): void
    {
        if (!function_exists('memory_get_usage') || !function_exists('ini_get')) {
            return;
        }

        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return;
        }

        register_tick_function(function () use ($memoryLimit) {
            static $checkCount = 0;
            $checkCount++;

            if ($checkCount % 1000 !== 0) {
                return;
            }

            $current = memory_get_usage(true);
            $limit = $this->parseMemoryLimit($memoryLimit);

            if ($limit > 0 && $current > ($limit * 0.95)) {
                error_log('Approaching memory limit, executing deferred callbacks');
            }
        });
    }

    /**
     * Parse memory limit string to bytes
     *
     * @param string $limit Memory limit string (e.g., '128M', '1G')
     * @return int Memory limit in bytes
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        $value *= match ($unit) {
            'g' => 1024 * 1024 * 1024,
            'm' => 1024 * 1024,
            'k' => 1024,
            default => 1,
        };

        return $value;
    }

    /**
     * Detect available signal handling capabilities
     */
    private function detectCapabilities(): void
    {
        $this->capabilities = [
            'platform' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'windows_signals' => false,
            'pcntl_signals' => false,
            'posix_monitoring' => false,
            'stdin_monitoring' => false,
            'connection_monitoring' => false,
            'shutdown_function' => true,
        ];

        $this->methods = [];

        if (PHP_OS_FAMILY === 'Windows' && function_exists('sapi_windows_set_ctrl_handler')) {
            $this->methods[] = 'Windows native (sapi_windows_set_ctrl_handler)';
            $this->capabilities['windows_signals'] = true;
        }

        if (function_exists('pcntl_signal')) {
            $this->methods[] = 'Unix pcntl signals';
            $this->capabilities['pcntl_signals'] = true;
        }

        if (function_exists('posix_getppid')) {
            $this->methods[] = 'Unix process monitoring (posix)';
            $this->capabilities['posix_monitoring'] = true;
        }

        if ($this->canMonitorStdin()) {
            $this->methods[] = 'STDIN monitoring';
            $this->capabilities['stdin_monitoring'] = true;
        }

        if (PHP_SAPI !== 'cli' && function_exists('connection_aborted')) {
            $this->methods[] = 'Web connection monitoring';
            $this->capabilities['connection_monitoring'] = true;
        }

        $this->methods[] = 'Generic fallback (shutdown function)';
    }

    /**
     * Get signal handling capabilities
     *
     * @return array Capabilities information
     */
    public function getCapabilities(): array
    {
        return [
            'platform' => $this->capabilities['platform'],
            'sapi' => $this->capabilities['sapi'],
            'methods' => $this->methods,
            'capabilities' => $this->capabilities,
        ];
    }

    /**
     * Test if a specific capability is available
     *
     * @param string $capability Capability name to test
     * @return bool True if capability is available
     */
    public function hasCapability(string $capability): bool
    {
        return $this->capabilities[$capability] ?? false;
    }
}
