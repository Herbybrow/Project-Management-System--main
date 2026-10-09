<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_login_is_case_insensitive_and_logout_revokes_the_bearer_token(): void
    {
        $user = User::factory()->create(['email' => 'Planner@Example.test']);
        $token = $this->postJson('/api/login', ['login' => 'planner@example.test', 'password' => 'password'])
            ->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_non_email_login_returns_validation_error_instead_of_querying_a_missing_column(): void
    {
        $this->postJson('/api/login', ['login' => 'planner', 'password' => 'password'])->assertUnprocessable();
    }

    public function test_known_email_does_not_receive_privileges_automatically(): void
    {
        Role::create(['name' => 'Project Administrator']);
        $user = User::factory()->create(['email' => 'mussasaid@gmail.com']);
        $this->postJson('/api/login', ['login' => $user->email, 'password' => 'password'])
            ->assertOk()->assertJsonPath('user.roles', []);
        $this->assertFalse($user->roles()->exists());
    }

    public function test_reading_auth_details_does_not_reactivate_disabled_roles(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Project Administrator']);
        $user->roles()->attach($role->id, ['is_active' => false]);
        $this->assertSame([], $user->toAuthArray()['roles']->all());
        $this->assertFalse($user->hasRole($role->name));
    }

    public function test_an_existing_token_cannot_access_a_disabled_account(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['is_active' => false]);
        $this->withToken($token)->getJson('/api/me')->assertForbidden();
        $this->withToken($token)->getJson('/api/user')->assertForbidden();
    }
}
