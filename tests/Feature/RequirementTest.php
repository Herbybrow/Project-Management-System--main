<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;

class RequirementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create());
    }

    #[Test]
    public function it_can_successfully_update_requirement_status_and_remarks()
    {
        $project = Project::create([
            'name' => 'Test Project', 'planner_id' => auth()->id()
        ]);

        $requirement = Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'SRS-001',
            'description' => 'Test description'
        ]);

        $response = $this->patchJson("/api/requirements/{$requirement->id}/status", [
            'implementation_status' => 'Ongoing',
            'test_result' => 'Pass',
            'remarks' => 'Initial phase verified'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Requirement status updated successfully',
                'overall_implementation_score' => '50%',
                'data' => [
                    'id' => $requirement->id,
                    'implementation_status' => 'Ongoing',
                    'test_result' => 'Pass',
                    'remarks' => 'Initial phase verified',
                ]
            ]);

        $this->assertDatabaseHas('requirements', [
            'id' => $requirement->id,
            'implementation_status' => 'Ongoing',
            'test_result' => 'Pass',
            'remarks' => 'Initial phase verified',
        ]);

        $this->assertDatabaseHas('progress_updates', [
            'entity_type' => 'requirement',
            'entity_id' => $requirement->id,
            'remark' => 'Initial phase verified',
        ]);
    }

    #[Test]
    public function it_fails_validation_when_invalid_status_is_provided()
    {
        $project = Project::create([
            'name' => 'Test Project', 'planner_id' => auth()->id()
        ]);

        $requirement = Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'SRS-001',
            'description' => 'Test description'
        ]);

        $response = $this->patchJson("/api/requirements/{$requirement->id}/status", [
            'implementation_status' => 'InvalidStatus'
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['implementation_status']);
    }

    #[Test]
    public function it_can_fetch_project_progress_and_metrics()
    {
        $project = Project::create(['name' => 'Test Project', 'planner_id' => auth()->id()]);

        Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'SRS-001',
            'description' => 'First task',
            'implementation_status' => 'Ongoing'
        ]);

        Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => 'SRS-002',
            'description' => 'Second task',
            'implementation_status' => 'Pending'
        ]);

        $response = $this->getJson("/api/projects/{$project->id}/progress");

        $response->assertStatus(200)
            ->assertJson([
                'project_id' => $project->id,
                'overall_progress' => '25%',
                'metrics' => [
                    'total_requirements' => 2,
                    'completed' => 0,
                    'ongoing' => 1,
                    'pending' => 1,
                ]
            ]);
    }
}
