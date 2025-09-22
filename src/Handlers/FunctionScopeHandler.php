<?php

namespace Library\Defer\Handlers;

/**
 * Function-scoped defer handler that executes when the object is destroyed
 */
class FunctionScopeHandler
{
    /**
     * @var array<callable> Function-scoped defer stack
     */
    private array $stack = [];

    /**
     * Add a defer callback to this function scope
     */
    public function defer(callable $callback): void
    {
        if (count($this->stack) >= 50) {
            array_shift($this->stack);
        }

        $this->stack[] = $callback;
    }

    /**
     * Execute all defers when object is destroyed (function ends)
     */
    public function __destruct()
    {
        // Execute in LIFO order when function ends
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            try {
                if (is_callable($this->stack[$i])) {
                    $this->stack[$i]();
                }
            } catch (\Throwable $e) {
                error_log('Defer error: '.$e->getMessage());
            } finally {
                unset($this->stack[$i]);
            }
        }
    }

    /**
     * Get the number of pending defers
     */
    public function count(): int
    {
        return count($this->stack);
    }

    /**
     * Manually execute all defers (useful for testing)
     */
    public function executeAll(): void
    {
        $this->__destruct();
        $this->stack = []; // Clear after manual execution
    }
}
