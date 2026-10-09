<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\ImplementationActivity;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Review;
use App\Models\User;
use App\Models\UserActivityLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes load-test / seed demo projects (NSSF-DEMO-APR-*) and the disposable
 * role accounts created only for that demo (reviewer/planner/coordinator/
 * approver @gmail.com). Keeps the seeded Admin + ICT Support accounts.
 */
class PurgeDemoData extends Command
{
    protected $signature = 'demo:purge {--force : Run without confirmation} {--all-projects : Delete every project, not only NSSF-DEMO / seeder rows}';

    protected $description = 'Delete demo/sample projects (NSSF-DEMO-* and seeded NSSF-2026-APR-*) and disposable demo role users.';

    /** @var list<string> */
    private array $demoEmails = [
        'reviewer@gmail.com',
        'planner@gmail.com',
        'coordinator@gmail.com',
        'approver@gmail.com',
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo data deletion is restricted to local or testing environments.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm('Delete demo projects and disposable demo role users?', false)) {
            $this->info('Cancelled.');

            return self::SUCCESS;
        }

        $demoProjectQuery = Project::query()->where(function ($query) {
            $query->where('annual_plan_reference', 'like', 'NSSF-DEMO-%')
                // Sample rows from ProjectSeeder (dashboard fixture pack).
                ->orWhere('annual_plan_reference', 'like', 'NSSF-2026-APR-%');
        });

        // Also clear leftover manual test projects that never got a demo prefix
        // (e.g. Registration System, NEcta) so queues/dashboards start empty.
        if ($this->option('all-projects')) {
            $demoProjectQuery = Project::query();
        }
        $projectCount = (clone $demoProjectQuery)->count();
        $projectIds = (clone $demoProjectQuery)->pluck('id');

        DB::transaction(function () use ($projectIds) {
            if ($projectIds->isNotEmpty()) {
                if (Schema::hasTable('reviews')) {
                    Review::whereIn('project_id', $projectIds)->delete();
                }
                Document::whereIn('project_id', $projectIds)->delete();
                Requirement::whereIn('project_id', $projectIds)->delete();
                ImplementationActivity::whereIn('project_id', $projectIds)->delete();
                Project::whereIn('id', $projectIds)->delete();
            }

            $demoUsers = User::query()->whereIn('email', $this->demoEmails)->get();
            foreach ($demoUsers as $user) {
                $user->roles()->detach();
                if (Schema::hasTable('user_activity_logs')) {
                    UserActivityLog::where('user_id', $user->id)->delete();
                }
                $user->delete();
            }

            // Any leftover planner named exactly for demos (not a system account).
            User::query()
                ->where('name', 'Demo Planner')
                ->whereNotIn('email', ['admin@gmail.com', 'ictsupport@gmail.com'])
                ->each(function (User $user) {
                    $user->roles()->detach();
                    if (Schema::hasTable('user_activity_logs')) {
                        UserActivityLog::where('user_id', $user->id)->delete();
                    }
                    $user->delete();
                });
        });

        $this->info("Removed {$projectCount} demo project(s) and disposable demo role users.");

        return self::SUCCESS;
    }
}
