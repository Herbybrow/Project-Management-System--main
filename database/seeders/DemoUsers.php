<?php

namespace Database\Seeders;

use App\Models\User;
use RuntimeException;

final class DemoUsers
{
    public static function resolve(string $role, string $preferredEmail): User
    {
        $eligible = User::where('is_active', true)
            ->whereHas('activeRoles', fn ($query) => $query->where('roles.name', $role));

        $preferred = (clone $eligible)->where('email', $preferredEmail)->first();
        if ($preferred) {
            return $preferred;
        }

        $candidates = $eligible->limit(2)->get();
        if ($candidates->count() !== 1) {
            throw new RuntimeException(
                "Demo data requires one active {$role}, or the designated {$preferredEmail} account with that active role. Run DemoSeeder on a disposable database."
            );
        }

        return $candidates->first();
    }
}
