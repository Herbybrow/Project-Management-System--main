<?php

namespace Tests\Feature;

use App\Models\ImplementationActivity;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Person3PlannerTest extends TestCase
{
    use RefreshDatabase;

    private function actingPlanner(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Project Planner']);
        $permission = Permission::firstOrCreate(
            ['code' => 'projects.plan'],
            ['name' => 'projects.plan', 'module' => 'test']
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
        $user->roles()->attach($role->id, ['is_active' => true, 'assigned_at' => now()]);
        Sanctum::actingAs($user);

        return $user->fresh();
    }

    #[Test]
    public function plan_cannot_be_submitted_without_activities(): void
    {
        $user = $this->actingPlanner();
        $project = Project::create([
            'name' => 'Empty Plan',
            'plan_review_status' => 'draft',
            'planner_id' => $user->id,
        ]);

        $this->postJson("/api/projects/{$project->id}/plan/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['plan']);
    }

    #[Test]
    public function returned_plan_edits_are_flagged_until_resubmit(): void
    {
        $user = $this->actingPlanner();
        $reviewer = User::factory()->create();
        $reviewRole = Role::firstOrCreate(['name' => 'Project Reviewer']);
        $perm = Permission::firstOrCreate(
            ['code' => 'projects.review'],
            ['name' => 'projects.review', 'module' => 'test']
        );
        $reviewRole->permissions()->syncWithoutDetaching([$perm->id]);
        $reviewer->roles()->attach($reviewRole->id, ['is_active' => true, 'assigned_at' => now()]);

        $project = Project::create([
            'name' => 'Portal',
            'plan_review_status' => 'draft',
            'planner_id' => $user->id,
        ]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Kickoff',
            'expected_deliverable' => 'Minutes',
            'planned_start_date' => now()->subDays(10)->toDateString(),
            'planned_end_date' => now()->addDays(10)->toDateString(),
            'responsible_person_id' => $user->id,
            'status' => 'not_started',
        ]);

        $this->postJson("/api/projects/{$project->id}/plan/submit")->assertOk();

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/projects/{$project->id}/plan/review", [
            'decision' => 'returned',
            'comment' => 'Add a testing activity',
        ])->assertOk();

        Sanctum::actingAs($user);
        $this->putJson("/api/activities/{$activity->id}", [
            'name' => 'Kickoff revised',
        ])->assertOk();

        $this->assertTrue($project->fresh()->plan_pending_reapproval);
        $this->assertSame('changes_requested', $project->fresh()->plan_review_status);
        $this->assertSame('Kickoff revised', $activity->fresh()->name);

        $this->getJson("/api/projects/{$project->id}/workflow")
            ->assertOk()
            ->assertJsonPath('data.plan_pending_reapproval', true)
            ->assertJsonPath('data.plan_review_comment', 'Add a testing activity');

        $this->postJson("/api/projects/{$project->id}/plan/submit")->assertOk()
            ->assertJsonPath('data.plan_review_status', 'pending_review')
            ->assertJsonPath('data.plan_pending_reapproval', false);
    }

    #[Test]
    public function actual_dates_cannot_be_before_planned_start_or_in_the_future(): void
    {
        $user = $this->actingPlanner();
        $project = Project::create([
            'name' => 'Dates',
            'plan_review_status' => 'draft',
            'planner_id' => $user->id,
        ]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Build',
            'expected_deliverable' => 'Module',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(5)->toDateString(),
            'responsible_person_id' => $user->id,
        ]);

        $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => now()->subDay()->toDateString(),
            'remark' => 'Started early',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['actual_start_date']);

        $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => now()->addDay()->toDateString(),
            'remark' => 'Future start',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['actual_start_date']);

        $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => now()->toDateString(),
            'remark' => 'Started today',
        ])->assertOk();
    }

    #[Test]
    public function matrix_scores_are_zero_fifty_or_one_hundred_from_progress_endpoint(): void
    {
        $planner = $this->actingPlanner();
        $project = Project::create(['name' => 'SRS', 'planner_id' => $planner->id]);
        Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-1',
            'description' => 'Login',
            'implementation_status' => 'Pending',
        ]);
        $ongoing = Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-2',
            'description' => 'Search',
            'implementation_status' => 'Pending',
        ]);

        $this->patchJson("/api/requirements/{$ongoing->id}/status", [
            'implementation_status' => 'Ongoing',
            'remarks' => 'In progress',
        ])->assertOk()
            ->assertJsonPath('data.score_percent', 50)
            ->assertJsonPath('overall_implementation_score', '25%');

        $this->getJson("/api/projects/{$project->id}/progress")
            ->assertOk()
            ->assertJsonPath('overall_progress', '25%')
            ->assertJsonPath('scoring.Ongoing', 50)
            ->assertJsonPath('scoring.Completed', 100);
    }

    #[Test]
    public function planner_metrics_and_closure_helpers_use_person3_contracts(): void
    {
        $user = $this->actingPlanner();
        $project = Project::create([
            'name' => 'Close me',
            'planner_id' => $user->id,
            'plan_review_status' => 'approved',
        ]);
        ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Done',
            'expected_deliverable' => 'Report',
            'planned_start_date' => now()->subDays(5)->toDateString(),
            'planned_end_date' => now()->toDateString(),
            'responsible_person_id' => $user->id,
            'actual_end_date' => now()->toDateString(),
            'status' => 'completed',
        ]);
        Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-1',
            'description' => 'UAT',
            'implementation_status' => 'Completed',
            'test_result' => 'Pass',
        ]);

        $this->assertTrue($project->fresh()->hasCompletedAllActivities());
        $this->assertTrue($project->fresh()->hasPassedAllUAT());

        $metrics = Project::getPlannerMetrics($user->id);
        $this->assertSame(1, $metrics['assigned_projects']);
        $this->assertSame(1, $metrics['active_activities']);
        $this->assertSame(0, $metrics['pending_matrix_items']);
    }
}
