<?php

use Rcalicdan\Defer\Defer;

beforeEach(function () {
    Defer::reset();
});

afterEach(function () {
    Defer::reset();
});

describe('Real World Scenarios', function () {
    it('handles database transaction simulation', function () {
        $transactionState = 'none';
        $committed = false;
        
        $scope = Defer::scope();
        
        // Start transaction
        $transactionState = 'started';
        
        // Defer rollback (will be overridden if commit succeeds)
        $scope->task(function () use (&$transactionState, &$committed) {
            if (!$committed) {
                $transactionState = 'rolled_back';
            }
        });
        
        // Do some work...
        $work_successful = true;
        
        if ($work_successful) {
            $committed = true;
            $transactionState = 'committed';
        }
        
        $scope->executeAll();
        
        expect($transactionState)->toBe('committed');
        expect($committed)->toBeTrue();
    });

    it('handles file operations with cleanup', function () {
        $files = [];
        $scope = Defer::scope();
        
        // Simulate creating temp files
        for ($i = 1; $i <= 3; $i++) {
            $filename = "temp_file_{$i}.tmp";
            $files[] = $filename;
            
            // Defer cleanup for each file
            $scope->task(function () use ($filename, &$files) {
                $key = array_search($filename, $files);
                if ($key !== false) {
                    unset($files[$key]);
                }
            });
        }
        
        expect($files)->toHaveCount(3);
        
        $scope->executeAll();
        
        expect($files)->toHaveCount(0);
    });

    it('handles API request lifecycle', function () {
        $metrics = [];
        $startTime = microtime(true);
        
        // Global defer for request metrics
        Defer::global(function () use (&$metrics, $startTime) {
            $metrics['total_time'] = microtime(true) - $startTime;
            $metrics['status'] = 'completed';
        });
        
        // Terminate defer for cleanup after response
        Defer::terminate(function () use (&$metrics) {
            $metrics['cleanup_done'] = true;
        });
        
        // Function scope for request-specific cleanup
        $scope = Defer::scope();
        $scope->task(function () use (&$metrics) {
            $metrics['request_cleanup'] = true;
        });
        
        // Simulate request processing
        usleep(1000); // 1ms
        
        // Execute cleanup in order
        $scope->executeAll();
        Defer::getHandler()->executeTerminate();
        Defer::getHandler()->executeAll();
        
        expect($metrics)->toHaveKey('request_cleanup');
        expect($metrics)->toHaveKey('cleanup_done');
        expect($metrics)->toHaveKey('total_time');
        expect($metrics)->toHaveKey('status');
        expect($metrics['status'])->toBe('completed');
    });

    it('handles memory management scenarios', function () {
        $allocatedMemory = [];
        $scope = Defer::scope();
        
        // Simulate allocating memory blocks
        for ($i = 1; $i <= 5; $i++) {
            $block = str_repeat('x', 1024); // 1KB block
            $allocatedMemory["block_{$i}"] = $block;
            
            // Defer cleanup
            $scope->task(function () use ($i, &$allocatedMemory) {
                unset($allocatedMemory["block_{$i}"]);
            });
        }
        
        expect($allocatedMemory)->toHaveCount(5);
        
        $scope->executeAll();
        
        expect($allocatedMemory)->toHaveCount(0);
    });

    it('handles concurrent operation simulation', function () {
        $locks = [];
        $results = [];
        
        $scope = Defer::scope();
        
        // Simulate acquiring locks
        for ($i = 1; $i <= 3; $i++) {
            $lockName = "lock_{$i}";
            $locks[$lockName] = true;
            
            // Defer lock release
            $scope->task(function () use ($lockName, &$locks) {
                unset($locks[$lockName]);
            });
            
            // Simulate work
            $results[] = "work_{$i}_done";
        }
        
        expect($locks)->toHaveCount(3);
        expect($results)->toHaveCount(3);
        
        $scope->executeAll();
        
        expect($locks)->toHaveCount(0);
        expect($results)->toHaveCount(3);
    });
});