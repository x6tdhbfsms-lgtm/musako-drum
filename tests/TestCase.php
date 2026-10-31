<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null): static
    {
        return parent::actingAs($user, $guard ?? 'web');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
