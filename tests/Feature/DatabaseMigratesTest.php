<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseMigratesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_migration_runs_against_the_test_database(): void
    {
        $user = User::create([
            'full_name' => 'کاربر آزمایشی',
            'mobile' => '09120000000',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('users', ['mobile' => '09120000000']);
        $this->assertSame('کاربر آزمایشی', $user->full_name);
    }
}
