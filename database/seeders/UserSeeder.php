<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataGuard::check();
        DB::transaction(fn () => $this->seedAccounts());
    }

    private function seedAccounts(): void
    {
        // These fixed-password support accounts are explicit demo data only.
        // Production uses users:bootstrap-admin and User Management.
        $accounts = [
            ['email' => 'admin@gmail.com', 'name' => 'System Admin', 'password' => 'password123', 'role' => 'Project Administrator'],
            ['email' => 'ictsupport@gmail.com', 'name' => 'ICT Support', 'password' => 'password123', 'role' => 'ICT Support'],
        ];

        foreach ($accounts as $account) {
            $role = Role::where('name', $account['role'])->firstOrFail();
            $user = User::firstOrCreate(
                ['email' => $account['email']],
                [
                    'name' => $account['name'],
                    'password' => Hash::make($account['password']),
                    'email_verified_at' => now(),
                    'is_active' => true,
                ]
            );

            if ($user->wasRecentlyCreated) {
                $user->roles()->attach($role->id, ['is_active' => true, 'assigned_at' => now()]);
            } elseif (! $user->is_active || ! $user->hasRole($account['role'])) {
                throw new \RuntimeException('Existing demo support account '.$account['email'].' requires an active '.$account['role'].' role. No account was changed.');
            }
        }
    }
}
