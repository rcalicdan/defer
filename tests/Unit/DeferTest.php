<?php

use Rcalicdan\Defer\Defer;
use Rcalicdan\Defer\Handlers\ProcessDeferHandler;
use Rcalicdan\Defer\Utilities\DeferInstance;

beforeEach(function () {
    Defer::reset();
});

afterEach(function () {
    Defer::reset();
});

describe('Defer Static Class', function () {
    it('creates a new defer scope instance', function () {
        $scope = Defer::scope();
        
        expect($scope)->toBeInstanceOf(DeferInstance::class);
        expect($scope->count())->toBe(0);
    });

    it('registers global defer callbacks', function () {
        $executed = false;
        
        Defer::global(function () use (&$executed) {
            $executed = true;
        });
        
        $handler = Defer::getHandler();
        $handler->executeAll();
        
        expect($executed)->toBeTrue();
    });

    it('registers terminate callbacks', function () {
        $executed = false;
        
        Defer::terminate(function () use (&$executed) {
            $executed = true;
        });
        
        $handler = Defer::getHandler();
        $handler->executeTerminate();
        
        expect($executed)->toBeTrue();
    });

    it('resets state properly', function () {
        Defer::global(fn () => null);
        
        expect(Defer::getHandler())->toBeInstanceOf(ProcessDeferHandler::class);
        
        Defer::reset();
        
        // After reset, a new handler should be created
        $newHandler = Defer::getHandler();
        expect($newHandler)->toBeInstanceOf(ProcessDeferHandler::class);
    });

    it('maintains singleton pattern for handler', function () {
        $handler1 = Defer::getHandler();
        $handler2 = Defer::getHandler();
        
        expect($handler1)->toBe($handler2);
    });
});