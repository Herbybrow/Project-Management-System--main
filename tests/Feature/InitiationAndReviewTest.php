<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\ImplementationActivity;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Role;
use App\Models\User;
use App\Support\ProjectCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InitiationAndReviewTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissionCodes, string $roleName = 'Project Reviewer'): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => $roleName]);
        $ids = [];
        foreach ($permissionCodes as $code) {
            $permission = Permission::firstOrCreate(
                ['code' => $code],
                ['name' => $code, 'module' => 'test']
            );
            $ids[] = $permission->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user->roles()->attach($role->id, [
            'is_active' => true,
            'assigned_at' => now(),
        ]);

        Sanctum::actingAs($user);

        return $user->fresh();
    }

    #[Test]
    public function reviewer_can_register_a_project_with_picklists_and_planner(): void
    {
        $reviewer = $this->userWith([
            'projects.register',
            'projects.review',
            'projects.assign_planner',
            'projects.reassign_planner',
        ]);
        $planner = User::factory()->create(['name' => 'Assigned Planner']);

        $response = $this->postJson('/api/projects', [
            'annual_plan_reference' => 'NSSF-2026-APR-001',
            'category' => 'System',
            'project_type' => 'New Implementation',
            'activity_name' => 'CFMS',
            'name' => 'Member Portal',
            'team_type' => 'Internal',
            'review_track' => 'SDMM',
            'planner_id' => $planner->id,
            'initiation_document_id' => null,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Member Portal')
            ->assertJsonPath('data.planner_id', $planner->id)
            ->assertJsonPath('data.reviewer_id', $reviewer->id)
            ->assertJsonPath('data.review_track', 'SDMM')
            ->assertJsonPath('data.plan_review_status', 'draft');
    }

    #[Test]
    public function registration_rejects_free_text_category(): void
    {
        $this->userWith(['projects.register']);

        $this->postJson('/api/projects', [
            'annual_plan_reference' => 'X-1',
            'category' => 'Whatever',
            'project_type' => 'New Implementation',
            'activity_name' => ProjectCatalog::ACTIVITIES[0],
            'name' => 'Bad Category',
            'review_track' => 'SDMM',
            'planner_id' => User::factory()->create()->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['category']);
    }

    #[Test]
    public function reviewer_can_reassign_planner_and_review_a_submitted_plan(): void
    {
        $reviewer = $this->userWith([
            'projects.register',
            'projects.review',
            'projects.assign_planner',
            'projects.reassign_planner',
        ]);
        $planner = User::factory()->create();
        $otherPlanner = User::factory()->create();

        $project = Project::create([
            'name' => 'Reassign Me',
            'category' => 'System',
            'review_track' => 'IDMM',
            'planner_id' => $planner->id,
            'reviewer_id' => $reviewer->id,
            'plan_review_status' => 'draft',
        ]);

        $this->putJson("/api/projects/{$project->id}/reassign", [
            'planner_id' => $otherPlanner->id,
        ])->assertOk()
            ->assertJsonPath('data.planner_id', $otherPlanner->id);

        ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Kickoff',
            'expected_deliverable' => 'Minutes',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-10',
            'responsible_person_id' => $planner->id,
        ]);

        Sanctum::actingAs($otherPlanner);
        $this->postJson("/api/projects/{$project->id}/plan/submit")->assertOk();

        Sanctum::actingAs($reviewer);
        $this->postJson("/api/projects/{$project->id}/plan/review", [
            'decision' => 'returned',
        ])->assertStatus(422);

        $this->postJson("/api/projects/{$project->id}/plan/review", [
            'decision' => 'returned',
            'comment' => 'Add missing UAT activity',
        ])->assertOk()
            ->assertJsonPath('data.plan_review_status', 'changes_requested');

        Sanctum::actingAs($otherPlanner);
        $this->postJson("/api/projects/{$project->id}/plan/submit")->assertOk();
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/projects/{$project->id}/plan/review", [
            'decision' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.plan_review_status', 'approved');
    }

    #[Test]
    public function coordinator_recommends_sdmm_and_approver_signs_off_dict(): void
    {
        $coordinator = $this->userWith(['projects.recommend'], 'Project Coordinator');
        $sdmm = $this->readyProject('SDMM');

        $this->postJson("/api/projects/{$sdmm->id}/recommend", [
            'comment' => 'SDMM recommended',
        ])->assertOk()
            ->assertJsonPath('data.phase', 'Execution');

        $dict = $this->readyProject('DICT');
        $this->postJson("/api/projects/{$dict->id}/recommend")->assertStatus(422);

        $approver = $this->userWith(['projects.approve'], 'Project Approver');
        $this->postJson("/api/projects/{$dict->id}/approve-execution", [
            'comment' => 'DICT sign-off',
        ])->assertOk()
            ->assertJsonPath('data.phase', 'Execution');

        $this->assertNotNull($dict->fresh()->execution_approved_at);
        $this->assertSame($approver->id, $dict->fresh()->reviews()->where('entity_type', 'execution')->first()?->reviewer_id);
        $this->assertSame($coordinator->id, $sdmm->fresh()->reviews()->where('entity_type', 'recommendation')->first()?->reviewer_id);
    }

    #[Test]
    public function close_is_blocked_until_person3_and_person4_helpers_pass(): void
    {
        $this->userWith(['projects.close', 'projects.review']);
        $owner = User::factory()->create();
        $project = Project::create([
            'name' => 'Close Me',
            'plan_review_status' => 'approved',
            'planner_id' => $owner->id,
        ]);

        $this->postJson("/api/projects/{$project->id}/close")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['close']);

        $this->getJson("/api/projects/{$project->id}/closure-readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', false);

        ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Done',
            'expected_deliverable' => 'Release',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-01-02',
            'responsible_person_id' => $owner->id,
            'status' => 'completed',
            'actual_end_date' => '2026-01-02',
        ]);
        Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'REQ-1',
            'description' => 'Login',
            'implementation_status' => 'Completed',
            'test_result' => 'Pass',
        ]);
        Document::create([
            'project_id' => $project->id,
            'file_name' => 'closure.pdf',
            'file_url' => 'documents/closure.pdf',
            'is_current' => true,
            'review_status' => 'approved',
            'uploaded_by' => $owner->id,
            'uploaded_at' => now(),
        ]);

        $this->getJson("/api/projects/{$project->id}/closure-readiness")
            ->assertOk()
            ->assertJsonPath('data.ready', true)
            ->assertJsonPath('data.helpers.hasCompletedAllActivities', true)
            ->assertJsonPath('data.helpers.hasPassedAllUAT', true)
            ->assertJsonPath('data.helpers.hasAllClosureDocsReviewed', true);

        // The current workflow requires the planner's closure request.
        $project->update(['closure_requested_at' => now(), 'closure_requested_by' => $owner->id]);

        $this->postJson("/api/projects/{$project->id}/close", [
            'comment' => 'Reviewer sign-off',
        ])->assertOk()
            ->assertJsonPath('data.status', 'Closed');
    }

    private function readyProject(string $track): Project
    {
        $user = User::factory()->create();
        $project = Project::create([
            'name' => $track.' Project',
            'category' => 'System',
            'review_track' => $track,
            'plan_review_status' => 'approved',
            'planner_id' => $user->id,
        ]);
        $activity = ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => 'Build',
            'expected_deliverable' => 'Module',
            'planned_start_date' => '2026-01-01',
            'planned_end_date' => '2026-02-01',
            'responsible_person_id' => $user->id,
        ]);
        foreach (['Implementation Plan', 'SRS'] as $type) {
            Document::create([
                'project_id' => $project->id,
                'activity_id' => $activity->id,
                'document_type' => $type,
                'file_name' => $type.'.pdf',
                'file_url' => 'documents/'.$type.'.pdf',
                'is_current' => true,
                'review_status' => 'pending',
                'uploaded_by' => $user->id,
                'uploaded_at' => now(),
            ]);
        }

        return $project;
    }
}
