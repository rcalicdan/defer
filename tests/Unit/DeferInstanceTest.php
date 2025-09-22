<?php

use Rcalicdan\Defer\Handlers\FunctionScopeHandler;
use Rcalicdan\Defer\Utilities\DeferInstance;

describe('DeferInstance', function () {
    it('supports method chaining', function () {
        $instance = new DeferInstance;

        $result = $instance->task(fn () => null)
            ->task(fn () => null)
            ->task(fn () => null)
        ;

        expect($result)->toBeInstanceOf(DeferInstance::class);
        expect($instance->count())->toBe(3);
    });

    it('executes tasks in LIFO order', function () {
        $executed = [];
        $instance = new DeferInstance;

        $instance->task(function () use (&$executed) {
            $executed[] = 'first';
        })->task(function () use (&$executed) {
            $executed[] = 'second';
        });

        $instance->executeAll();

        expect($executed)->toBe(['second', 'first']);
    });

    it('provides access to underlying handler', function () {
        $instance = new DeferInstance;
        $handler = $instance->getHandler();

        expect($handler)->toBeInstanceOf(FunctionScopeHandler::class);
    });

    it('counts tasks correctly', function () {
        $instance = new DeferInstance;

        expect($instance->count())->toBe(0);

        $instance->task(fn () => null);
        expect($instance->count())->toBe(1);

        $instance->task(fn () => null);
        expect($instance->count())->toBe(2);
    });
});
