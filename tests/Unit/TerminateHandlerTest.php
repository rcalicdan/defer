<?php

namespace Tests\Unit;

use Rcalicdan\Defer\Handlers\TerminateHandler;
use Tests\Helpers\TestableTerminateHandler;
use Exception;

describe('TerminateHandler', function () {
    it('adds callbacks correctly', function () {
        $handler = new TerminateHandler;

        expect($handler->getCallbackCount())->toBe(0);

        $handler->addCallback(fn () => null);
        expect($handler->getCallbackCount())->toBe(1);

        $handler->addCallback(fn () => null, true);
        expect($handler->getCallbackCount())->toBe(2);
    });

    it('limits callback stack to 50 items', function () {
        $handler = new TerminateHandler;

        for ($i = 0; $i < 60; $i++) {
            $handler->addCallback(fn () => null);
        }

        expect($handler->getCallbackCount())->toBe(50);
    });

    it('executes callbacks on success status', function () {
        $handler = new TestableTerminateHandler;
        $handler->setMockStatusCode(200);

        $executed = [];

        $handler->addCallback(function () use (&$executed) {
            $executed[] = 'normal';
        });

        $handler->addCallback(function () use (&$executed) {
            $executed[] = 'always';
        }, true);

        $handler->executeCallbacks();

        expect($executed)->toBe(['normal', 'always']);
    });

    it('skips normal callbacks on error status but executes always callbacks', function () {
        $handler = new TestableTerminateHandler;
        $handler->setMockStatusCode(500);

        $executed = [];

        $handler->addCallback(function () use (&$executed) {
            $executed[] = 'normal';
        });

        $handler->addCallback(function () use (&$executed) {
            $executed[] = 'always';
        }, true);

        $handler->executeCallbacks();

        expect($executed)->toBe(['always']);
    });

    it('provides environment information', function () {
        $handler = new TerminateHandler;
        $info = $handler->getEnvironmentInfo();

        expect($info)->toHaveKeys([
            'sapi',
            'fastcgi',
            'fastcgi_finish_request',
            'output_buffering',
            'current_response_code',
        ]);

        expect($info['sapi'])->toBe(PHP_SAPI);
        expect($info['fastcgi'])->toBeBool();
    });

    it('handles exceptions in callbacks gracefully', function () {
        $handler = new TestableTerminateHandler;
        $executed = [];

        $handler->addCallback(function () use (&$executed) {
            $executed[] = 'first';
        });

        $handler->addCallback(function () {
            throw new Exception('Test exception');
        });

        $handler->addCallback(function () use (&$executed) {
            $executed[] = 'third';
        });

        $handler->executeCallbacks();

        expect($executed)->toBe(['first', 'third']);
    });
});