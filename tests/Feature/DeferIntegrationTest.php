<?php

use Rcalicdan\Defer\Defer;

beforeEach(function () {
    Defer::reset();
});

afterEach(function () {
    Defer::reset();
});

describe('Defer Integration', function () {
    it('handles mixed scope defers correctly', function () {
        $executed = [];
        
        // Global defer
        Defer::global(function () use (&$executed) {
            $executed[] = 'global';
        });
        
        // Function scope
        $scope = Defer::scope();
        $scope->task(function () use (&$executed) {
            $executed[] = 'function';
        });
        
        // Terminate defer
        Defer::terminate(function () use (&$executed) {
            $executed[] = 'terminate';
        });
        
        // Execute in proper order
        $scope->executeAll(); // Function scope first
        Defer::getHandler()->executeTerminate(); // Terminate second
        Defer::getHandler()->executeAll(); // Global last
        
        expect($executed)->toBe(['function', 'terminate', 'global']);
    });

    it('handles nested defer scopes with manual execution', function () {
        $executed = [];
        
        $outerScope = Defer::scope();
        $outerScope->task(function () use (&$executed) {
            $executed[] = 'outer';
            
            $innerScope = Defer::scope();
            $innerScope->task(function () use (&$executed) {
                $executed[] = 'inner';
            });
            // Manually execute inner scope
            $innerScope->executeAll();
        });
        
        $outerScope->executeAll();
        
        // Both should execute since we manually called executeAll() on inner scope
        expect($executed)->toBe(['outer', 'inner']);
    });

    it('handles nested defer scopes with automatic destruction', function () {
        $executed = [];
        
        $executeOuter = function () use (&$executed) {
            $outerScope = Defer::scope();
            $outerScope->task(function () use (&$executed) {
                $executed[] = 'outer';
                
                // Create inner scope that will be destroyed when function ends
                $createInner = function () use (&$executed) {
                    $innerScope = Defer::scope();
                    $innerScope->task(function () use (&$executed) {
                        $executed[] = 'inner';
                    });
                    // innerScope goes out of scope here, triggering destructor
                };
                
                $createInner();
            });
            
            $outerScope->executeAll();
        };
        
        $executeOuter();
        
        // Both should execute: inner via destructor, outer via executeAll()
        expect($executed)->toBe(['outer', 'inner']);
    });

    it('handles resource cleanup scenarios', function () {
        $resources = [];
        
        $scope = Defer::scope();
        
        // Simulate opening resources
        $file1 = 'resource1';
        $file2 = 'resource2';
        $resources[] = $file1;
        $resources[] = $file2;
        
        $scope->task(function () use ($file1, &$resources) {
            $key = array_search($file1, $resources);
            if ($key !== false) unset($resources[$key]);
        })->task(function () use ($file2, &$resources) {
            $key = array_search($file2, $resources); 
            if ($key !== false) unset($resources[$key]);
        });
        
        expect($resources)->toHaveCount(2);
        
        $scope->executeAll();
        
        expect($resources)->toHaveCount(0);
    });

    it('preserves execution order under exceptions', function () {
        $executed = [];
        
        $scope = Defer::scope();
        
        $scope->task(function () use (&$executed) {
            $executed[] = 'cleanup1';
        })->task(function () {
            throw new Exception('Simulated error');
        })->task(function () use (&$executed) {
            $executed[] = 'cleanup3';
        });
        
        $scope->executeAll();
        
        // Should execute in LIFO order, skipping the exception
        expect($executed)->toBe(['cleanup3', 'cleanup1']);
    });
});