<?php

use Rcalicdan\Defer\Handlers\FunctionScopeHandler;

describe('FunctionScopeHandler', function () {
    it('executes defers in LIFO order', function () {
        $executed = [];
        $handler = new FunctionScopeHandler;

        $handler->defer(function () use (&$executed) {
            $executed[] = 'first';
        });

        $handler->defer(function () use (&$executed) {
            $executed[] = 'second';
        });

        $handler->defer(function () use (&$executed) {
            $executed[] = 'third';
        });

        $handler->executeAll();

        expect($executed)->toBe(['third', 'second', 'first']);
    });

    it('counts defers correctly', function () {
        $handler = new FunctionScopeHandler;

        expect($handler->count())->toBe(0);

        $handler->defer(fn () => null);
        expect($handler->count())->toBe(1);

        $handler->defer(fn () => null);
        expect($handler->count())->toBe(2);

        $handler->executeAll();
        expect($handler->count())->toBe(0);
    });

    it('limits stack size to 50 items', function () {
        $handler = new FunctionScopeHandler;

        // Add 60 defers
        for ($i = 0; $i < 60; $i++) {
            $handler->defer(function () {
                // Defer $i
            });
        }

        expect($handler->count())->toBe(50);
    });

    it('handles exceptions in defers gracefully', function () {
        $executed = [];
        $handler = new FunctionScopeHandler;

        $handler->defer(function () use (&$executed) {
            $executed[] = 'first';
        });

        $handler->defer(function () {
            throw new Exception('Test exception');
        });

        $handler->defer(function () use (&$executed) {
            $executed[] = 'third';
        });

        // Should not throw exception, but log error
        $handler->executeAll();

        // First and third should still execute despite middle exception
        expect($executed)->toBe(['third', 'first']);
    });

    it('executes defers on destruction', function () {
        $executed = false;

        // Create scope and let it go out of scope
        $createScope = function () use (&$executed) {
            $handler = new FunctionScopeHandler;
            $handler->defer(function () use (&$executed) {
                $executed = true;
            });
            // Handler goes out of scope here, triggering __destruct
        };

        $createScope();

        expect($executed)->toBeTrue();
    });
});
