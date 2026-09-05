<?php

namespace Tests\Unit;

use Tests\TestCase;

class SmokeTest extends TestCase
{
    public function test_the_application_boots(): void
    {
        $this->assertSame('testing', app()->environment());
    }
}
