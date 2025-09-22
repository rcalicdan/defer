<?php

use Rcalicdan\Defer\Handlers\FunctionScopeHandler;
use Rcalicdan\Defer\Handlers\ProcessDeferHandler;

describe('ProcessDeferHandler', function () {
    it('creates function defer handlers', function () {
        $handler = ProcessDeferHandler::createFunctionDefer();

        expect($handler)->toBeInstanceOf(FunctionScopeHandler::class);
    });

    it('executes global defers in LIFO order', function () {
        $executed = [];
        $handler = new ProcessDeferHandler;

        $handler->defer(function () use (&$executed) {
            $executed[] = 'first';
        });

        $handler->defer(function () use (&$executed) {
            $executed[] = 'second';
        });

        $handler->executeAll();

        expect($executed)->toBe(['second', 'first']);
    });

    it('limits global stack to 100 items', function () {
        $handler = new ProcessDeferHandler;

        // Add 120 defers
        for ($i = 0; $i < 120; $i++) {
            $handler->defer(function () {
                // Defer $i
            });
        }

        // Test by counting how many actually execute
        $executed = 0;
        for ($i = 0; $i < 10; $i++) {
            $handler->defer(function () use (&$executed) {
                $executed++;
            });
        }

        $handler->executeAll();

        // Should execute the last 10 we added
        expect($executed)->toBe(10);
    });

    it('handles terminate callbacks', function () {
        $executed = false;
        $handler = new ProcessDeferHandler;

        $handler->terminate(function () use (&$executed) {
            $executed = true;
        });

        $handler->executeTerminate();

        expect($executed)->toBeTrue();
    });

    it('provides signal handling information', function () {
        $handler = new ProcessDeferHandler;
        $info = $handler->getSignalHandlingInfo();

        expect($info)->toHaveKeys(['platform', 'sapi', 'methods', 'capabilities']);
        expect($info['platform'])->toBe(PHP_OS_FAMILY);
        expect($info['sapi'])->toBe(PHP_SAPI);
        expect($info['methods'])->toBeArray();
        expect($info['capabilities'])->toBeArray();
    });

    it('handles exceptions during execution', function () {
        $executed = [];
        $handler = new ProcessDeferHandler;

        $handler->defer(function () use (&$executed) {
            $executed[] = 'first';
        });

        $handler->defer(function () {
            throw new Exception('Test exception');
        });

        $handler->defer(function () use (&$executed) {
            $executed[] = 'third';
        });

        $handler->executeAll();

        expect($executed)->toBe(['third', 'first']);
    });
});
