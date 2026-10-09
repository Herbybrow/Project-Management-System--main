<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class BootstrapAdministrator extends Command
{
    protected $signature = 'users:bootstrap-admin';

    protected $description = 'Create a new administrator with an interactively entered password; existing accounts are never changed.';

    public function handle(): int
    {
        $role = Role::where('name', 'Project Administrator')->first();
        if (! $role) {
            $this->error('Run db:seed --class=ReferenceDataSeeder first.');

            return self::FAILURE;
        }

        $email = strtolower(trim((string) $this->ask('Administrator email')));
        if (User::whereRaw('LOWER(email) = ?', [$email])->exists()) {
            $this->error('This account already exists. Use User Management to change its roles or password.');

            return self::FAILURE;
        }

        $values = [
            'email' => $email,
            'name' => $this->ask('Administrator name'),
            'password' => $this->secret('Password (at least 12 characters, letters and numbers)'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];
        $validator = Validator::make($values, [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        DB::transaction(function () use ($values, $role) {
            $user = User::create([
                'email' => $values['email'],
                'name' => $values['name'],
                'password' => $values['password'],
                'email_verified_at' => now(),
                'is_active' => true,
            ]);
            $user->roles()->attach($role->id, ['is_active' => true, 'assigned_at' => now()]);
        });

        $this->info('Administrator created. Use User Management to create the other required accounts.');

        return self::SUCCESS;
    }
}
