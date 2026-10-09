<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\Permission;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function createIctSupportUser(): User
    {
        $permission = Permission::create([
            'code' => 'admin.manage_users',
            'name' => 'Manage Users and Roles',
            'module' => 'admin',
        ]);
        $role = Role::create([
            'name' => 'ICT Support',
            'description' => 'Manages user accounts and role assignment',
        ]);
        $role->permissions()->sync([$permission->id]);

        $user = User::factory()->create(['name' => 'ICT Officer', 'email' => 'ictsupport@nssf.go.tz']);
        $user->roles()->attach($role->id, ['is_active' => true, 'assigned_at' => now()]);

        return $user->fresh();
    }

    #[Test]
    public function flagged_failures_include_earlier_attempts_outside_the_current_page(): void
    {
        $ict = $this->createIctSupportUser();
        $target = User::factory()->create();
        foreach ([10, 5, 1] as $minutesAgo) {
            AuditLog::create(['user_id' => $target->id, 'action' => 'login_failed', 'created_at' => now()->subMinutes($minutesAgo)]);
        }
        Sanctum::actingAs($ict);
        $this->getJson('/api/admin/audit-logs?action=login_failed&per_page=1')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.flagged', true);
    }

    #[Test]
    public function successful_login_is_recorded(): void
    {
        $user = User::factory()->create(['email' => 'planner@nssf.or.tz']);

        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'login_success',
        ]);
    }

    #[Test]
    public function failed_login_against_a_real_account_is_recorded(): void
    {
        $user = User::factory()->create(['email' => 'planner@nssf.or.tz']);

        $this->postJson('/api/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(422);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'login_failed',
        ]);
    }

    #[Test]
    public function failed_login_against_an_unknown_email_is_not_recorded(): void
    {
        $this->postJson('/api/login', [
            'login' => 'nobody-like-this@nssf.or.tz',
            'password' => 'whatever123',
        ])->assertStatus(422);

        $this->assertDatabaseCount('audit_logs', 0);
    }

    #[Test]
    public function logout_is_recorded(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/logout')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'logout',
        ]);
    }

    #[Test]
    public function viewing_a_document_is_not_recorded(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $project = Project::create(['name' => 'Audit Project']);
        $file = UploadedFile::fake()->create('SRS.pdf', 50, 'application/pdf');
        $path = $file->store('documents', 'local');

        $document = Document::create([
            'project_id' => $project->id,
            'document_type' => 'SRS',
            'file_name' => 'SRS.pdf',
            'file_url' => $path,
            'file_type' => 'application/pdf',
            'file_size' => $file->getSize(),
            'uploaded_by' => $user->id,
            'uploaded_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $this->get("/api/documents/{$document->id}/file")->assertOk();

        // Routine reads don't belong in the accountability trail — only
        // actions do — and skipping the write also keeps this hot path fast.
        $this->assertDatabaseMissing('audit_logs', [
            'user_id' => $user->id,
            'action' => 'document_viewed',
        ]);
    }

    #[Test]
    public function only_ict_support_or_administrator_can_read_the_audit_log(): void
    {
        AuditLog::create(['user_id' => null, 'action' => 'login_success']);

        $planner = User::factory()->create();
        Sanctum::actingAs($planner);
        $this->getJson('/api/admin/audit-logs')->assertForbidden();

        $ict = $this->createIctSupportUser();
        Sanctum::actingAs($ict);
        $this->getJson('/api/admin/audit-logs')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }

    #[Test]
    public function a_forbidden_request_is_recorded_regardless_of_which_check_rejected_it(): void
    {
        $planner = User::factory()->create();
        Sanctum::actingAs($planner);

        $this->getJson('/api/admin/audit-logs')->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $planner->id,
            'action' => 'access_denied',
        ]);
    }

    #[Test]
    public function log_can_be_filtered_by_action_search_and_date_range(): void
    {
        $ict = $this->createIctSupportUser();
        $alice = User::factory()->create(['name' => 'Alice Planner', 'email' => 'alice@nssf.or.tz']);

        AuditLog::create(['user_id' => $alice->id, 'action' => 'login_success', 'created_at' => now()->subDays(10)]);
        AuditLog::create(['user_id' => $alice->id, 'action' => 'logout', 'created_at' => now()]);
        AuditLog::create(['user_id' => $ict->id, 'action' => 'login_success', 'created_at' => now()]);

        Sanctum::actingAs($ict);

        $this->getJson('/api/admin/audit-logs?action=logout')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.action', 'logout');

        $this->getJson('/api/admin/audit-logs?search=alice')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/admin/audit-logs?date_from='.now()->subDays(1)->toDateString())
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    #[Test]
    public function account_management_actions_are_mirrored_onto_the_security_audit_trail(): void
    {
        $ict = $this->createIctSupportUser();
        $planner = User::factory()->create(['email' => 'target@nssf.or.tz']);
        $plannerRole = Role::create(['name' => 'Project Planner']);
        Sanctum::actingAs($ict);

        $this->postJson('/api/admin/users', [
            'name' => 'New Hire',
            'email' => 'new.hire@nssf.go.tz',
            'password' => 'password123',
            'role_ids' => [$plannerRole->id],
        ])->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $ict->id, 'action' => 'account_created']);

        $this->putJson("/api/admin/users/{$planner->id}/roles", ['role_ids' => [$plannerRole->id]])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $ict->id, 'action' => 'role_assigned']);

        $this->postJson("/api/admin/users/{$planner->id}/password", [
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $ict->id, 'action' => 'password_reset']);

        $this->postJson("/api/admin/users/{$planner->id}/toggle-status")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['user_id' => $ict->id, 'action' => 'account_disabled']);
    }
}
