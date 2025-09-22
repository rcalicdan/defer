<?php

namespace Rcalicdan\Defer\Utilities;

use Rcalicdan\Defer\Handlers\FunctionScopeHandler;
use Rcalicdan\Defer\Handlers\ProcessDeferHandler;

/**
 * Function-scoped defer instance with method chaining
 */
class DeferInstance
{
    /**
     * @var FunctionScopeHandler Function-scoped defer handler
     */
    private FunctionScopeHandler $functionHandler;

    /**
     * Initialize with a new function-scoped defer handler
     */
    public function __construct()
    {
        $this->functionHandler = ProcessDeferHandler::createFunctionDefer();
    }

    /**
     * Add a function-scoped defer
     *
     * @param  callable  $callback  The callback to defer
     * @return self For method chaining
     */
    public function task(callable $callback): self
    {
        $this->functionHandler->defer($callback);

        return $this;
    }

    /**
     * Get the number of pending function-scoped defers
     */
    public function count(): int
    {
        return $this->functionHandler->count();
    }

    /**
     * Get the underlying function defer handler (for advanced usage)
     */
    public function getHandler(): FunctionScopeHandler
    {
        return $this->functionHandler;
    }

    /**
     * Manually execute all function-scoped defers (useful for testing)
     */
    public function executeAll(): void
    {
        $this->functionHandler->executeAll();
    }
}
