<?php

namespace Database\Seeders;

use App\Models\Notification;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

/**
 * Volume for the ICT Support dashboard: users spread across every role
 * (plus a handful with none, and a handful disabled), and account-activity
 * history for the "Recent account activity" table. Development data only.
 */
class UserBulkSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataGuard::check();
        DB::transaction(fn () => $this->seedUsers());
    }

    private function seedUsers(): void
    {
        $emails = array_map(fn ($n) => 'bulk'.$n.'@nssf.or.tz', range(1, 43));
        $existing = User::whereIn('email', $emails)->count();
        if ($existing > 0) {
            if ($existing === 43) {
                return;
            }
            throw new \RuntimeException('Incomplete bulk demo account set. No data was changed; use a new disposable database.');
        }

        $ictSupport = DemoUsers::resolve('ICT Support', 'ictsupport@gmail.com');

        $roleAllocation = [
            'Project Planner' => 14,
            'Project ViewOnly' => 8,
            'Project Reviewer' => 6,
            'Project Coordinator' => 5,
            'Project Approver' => 3,
            'Project Administrator' => 1,
        ];

        $index = 1;
        $created = 0;

        foreach ($roleAllocation as $roleName => $count) {
            $role = Role::where('name', $roleName)->firstOrFail();

            for ($n = 1; $n <= $count; $n++) {
                $created++;
                $isActive = $created % 6 !== 0;
                $daysAgo = 3 + ($created % 20);

                $user = User::create([
                    'name' => $roleName.' '.($n + 1),
                    'email' => 'bulk'.$index.'@nssf.or.tz',
                    'password' => Hash::make('password123'),
                    'is_active' => $isActive,
                    'email_verified_at' => now(),
                ]);
                $user->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

                UserActivityLog::create([
                    'user_id' => $user->id,
                    'action' => 'account_created',
                    'performed_by' => $ictSupport?->id,
                    'created_at' => now()->subDays($daysAgo),
                ]);

                if ($role) {
                    $user->roles()->attach($role->id, [
                        'is_active' => true,
                        'assigned_at' => now()->subDays($daysAgo),
                    ]);
                    UserActivityLog::create([
                        'user_id' => $user->id,
                        'action' => 'role_assigned',
                        'performed_by' => $ictSupport?->id,
                        'created_at' => now()->subDays($daysAgo)->addMinutes(5),
                    ]);
                }

                if (! $isActive) {
                    UserActivityLog::create([
                        'user_id' => $user->id,
                        'action' => 'account_disabled',
                        'performed_by' => $ictSupport?->id,
                        'created_at' => now()->subDays(max(0, $daysAgo - 2)),
                    ]);
                }

                $index++;
            }
        }

        // Users without any role — the highest-priority card on the dashboard.
        for ($n = 1; $n <= 6; $n++) {
            $user = User::create([
                'name' => 'Unassigned Account '.$n,
                'email' => 'bulk'.$index.'@nssf.or.tz',
                'password' => Hash::make('password123'),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
            $daysAgo = $n;
            $user->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
            UserActivityLog::create([
                'user_id' => $user->id,
                'action' => 'account_created',
                'performed_by' => $ictSupport?->id,
                'created_at' => now()->subDays($daysAgo),
            ]);
            $index++;
        }

        // A handful of recent password resets, spread over the last couple of days,
        // so "Recent account activity" reads like a real, active system.
        $resetTargets = User::where('email', 'like', 'bulk%')->orderBy('id')->limit(9)->get();
        $hoursAgoCycle = [2, 5, 26, 30, 48, 50, 3, 8, 20];
        foreach ($resetTargets as $i => $target) {
            UserActivityLog::create([
                'user_id' => $target->id,
                'action' => 'password_reset',
                'performed_by' => $ictSupport?->id,
                'created_at' => now()->subHours($hoursAgoCycle[$i % count($hoursAgoCycle)]),
            ]);
        }

        $this->seedNotifications();
    }

    /**
     * Populates the notifications table so "Notification engine" reflects
     * real deadline-reminder output instead of an empty state.
     */
    private function seedNotifications(): void
    {
        $recipients = User::where('email', 'like', 'bulk%')->orderBy('id')->limit(10)->get();
        if ($recipients->isEmpty()) {
            return;
        }

        $today = ['Upcoming', 'Upcoming', 'Overdue', 'Upcoming', 'Overdue'];
        foreach ($today as $i => $type) {
            Notification::create([
                'user_id' => $recipients[$i % $recipients->count()]->id,
                'type' => $type,
                'message' => $type === 'Upcoming'
                    ? 'Activity starts soon.'
                    : 'Activity is overdue.',
                'created_at' => now()->subHours($i + 1),
            ]);
        }

        foreach (range(1, 4) as $daysAgo) {
            Notification::create([
                'user_id' => $recipients[$daysAgo % $recipients->count()]->id,
                'type' => $daysAgo % 2 === 0 ? 'Overdue' : 'Upcoming',
                'message' => $daysAgo % 2 === 0 ? 'Activity is overdue.' : 'Activity starts soon.',
                'created_at' => now()->subDays($daysAgo),
            ]);
        }
    }
}
