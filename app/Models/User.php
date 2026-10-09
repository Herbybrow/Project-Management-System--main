<?php

namespace App\Models;

use App\Traits\HasPermissions;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, HasPermissions, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_active',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function toAuthArray(): array
    {
        $this->unsetRelation('activeRoles');
        $this->load('activeRoles');

        $roles = $this->activeRoles
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
            ])
            ->values();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => (bool) ($this->is_active ?? true),
            'role' => data_get($roles->first(), 'name'),
            'roles' => $roles,
            'permissions' => $this->permissionCodes(),
        ];
    }

    /**
     * Aggregates for the ICT Support dashboard at "/".
     */
    public static function ictSupportDashboard(): array
    {
        $total = static::count();
        $active = static::where('is_active', true)->count();
        $disabled = static::where('is_active', false)->count();
        $passwordResets = UserActivityLog::where('action', 'password_reset')->count();

        $roleNames = [
            'Project Planner',
            'Project Reviewer',
            'Project ViewOnly',
            'Project Coordinator',
            'Project Approver',
            'Project Administrator',
        ];

        $roleCounts = \Illuminate\Support\Facades\DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->whereIn('roles.name', $roleNames)
            ->where(function ($query) {
                $query->where('user_roles.is_active', true)->orWhereNull('user_roles.is_active');
            })
            ->selectRaw('roles.name as role, COUNT(DISTINCT user_roles.user_id) as count')
            ->groupBy('roles.name')
            ->pluck('count', 'role');

        $usersByRole = collect($roleNames)
            ->map(fn (string $roleName) => [
                'role' => $roleName,
                'count' => (int) ($roleCounts[$roleName] ?? 0),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $recentActivity = UserActivityLog::with('user:id,name')
            ->latest('created_at')
            ->limit(5)
            ->get()
            ->map(fn (UserActivityLog $log) => [
                'user' => $log->user?->name ?? 'Unknown user',
                'action' => $log->action,
                'when' => $log->created_at,
            ])
            ->all();

        $usersWithoutRoles = static::whereDoesntHave('roles', function ($query) {
            $query->where(function ($inner) {
                $inner->where('user_roles.is_active', true)->orWhereNull('user_roles.is_active');
            });
        })->count();

        return [
            'metrics' => [
                'total_users' => $total,
                'active_accounts' => $active,
                'disabled_accounts' => $disabled,
                'password_resets' => $passwordResets,
            ],
            'users_by_role' => $usersByRole,
            'users_without_roles' => $usersWithoutRoles,
            'recent_activity' => $recentActivity,
            'notification_engine' => [
                'scheduler_running' => true,
                'last_run_at' => Notification::max('created_at'),
                'alerts_sent_today' => Notification::whereDate('created_at', now()->toDateString())->count(),
                'failed_deliveries' => 0,
            ],
        ];
    }
}
