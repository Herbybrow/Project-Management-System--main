<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\ImplementationActivity;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Review;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActivityLog;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class SeederIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_production_seed_creates_only_reference_data_and_preserves_legacy_assignments(): void
    {
        $legacy = Role::create(['name' => 'Project Implementor']);
        $permission = Permission::create(['code' => 'projects.implement', 'name' => 'Legacy permission']);
        $user = User::factory()->create();
        $user->roles()->attach($legacy->id, ['is_active' => true]);
        $legacy->permissions()->attach($permission->id);
        $this->app->instance('env', 'production');

        $this->app->make(DatabaseSeeder::class)->run();
        $this->app->make(DatabaseSeeder::class)->run();

        $this->assertSame(1, User::count());
        $this->assertSame(0, Project::count());
        $this->assertSame(8, Role::count());
        $this->assertTrue($user->hasRole('Project Implementor'));
        $this->assertTrue($legacy->permissions()->whereKey($permission->id)->exists());
    }

    public function test_demo_seed_keeps_48_projects_and_valid_role_assignments_on_repeat(): void
    {
        $this->seed(DemoSeeder::class);
        $this->assertSame(48, Project::count());
        $this->assertSame(50, User::count());

        foreach (Project::all() as $project) {
            $this->assertTrue($project->reviewer->hasRole('Project Reviewer'));
            $this->assertTrue($project->planner->hasRole('Project Planner'));
            if ($project->coordinator) {
                $this->assertTrue($project->coordinator->hasRole('Project Coordinator'));
            }
            if ($project->approver) {
                $this->assertTrue($project->approver->hasRole('Project Approver'));
            }
        }
        foreach (Document::with(['uploader', 'project'])->get() as $document) {
            $this->assertNotNull($document->uploader);
            $this->assertSame($document->project->planner_id, $document->uploaded_by);
        }
        foreach (ImplementationActivity::with('responsiblePerson')->get() as $activity) {
            $this->assertTrue($activity->responsiblePerson->hasRole('Project Planner'));
        }
        foreach (Review::with('reviewer')->get() as $review) {
            $this->assertTrue($review->reviewer->hasRole('Project Reviewer'));
        }
        $before = [Project::count(), User::count(), Document::count(), Review::count(), UserActivityLog::count()];
        $password = User::where('email', 'planner@example.test')->value('password');
        $this->seed(DemoSeeder::class);
        $this->assertSame($before, [Project::count(), User::count(), Document::count(), Review::count(), UserActivityLog::count()]);
        $this->assertSame($password, User::where('email', 'planner@example.test')->value('password'));
    }

    public function test_missing_workflow_accounts_fail_before_writing_any_projects(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        User::factory()->create();
        try {
            $this->seed(ProjectSeeder::class);
            $this->fail('An arbitrary first user must not be used.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Project Reviewer', $exception->getMessage());
        }
        $this->assertSame(0, Project::count());
        $this->assertSame(0, Document::count());
    }

    public function test_partial_demo_set_is_reported_without_deleting_or_overwriting_it(): void
    {
        $project = Project::create(['name' => 'Existing data', 'annual_plan_reference' => 'NSSF-2026-APR-101']);
        try {
            $this->seed(ProjectSeeder::class);
            $this->fail('An incomplete earlier seed must be reported.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Incomplete or duplicate', $exception->getMessage());
        }
        $this->assertSame(1, Project::count());
        $this->assertSame('Existing data', $project->fresh()->name);
    }

    public function test_demo_seed_is_refused_in_production(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(RuntimeException::class);
        $this->app->make(DemoSeeder::class)->run();
    }
}
