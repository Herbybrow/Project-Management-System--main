<?php

namespace Tests\Feature;

use App\Models\ImplementationActivity;
use App\Models\Project;
use App\Models\Permission;
use App\Models\Requirement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlanLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function authenticate(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        return $user;
    }

    private function authenticateAsAdministrator(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'Project Administrator']);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'is_active' => true]);
        Sanctum::actingAs($user);
        return $user;
    }

    private function authenticateAsReviewer(): User
    {
        $user = $this->authenticate();
        $role = Role::firstOrCreate(['name' => 'Project Reviewer']);
        $permission = Permission::firstOrCreate(['code' => 'projects.review'], ['name' => 'Review projects']);
        $role->permissions()->attach($permission->id);
        $user->roles()->attach($role->id, ['is_active' => true]);
        return $user;
    }

    // --- plan/submit ---------------------------------------------------

    #[Test]
    public function it_submits_a_draft_plan_for_review()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id]);
        ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Kickoff']);

        $response = $this->postJson("/api/projects/{$project->id}/plan/submit");

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $project->id,
                    'plan_status' => 'pending_review',
                ],
            ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'plan_status' => 'pending_review',
        ]);
        $this->assertNotNull($project->fresh()->plan_submitted_at);
    }

    #[Test]
    public function it_allows_a_project_administrator_to_submit_any_plan()
    {
        $this->authenticateAsAdministrator();
        $otherPlanner = User::factory()->create();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $otherPlanner->id]);
        ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Kickoff']);

        $response = $this->postJson("/api/projects/{$project->id}/plan/submit");

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'pending_review']);
    }

    #[Test]
    public function it_forbids_submitting_a_plan_you_are_not_assigned_to()
    {
        $this->authenticate();
        $otherPlanner = User::factory()->create();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $otherPlanner->id]);
        ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Kickoff']);

        $response = $this->postJson("/api/projects/{$project->id}/plan/submit");

        $response->assertStatus(403);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'draft']);
    }

    #[Test]
    public function it_refuses_to_submit_a_plan_that_is_already_pending_review()
    {
        $user = $this->authenticate();
        $project = Project::create([
            'name' => 'Test Project',
            'planner_id' => $user->id,
            'plan_status' => 'pending_review',
        ]);
        ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Kickoff']);

        $response = $this->postJson("/api/projects/{$project->id}/plan/submit");

        $response->assertStatus(422);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'pending_review']);
    }

    #[Test]
    public function it_refuses_to_submit_a_plan_with_no_activities()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id]);

        $response = $this->postJson("/api/projects/{$project->id}/plan/submit");

        $response->assertStatus(422);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'draft']);
    }

    // --- matrix/return (Person 2's endpoint — schema-compat only) -------

    #[Test]
    public function it_returns_the_matrix_with_a_comment_and_flags_the_plan_as_changes_requested()
    {
        $this->authenticateAsReviewer();
        $project = Project::create(['name' => 'Test Project', 'plan_status' => 'pending_review']);

        $response = $this->postJson("/api/projects/{$project->id}/matrix/return", [
            'comment' => 'Please add acceptance criteria to REQ-003.',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'project_id' => $project->id,
                    'plan_status' => 'changes_requested',
                    'return_comment' => 'Please add acceptance criteria to REQ-003.',
                ],
            ]);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'plan_status' => 'changes_requested',
            'plan_return_comment' => 'Please add acceptance criteria to REQ-003.',
        ]);
    }

    #[Test]
    public function it_requires_a_comment_to_return_the_matrix()
    {
        $this->authenticateAsReviewer();
        $project = Project::create(['name' => 'Test Project']);

        $response = $this->postJson("/api/projects/{$project->id}/matrix/return", []);

        $response->assertStatus(422)->assertJsonValidationErrors(['comment']);
    }

    // --- requirements/{id}/review (Person 2's endpoint) ------------------

    #[Test]
    public function it_saves_a_review_decision_on_a_requirement()
    {
        $this->authenticateAsReviewer();
        $project = Project::create(['name' => 'Test Project']);
        $requirement = Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-001',
            'description' => 'Test requirement',
        ]);

        $response = $this->patchJson("/api/requirements/{$requirement->id}/review", [
            'review_decision' => 'approved',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'id' => $requirement->id,
                    'review_decision' => 'approved',
                ],
            ]);

        $this->assertDatabaseHas('requirements', [
            'id' => $requirement->id,
            'review_decision' => 'approved',
        ]);
    }

    #[Test]
    public function it_rejects_an_invalid_review_decision()
    {
        $this->authenticateAsReviewer();
        $project = Project::create(['name' => 'Test Project']);
        $requirement = Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-001',
            'description' => 'Test requirement',
        ]);

        $response = $this->patchJson("/api/requirements/{$requirement->id}/review", [
            'review_decision' => 'maybe',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['review_decision']);
    }

    // --- activity date validation ---------------------------------------

    #[Test]
    public function it_rejects_an_actual_start_date_before_the_planned_start_date()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'planned_start_date' => '2026-02-10',
            'planned_end_date' => '2026-02-20',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => '2026-02-01',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['actual_start_date']);
    }

    #[Test]
    public function it_rejects_a_future_actual_start_date()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'planned_start_date' => '2020-01-01',
            'planned_end_date' => '2020-02-01',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => now()->addDays(5)->toDateString(),
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['actual_start_date']);
    }

    #[Test]
    public function it_rejects_an_actual_end_date_before_the_actual_start_date()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'planned_start_date' => '2020-01-01',
            'planned_end_date' => '2020-02-01',
            'actual_start_date' => '2020-01-10',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", [
            'actual_end_date' => '2020-01-05',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['actual_end_date']);
    }

    #[Test]
    public function it_accepts_valid_actual_dates()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'planned_start_date' => '2020-01-01',
            'planned_end_date' => '2020-02-01',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => '2020-01-05',
        ]);

        $response->assertStatus(200);
        $this->assertSame('2020-01-05', $activity->fresh()->actual_start_date->toDateString());
    }

    // --- edit locking ----------------------------------------------------

    #[Test]
    public function it_blocks_creating_an_activity_while_pending_review()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'pending_review']);

        $response = $this->postJson('/api/activities', [
            'project_id' => $project->id,
            'name' => 'New activity',
            'expected_deliverable' => 'Something',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-05',
            'responsible_person_id' => User::factory()->create()->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseCount('implementation_activities', 0);
    }

    #[Test]
    public function it_blocks_a_plan_field_edit_while_pending_review()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'pending_review']);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", ['name' => 'Renamed']);

        $response->assertStatus(403);
        $this->assertDatabaseHas('implementation_activities', ['id' => $activity->id, 'name' => 'Excavation']);
    }

    #[Test]
    public function it_allows_a_progress_only_update_while_pending_review()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'pending_review']);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'planned_start_date' => '2020-01-01',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => '2020-01-05',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'pending_review']);
    }

    #[Test]
    public function it_blocks_deleting_an_activity_while_pending_review()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'pending_review']);
        $activity = ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Excavation']);

        $response = $this->deleteJson("/api/activities/{$activity->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('implementation_activities', ['id' => $activity->id]);
    }

    #[Test]
    public function it_reopens_an_approved_plan_when_a_plan_field_is_edited()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'approved']);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", ['name' => 'Renamed']);

        $response->assertStatus(200);
        $this->assertDatabaseHas('implementation_activities', ['id' => $activity->id, 'name' => 'Renamed']);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'changes_requested']);
    }

    #[Test]
    public function it_reopens_an_approved_plan_when_an_activity_is_created()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'approved']);

        $response = $this->postJson('/api/activities', [
            'project_id' => $project->id,
            'name' => 'New activity',
            'expected_deliverable' => 'Something',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-05',
            'responsible_person_id' => User::factory()->create()->id,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'changes_requested']);
    }

    #[Test]
    public function it_reopens_an_approved_plan_when_an_activity_is_deleted()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'approved']);
        $activity = ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Excavation']);

        $response = $this->deleteJson("/api/activities/{$activity->id}");

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'changes_requested']);
    }

    #[Test]
    public function it_does_not_reopen_an_approved_plan_for_a_progress_only_update()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'approved']);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'planned_start_date' => '2020-01-01',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", [
            'actual_start_date' => '2020-01-05',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'approved']);
    }

    #[Test]
    public function resaving_unchanged_planning_fields_does_not_reopen_the_plan_or_flag_a_pending_change()
    {
        $user = $this->authenticate();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $user->id, 'plan_status' => 'approved']);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'expected_deliverable' => 'Trench',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-05',
            'responsible_person_id' => $user->id,
            'plan_change_status' => 'approved',
        ]);

        // Same edit form always resubmits every planning field, even the
        // ones nobody touched — this reproduces clicking Save without
        // actually changing anything.
        $response = $this->putJson("/api/activities/{$activity->id}", [
            'name' => 'Excavation',
            'expected_deliverable' => 'Trench',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-05',
            'responsible_person_id' => $user->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'plan_status' => 'approved']);
        $this->assertDatabaseHas('implementation_activities', [
            'id' => $activity->id,
            'plan_change_status' => 'approved',
        ]);
    }

    #[Test]
    public function editing_an_activity_after_execution_has_started_does_not_regress_the_project_back_to_planning()
    {
        $user = $this->authenticate();
        $project = Project::create([
            'name' => 'Test Project',
            'planner_id' => $user->id,
            'plan_status' => 'approved',
            'plan_review_status' => 'approved',
            'phase' => 'Execution',
            'status' => 'In Execution',
            'execution_started_at' => now(),
        ]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Excavation',
            'plan_change_status' => 'approved',
        ]);

        $response = $this->putJson("/api/activities/{$activity->id}", ['name' => 'Renamed']);

        $response->assertStatus(200);
        // The activity's own change still needs sign-off...
        $this->assertDatabaseHas('implementation_activities', [
            'id' => $activity->id,
            'name' => 'Renamed',
            'plan_change_status' => 'pending',
        ]);
        // ...but the project itself must not be relabelled back to Planning
        // just because one already-executing activity was tweaked.
        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'plan_status' => 'approved',
            'phase' => 'Execution',
            'status' => 'In Execution',
        ]);
    }

    // --- activity mutation authorisation ------------------------------------

    #[Test]
    public function it_forbids_editing_activities_on_a_project_you_are_not_assigned_to()
    {
        $this->authenticate();
        $otherPlanner = User::factory()->create();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $otherPlanner->id]);
        $activity = ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Excavation']);

        $this->putJson("/api/activities/{$activity->id}", ['name' => 'Renamed'])->assertStatus(403);
        $this->deleteJson("/api/activities/{$activity->id}")->assertStatus(403);
        $this->postJson('/api/activities', [
            'project_id' => $project->id,
            'name' => 'New activity',
            'expected_deliverable' => 'Something',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-05',
            'responsible_person_id' => User::factory()->create()->id,
        ])->assertStatus(403);
    }

    #[Test]
    public function it_lets_an_administrator_manage_activities_on_any_project()
    {
        $this->authenticateAsAdministrator();
        $otherPlanner = User::factory()->create();
        $project = Project::create(['name' => 'Test Project', 'planner_id' => $otherPlanner->id]);
        $activity = ImplementationActivity::create(['project_id' => $project->id, 'name' => 'Excavation']);

        $this->putJson("/api/activities/{$activity->id}", ['name' => 'Renamed'])->assertStatus(200);
    }

    // --- Project model helpers --------------------------------------------

    #[Test]
    public function project_model_exposes_plan_lifecycle_helpers()
    {
        $draft = Project::create(['name' => 'Draft', 'plan_status' => 'draft']);
        $pending = Project::create(['name' => 'Pending', 'plan_status' => 'pending_review']);
        $changes = Project::create(['name' => 'Changes', 'plan_status' => 'changes_requested']);
        $approved = Project::create(['name' => 'Approved', 'plan_status' => 'approved']);

        $this->assertTrue($draft->canSubmitPlan());
        $this->assertFalse($draft->isPlanLocked());

        $this->assertFalse($pending->canSubmitPlan());
        $this->assertTrue($pending->isPlanLocked());

        $this->assertTrue($changes->canSubmitPlan());
        $this->assertFalse($changes->isPlanLocked());

        $this->assertFalse($approved->canSubmitPlan());
        $this->assertFalse($approved->isPlanLocked());
    }
}
