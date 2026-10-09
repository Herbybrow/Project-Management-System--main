<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DemoUserSeeder extends Seeder
{
    public const ACCOUNTS = [
        'reviewer@example.test' => 'Project Reviewer',
        'planner@example.test' => 'Project Planner',
        'coordinator@example.test' => 'Project Coordinator',
        'approver@example.test' => 'Project Approver',
        'viewonly@example.test' => 'Project ViewOnly',
    ];

    public function run(): void
    {
        DemoDataGuard::check();

        foreach (self::ACCOUNTS as $email => $roleName) {
            $role = Role::where('name', $roleName)->firstOrFail();
            $user = User::firstOrCreate(['email' => $email], [
                'name' => 'Demo '.$roleName,
                'password' => 'password123',
                'email_verified_at' => now(),
                'is_active' => true,
            ]);

            // Do not change privileges or reactivate an existing account.
            if ($user->wasRecentlyCreated) {
                $user->roles()->attach($role->id, ['is_active' => true, 'assigned_at' => now()]);
            } elseif (! $user->is_active || ! $user->hasRole($roleName)) {
                throw new RuntimeException("Existing demo account {$email} must have an active {$roleName} role.");
            }
        }
    }
}
