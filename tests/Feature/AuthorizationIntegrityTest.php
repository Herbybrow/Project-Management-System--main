<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthorizationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_planner_cannot_select_an_administrator_dashboard(): void
    {
        $user = User::factory()->create();
        $role = Role::create(['name' => 'Project Planner']);
        $user->roles()->attach($role->id, ['is_active' => true]);
        Sanctum::actingAs($user);
        $this->getJson('/api/dashboard?role=Project Administrator')->assertForbidden();
    }

    public function test_unassigned_user_cannot_change_requirements_or_sign_off_a_review(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $project = Project::create(['name' => 'Owned', 'planner_id' => User::factory()->create()->id]);
        $requirement = Requirement::create(['project_id' => $project->id, 'requirement_code' => 'R1']);
        $this->patchJson("/api/requirements/{$requirement->id}/status", ['implementation_status' => 'Completed'])->assertForbidden();
        $this->patchJson("/api/requirements/{$requirement->id}/review", ['review_decision' => 'approved'])->assertForbidden();
        $this->assertNull($requirement->fresh()->review_decision);
    }

    public function test_document_upload_and_authenticated_download_keep_a_valid_uploader(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::create(['name' => 'Owned', 'planner_id' => $user->id]);
        $documentId = $this->postJson('/api/documents', [
            'project_id' => $project->id,
            'document_type' => 'SRS',
            'file' => UploadedFile::fake()->create('SRS.pdf', 10, 'application/pdf'),
        ])->assertCreated()->assertJsonPath('data.uploaded_by', $user->id)->json('data.id');
        $document = Document::findOrFail($documentId);
        Storage::disk('local')->assertExists($document->file_url);
        $this->get("/api/documents/{$documentId}/file")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/documents/{$documentId}/file")->assertUnauthorized();
    }

    public function test_document_cannot_reference_another_projects_requirement(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $project = Project::create(['name' => 'Owned', 'planner_id' => $user->id]);
        $other = Project::create(['name' => 'Other']);
        $requirement = Requirement::create(['project_id' => $other->id, 'requirement_code' => 'Other-R1']);
        $this->postJson('/api/documents', [
            'project_id' => $project->id,
            'requirement_id' => $requirement->id,
            'file' => UploadedFile::fake()->create('SRS.pdf', 10, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('requirement_id');
        $this->assertSame(0, Document::count());
    }
}
