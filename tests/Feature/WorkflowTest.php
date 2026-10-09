<?php

namespace Tests\Feature;

use App\Models\ImplementationActivity;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function actingUser(): User
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        return $user;
    }

    private function grantPermissions(User $user, array $codes): void
    {
        $role = Role::firstOrCreate(['name' => 'Test Role']);
        $ids = [];
        foreach ($codes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'test']
            );
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user->roles()->syncWithoutDetaching([
            $role->id => ['is_active' => true, 'assigned_at' => now()],
        ]);
    }

    #[Test]
    public function plan_changes_are_queued_after_plan_is_submitted(): void
    {
        $user = $this->actingUser();
        $project = Project::create([
            'name' => 'Portal',
            'category' => 'SDMM',
            'plan_review_status' => 'draft',
            'planner_id' => $user->id,
        ]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Kickoff',
            'expected_deliverable' => 'Minutes',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'responsible_person_id' => $user->id,
            'status' => 'not_started',
        ]);

        $this->postJson("/api/projects/{$project->id}/plan/submit")->assertOk();

        $this->putJson("/api/activities/{$activity->id}", [
            'name' => 'Kickoff (revised)',
        ])->assertForbidden();

        $this->assertSame('Kickoff', $activity->fresh()->name);
    }

    #[Test]
    public function project_summary_returns_tab_counts_without_loading_tab_collections(): void
    {
        $user = $this->actingUser();
        $project = Project::create(['name' => 'Summary project', 'planner_id' => $user->id]);
        ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Build',
            'expected_deliverable' => 'Module',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'responsible_person_id' => $user->id,
        ]);
        Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-SUMMARY',
            'description' => 'Summary response stays small',
        ]);

        $this->getJson("/api/projects/{$project->id}?summary=1")
            ->assertOk()
            ->assertJsonPath('data.implementation_activities_count', 1)
            ->assertJsonPath('data.requirements_count', 1)
            ->assertJsonPath('data.documents_count', 0)
            ->assertJsonMissingPath('data.implementation_activities')
            ->assertJsonMissingPath('data.requirements')
            ->assertJsonMissingPath('data.documents');

        $this->getJson("/api/projects/{$project->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.implementation_activities')
            ->assertJsonCount(1, 'data.requirements');
    }

    #[Test]
    public function recommendation_is_blocked_when_gates_fail_and_forwards_by_track(): void
    {
        $user = $this->actingUser();
        $this->grantPermissions($user, ['projects.recommend', 'projects.approve']);

        $project = Project::create([
            'name' => 'DICT System',
            'planner_id' => $user->id,
            'category' => 'System',
            'review_track' => 'DICT',
            'plan_review_status' => 'draft',
        ]);

        $this->postJson("/api/projects/{$project->id}/approve-execution")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['execution']);

        $project->update(['plan_review_status' => 'approved']);
        ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Build',
            'expected_deliverable' => 'Module',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-02-01',
            'responsible_person_id' => $user->id,
        ]);

        Storage::fake('local');
        foreach (['Implementation Plan', 'SRS'] as $type) {
            $this->post('/api/documents', [
                'project_id' => $project->id,
                'activity_id' => $project->implementationActivities()->first()->id,
                'document_type' => $type,
                'file' => UploadedFile::fake()->create($type.'.pdf', 100, 'application/pdf'),
            ])->assertCreated();
        }

        $this->postJson("/api/projects/{$project->id}/recommend")
            ->assertStatus(422);

        $this->postJson("/api/projects/{$project->id}/approve-execution")
            ->assertOk()
            ->assertJsonFragment(['forwarded_role' => 'Project Approver']);

        $this->assertSame('Execution', $project->fresh()->phase);
        $this->assertSame('In Execution', $project->fresh()->status);
    }

    #[Test]
    public function requirement_review_dates_and_remark_history_are_persisted(): void
    {
        $user = $this->actingUser();
        $this->grantPermissions($user, ['projects.review']);
        $project = Project::create(['name' => 'Matrix Project', 'planner_id' => $user->id]);
        $requirement = Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-1',
            'description' => 'Login',
            'implementation_status' => 'Pending',
        ]);

        $this->patchJson("/api/requirements/{$requirement->id}/review", [
            'review_decision' => 'approved',
        ])->assertOk();

        $this->patchJson("/api/requirements/{$requirement->id}/status", [
            'actual_start_date' => '2026-03-01',
            'remarks' => 'Started implementation',
        ])->assertOk()
            ->assertJsonPath('data.implementation_status', 'Ongoing');

        $this->assertSame('2026-03-01', $requirement->fresh()->actual_start_date->toDateString());

        $this->assertDatabaseHas('progress_updates', [
            'entity_type' => 'requirement',
            'entity_id' => $requirement->id,
            'remark' => 'Started implementation',
        ]);

        $this->postJson("/api/projects/{$project->id}/matrix/return", [
            'comment' => 'Need more test evidence',
        ])->assertOk();

        $this->assertDatabaseHas('requirements', [
            'id' => $requirement->id,
            'review_decision' => 'needs_revision',
        ]);
    }
}
