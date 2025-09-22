<?php

use Rcalicdan\Defer\Defer;

beforeEach(function () {
    Defer::reset();
});

afterEach(function () {
    Defer::reset();
});

describe('Error Handling', function () {
    it('continues execution after exceptions in global defers', function () {
        $executed = [];
        
        Defer::global(function () use (&$executed) {
            $executed[] = 'first';
        });
        
        Defer::global(function () {
            throw new RuntimeException('Test error');
        });
        
        Defer::global(function () use (&$executed) {
            $executed[] = 'third';
        });
        
        // Should execute all, logging the error for the middle one
        Defer::getHandler()->executeAll();
        
        expect($executed)->toBe(['third', 'first']); // LIFO order
    });

    it('handles execution with mixed valid and throwing callbacks', function () {
        $executed = [];
        
        $scope = Defer::scope();
        
        // Add valid callback
        $scope->task(function () use (&$executed) {
            $executed[] = 'first';
        });
        
        // Add callback that throws
        $scope->task(function () {
            throw new RuntimeException('Test exception');
        });
        
        // Add another valid callback
        $scope->task(function () use (&$executed) {
            $executed[] = 'third';
        });
        
        $scope->executeAll();
        
        // Should execute valid callbacks in LIFO order
        expect($executed)->toBe(['third', 'first']);
    });

    it('handles type errors gracefully in production', function () {
        $executed = false;
        $scope = Defer::scope();
        
        $invalidCallback = null;
        
        if (is_callable($invalidCallback)) {
            $scope->task($invalidCallback);
        }
        
        $scope->task(function () use (&$executed) {
            $executed = true;
        });
        
        $scope->executeAll();
        
        expect($executed)->toBeTrue();
    });

    it('handles stack overflow protection', function () {
        $handler = Defer::getHandler();
        $executionCount = 0;
        
        // Add more than the limit (100)
        for ($i = 0; $i < 150; $i++) {
            $handler->defer(function () use (&$executionCount) {
                $executionCount++;
            });
        }
        
        $handler->executeAll();
        
        // Should only execute the last 100
        expect($executionCount)->toBe(100);
    });

    it('handles memory exhaustion gracefully', function () {
        $scope = Defer::scope();
        $handler = $scope->getHandler();
        
        for ($i = 0; $i < 75; $i++) {
            $handler->defer(function () {
                // Each callback takes minimal memory
            });
        }
        
        expect($handler->count())->toBe(50);
    });

    it('handles callable validation at runtime', function () {
        $executed = [];
        $scope = Defer::scope();
        
        $callbacks = [
            function () use (&$executed) { $executed[] = 'valid1'; },
            'not_a_function', // This would be invalid
            function () use (&$executed) { $executed[] = 'valid2'; },
            null, // This would be invalid
            function () use (&$executed) { $executed[] = 'valid3'; },
        ];
        
        foreach ($callbacks as $callback) {
            if (is_callable($callback)) {
                $scope->task($callback);
            }
        }
        
        $scope->executeAll();
        
        // Should execute only valid callbacks in LIFO order
        expect($executed)->toBe(['valid3', 'valid2', 'valid1']);
    });
});