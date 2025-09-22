<?php

namespace Tests\Helpers;

use Rcalicdan\Defer\Handlers\TerminateHandler;

class TestableTerminateHandler extends TerminateHandler
{
    private int $mockStatusCode = 200;

    public function setMockStatusCode(int $code): void
    {
        $this->mockStatusCode = $code;
    }

    protected function getHttpResponseCode(): int
    {
        return $this->mockStatusCode;
    }
}