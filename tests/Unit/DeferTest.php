<?php

declare(strict_types=1);

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

        Defer::executeAll();

        expect($executed)->toBeTrue();
    });

    it('registers terminate callbacks', function () {
        $executed = false;

        Defer::terminate(function () use (&$executed) {
            $executed = true;
        });

        Defer::executeTerminate();

        expect($executed)->toBeTrue();
    });

    it('resets state properly', function () {
        Defer::global(fn () => null);

        Defer::reset();

        $executed = false;
        Defer::global(function () use (&$executed) {
            $executed = true;
        });

        Defer::executeAll();

        expect($executed)->toBeTrue();
    });

    it('maintains singleton pattern for handler', function () {
        $executed = false;

        Defer::global(function () use (&$executed) {
            $executed = true;
        });

        Defer::signalsEnabled();

        Defer::executeAll();

        expect($executed)->toBeTrue();
    });
});