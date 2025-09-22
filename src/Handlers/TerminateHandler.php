<?php

namespace Library\Defer\Handlers;

class TerminateHandler
{
    /**
     * @var array Terminate callbacks (executed after response)
     */
    protected array $terminateStack = [];

    /**
     * @var bool Whether terminate handlers are registered
     */
    protected bool $handlersRegistered = false;

    /**
     * Add a terminate callback
     *
     * @param callable $callback The callback to add
     * @param bool $always Whether to execute even on 4xx/5xx status codes
     */
    public function addCallback(callable $callback, bool $always = false): void
    {
        if (count($this->terminateStack) >= 50) {
            array_shift($this->terminateStack);
        }

        $this->terminateStack[] = [
            'callback' => $callback,
            'always' => $always,
        ];
        
        $this->registerHandlers();
    }

    /**
     * Register terminate handlers based on environment
     */
    protected function registerHandlers(): void
    {
        if ($this->handlersRegistered) {
            return;
        }

        if ($this->isFastCgiEnvironment()) {
            $this->registerFastCgiHandler();
        } elseif (PHP_SAPI === 'cli') {
            $this->registerCliHandler();
        } elseif (PHP_SAPI === 'cli-server') {
            $this->registerDevServerHandler();
        } else {
            $this->registerFallbackHandler();
        }

        $this->handlersRegistered = true;
    }

    /**
     * Check if running in FastCGI environment
     */
    protected function isFastCgiEnvironment(): bool
    {
        return PHP_SAPI === 'fpm-fcgi' ||
            PHP_SAPI === 'cgi-fcgi' ||
            function_exists('fastcgi_finish_request');
    }

    /**
     * Register FastCGI terminate handler
     */
    protected function registerFastCgiHandler(): void
    {
        register_shutdown_function(function () {
            // Finish the FastCGI request first (sends response to client)
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }

            // Then execute terminate callbacks
            $this->executeCallbacks();
        });
    }

    /**
     * Register CLI terminate handler
     */
    protected function registerCliHandler(): void
    {
        register_shutdown_function(function () {
            $this->executeCallbacks();
        });
    }

    /**
     * Register development server handler
     */
    protected function registerDevServerHandler(): void
    {
        register_shutdown_function(function () {
            // Flush all output buffers to send response
            if (ob_get_level() > 0) {
                while (ob_get_level() > 0) {
                    ob_end_flush();
                }
            }
            flush();

            // Then execute terminate callbacks
            $this->executeCallbacks();
        });
    }

    /**
     * Register fallback terminate handler
     */
    protected function registerFallbackHandler(): void
    {
        if (ob_get_level() === 0) {
            ob_start();
        }

        register_shutdown_function(function () {
            // Flush output to send response
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();

            // Then execute terminate callbacks
            $this->executeCallbacks();
        });
    }

    /**
     * Execute all terminate callbacks
     */
    public function executeCallbacks(): void
    {
        if (empty($this->terminateStack)) {
            return;
        }

        $statusCode = $this->getHttpResponseCode();
        $shouldSkipOnError = $this->shouldSkipOnErrorStatus($statusCode);

        try {
            foreach ($this->terminateStack as $index => $item) {
                try {
                    // Skip execution if it's an error status and callback is not marked as 'always'
                    if ($shouldSkipOnError && !$item['always']) {
                        continue;
                    }

                    if (is_callable($item['callback'])) {
                        $item['callback']();
                    }
                } catch (\Throwable $e) {
                    error_log('Terminate callback error: ' . $e->getMessage());
                } finally {
                    unset($this->terminateStack[$index]);
                }
            }
        } finally {
            $this->terminateStack = [];
        }
    }

    /**
     * Get HTTP response code (protected so it can be overridden for testing)
     */
    protected function getHttpResponseCode(): int
    {
        // For CLI, always return 200
        if (PHP_SAPI === 'cli') {
            return 200;
        }

        // Try to get the response code
        $code = http_response_code();
        
        // If no code has been set, default to 200
        if ($code === false) {
            return 200;
        }

        return $code;
    }

    /**
     * Determine if we should skip execution on error status codes
     */
    protected function shouldSkipOnErrorStatus(int $statusCode): bool
    {
        return $statusCode >= 400 && $statusCode < 600;
    }

    /**
     * Get terminate callbacks count
     */
    public function getCallbackCount(): int
    {
        return count($this->terminateStack);
    }

    /**
     * Get environment information
     */
    public function getEnvironmentInfo(): array
    {
        return [
            'sapi' => PHP_SAPI,
            'fastcgi' => $this->isFastCgiEnvironment(),
            'fastcgi_finish_request' => function_exists('fastcgi_finish_request'),
            'output_buffering' => ob_get_level() > 0,
            'current_response_code' => $this->getHttpResponseCode(),
        ];
    }
}