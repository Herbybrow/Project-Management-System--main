<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BootstrapAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_initialize_a_new_administrator_with_a_private_password_prompt(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->artisan('users:bootstrap-admin')
            ->expectsQuestion('Administrator email', 'owner@example.test')
            ->expectsQuestion('Administrator name', 'Site Owner')
            ->expectsQuestion('Password (at least 12 characters, letters and numbers)', 'TestOnlyPass1234')
            ->expectsQuestion('Confirm password', 'TestOnlyPass1234')
            ->assertSuccessful();
        $user = User::where('email', 'owner@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('Project Administrator'));
        $this->assertTrue(Hash::check('TestOnlyPass1234', $user->password));
    }

    public function test_bootstrap_never_resets_or_elevates_an_existing_account(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $user = User::factory()->create(['email' => 'existing@example.test']);
        $password = $user->password;
        $this->artisan('users:bootstrap-admin')
            ->expectsQuestion('Administrator email', 'EXISTING@example.test')
            ->assertFailed();
        $this->assertSame($password, $user->fresh()->password);
        $this->assertFalse($user->roles()->exists());
    }
}
