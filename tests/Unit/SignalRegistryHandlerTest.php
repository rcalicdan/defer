<?php

use Rcalicdan\Defer\Handlers\SignalRegistryHandler;

describe('SignalRegistryHandler', function () {
    it('detects capabilities correctly', function () {
        $executed = false;
        $handler = new SignalRegistryHandler(function () use (&$executed) {
            $executed = true;
        });

        $capabilities = $handler->getCapabilities();

        expect($capabilities)->toHaveKeys(['platform', 'sapi', 'methods', 'capabilities']);
        expect($capabilities['platform'])->toBe(PHP_OS_FAMILY);
        expect($capabilities['sapi'])->toBe(PHP_SAPI);
        expect($capabilities['methods'])->toBeArray();
        expect($capabilities['capabilities']['shutdown_function'])->toBeTrue();
    });

    it('checks individual capabilities', function () {
        $handler = new SignalRegistryHandler(fn () => null);

        expect($handler->hasCapability('shutdown_function'))->toBeTrue();
        expect($handler->hasCapability('nonexistent_capability'))->toBeFalse();
    });

    it('registers handlers without errors', function () {
        $handler = new SignalRegistryHandler(fn () => null);

        expect(fn () => $handler->register())->not->toThrow(Throwable::class);
    });
});
