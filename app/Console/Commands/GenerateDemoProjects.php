<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\ImplementationActivity;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;
use App\Services\ProjectWorkflowService;
use App\Support\ProjectCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Database\Seeders\DemoUsers;

/**
 * Scale/workflow-fidelity check: drives N projects through the real
 * registration -> initiation -> planning -> execution -> closure workflow
 * using the same model methods and ProjectWorkflowService calls the
 * controllers use (not hand-set "looks right" fields), so a bug in any real
 * transition would surface here too. Spreads projects across every stage of
 * the lifecycle instead of pushing all of them to Closed, so every queue and
 * dashboard has real data to show.
 *
 * Everything it creates uses the 'NSSF-DEMO-APR-' annual plan reference
 * prefix, so it's trivially identifiable and removable later:
 *   Project::where('annual_plan_reference', 'like', 'NSSF-DEMO-APR-%')->delete();
 */
class GenerateDemoProjects extends Command
{
    protected $signature = 'demo:generate-projects {count=200}';

    protected $description = 'Generate N demo projects driven through the full workflow, for scale/load testing.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Demo generation is restricted to local or testing environments.');

            return self::FAILURE;
        }
        $count = max(1, (int) $this->argument('count'));

        $reviewer = DemoUsers::resolve('Project Reviewer', 'reviewer@example.test');
        $planner = DemoUsers::resolve('Project Planner', 'planner@example.test');
        $coordinator = DemoUsers::resolve('Project Coordinator', 'coordinator@example.test');
        $approver = DemoUsers::resolve('Project Approver', 'approver@example.test');

        if (! $reviewer || ! $planner || ! $coordinator || ! $approver) {
            $this->error('Expected reviewer@gmail.com, planner@gmail.com, coordinator@gmail.com and approver@gmail.com to already exist.');

            return self::FAILURE;
        }

        $startId = (int) Project::max('id');
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        DB::transaction(function () use ($count, $reviewer, $planner, $coordinator, $approver, $bar) {
            $categories = ProjectCatalog::CATEGORIES;
            $projectTypes = ProjectCatalog::PROJECT_TYPES;
            $teamTypes = ProjectCatalog::TEAM_TYPES;
            $suffixes = ['Rollout', 'Upgrade', 'Modernization', 'Enhancement', 'Refresh', 'Integration', 'Migration', 'Revamp'];

            for ($i = 1; $i <= $count; $i++) {
                $this->generateOne($i, $reviewer, $planner, $coordinator, $approver, $categories, $projectTypes, $teamTypes, $suffixes);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
        $this->info("Generated {$count} demo projects (ids ".($startId + 1).' through '.Project::max('id').').');

        return self::SUCCESS;
    }

    private function generateOne(
        int $i,
        User $reviewer,
        User $planner,
        User $coordinator,
        User $approver,
        array $categories,
        array $projectTypes,
        array $teamTypes,
        array $suffixes,
    ): void {
        $category = $categories[$i % count($categories)];
        $activities = ProjectCatalog::activitiesFor($category);
        $activityName = $activities[$i % count($activities)];
        // Spreads projects evenly across every stage of the lifecycle instead
        // of pushing all of them to Closed.
        $stagePlan = $i % 10;

        $project = Project::create([
            'annual_plan_reference' => 'NSSF-DEMO-APR-'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
            'category' => $category,
            'project_type' => $projectTypes[$i % count($projectTypes)],
            'activity_name' => $activityName,
            'name' => $activityName.' '.$suffixes[$i % count($suffixes)].' '.$i,
            'description' => 'Demo-generated project #'.$i.' for scale testing.',
            'budget' => 50000 + (($i * 12345) % 500000),
            'team_type' => $teamTypes[$i % count($teamTypes)],
            'reviewer_id' => $reviewer->id,
            'planner_id' => $planner->id,
            'coordinator_id' => $coordinator->id,
            'approver_id' => $approver->id,
            'status' => 'Initiated',
            'phase' => 'Registration',
            'lifecycle_stage' => 'initiation',
            'plan_review_status' => 'draft',
            'plan_status' => 'draft',
            'review_track' => ProjectWorkflowService::trackForCategory($category),
            'planned_start_date' => now()->subDays(60 - ($i % 60)),
            'planned_end_date' => now()->addDays(60 + ($i % 120)),
        ]);

        // Step 1: initiation documents — required before advancing to planning.
        $this->attachDocument($project, 'concept_note', $planner->id, phase: 'initiation');
        $this->attachDocument($project, 'ega_approval_letter', $planner->id, phase: 'initiation');

        if ($stagePlan === 0) {
            return; // stays in Initiation
        }

        // Step 2: advance to Planning (mirrors ProjectController::advanceToPlanning).
        $project->update(['lifecycle_stage' => 'planning', 'phase' => 'Planning', 'status' => 'Planning']);

        // Step 3: build the plan — activities (each with a supporting doc, a
        // real execution-blocker check) plus the project-level Implementation Plan.
        $activityCount = 3 + ($i % 4);
        $activityModels = [];
        for ($a = 1; $a <= $activityCount; $a++) {
            $plannedStart = $project->planned_start_date->copy()->addDays(($a - 1) * 10);
            $activity = ImplementationActivity::create([
                'project_id' => $project->id,
                'name' => $activityName.' — Activity '.$a,
                'phase' => 'Planning',
                'expected_deliverable' => 'Deliverable '.$a.' for '.$activityName,
                'planned_start_date' => $plannedStart,
                'planned_end_date' => $plannedStart->copy()->addDays(9),
                'responsible_person_id' => $planner->id,
                'status' => 'not_started',
            ]);
            $activityModels[] = $activity;
            $this->attachDocument($project, 'Supporting Document', $planner->id, activityId: $activity->id);
        }
        $this->attachDocument($project, 'Implementation Plan', $planner->id);

        // Step 4: RTM requirements, each with its own SRS document.
        $requirementCount = 2 + ($i % 3);
        $requirementModels = [];
        for ($r = 1; $r <= $requirementCount; $r++) {
            $requirement = Requirement::create([
                'project_id' => $project->id,
                'requirement_code' => 'REQ-DEMO-'.$i.'-'.$r,
                'description' => 'Requirement '.$r.' for '.$activityName,
                'implementation_status' => 'Pending',
            ]);
            $requirementModels[] = $requirement;
            $this->attachDocument($project, 'SRS', $planner->id, requirementId: $requirement->id);
        }

        if ($stagePlan === 1) {
            return; // plan built, not yet submitted
        }

        // Step 5: submit the plan for review (mirrors ProjectController::submitPlan).
        $project->applyPlanStatus('pending_review', [
            'phase' => 'Plan Review',
            'status' => 'Plan Submitted',
            'plan_submitted_at' => now(),
        ]);

        if ($stagePlan === 2) {
            return; // stuck awaiting reviewer decision
        }

        if ($stagePlan === 3) {
            // One in ten goes through a return-and-resubmit cycle before
            // approval, so that path gets exercised too.
            $project->applyPlanStatus('changes_requested', [
                'phase' => 'Planning',
                'status' => 'Plan Returned',
                'plan_review_comment' => 'Please revise the deliverable descriptions.',
            ]);
            $project->applyPlanStatus('pending_review', [
                'phase' => 'Plan Review',
                'status' => 'Plan Submitted',
                'plan_review_comment' => null,
                'plan_submitted_at' => now(),
            ]);
        }

        // Step 6: reviewer approves the plan — the real document
        // auto-approval cascade runs here, same as a live approval.
        $project->applyPlanStatus('approved', [
            'plan_reviewed_at' => now(),
            'phase' => 'Plan Approved',
            'status' => 'Plan Approved',
        ]);
        $project->implementationActivities()->update(['plan_change_status' => 'approved']);
        ProjectWorkflowService::autoApproveProjectLevelDocuments($project->id, $reviewer->id);
        foreach ($activityModels as $activity) {
            ProjectWorkflowService::autoApproveActivityDocuments($activity->id, $reviewer->id);
        }

        if ($stagePlan === 4) {
            return; // approved, not yet recommended/executed
        }

        // Step 7: enter Execution — SDMM/IDMM via Coordinator recommendation,
        // DICT via Approver sign-off, exactly like the real queues.
        if (ProjectWorkflowService::reviewTrack($project) === 'DICT') {
            $project = ProjectWorkflowService::approveExecution($project);
        } else {
            $project = ProjectWorkflowService::recommendAndMoveToExecution($project);
        }

        if ($stagePlan === 5) {
            return; // just entered execution
        }

        // Step 8: RTM requirements approved by the reviewer.
        foreach ($requirementModels as $requirement) {
            $requirement->update([
                'review_decision' => 'approved',
                'implementation_status' => 'Ongoing',
                'actual_start_date' => now()->subDays(10),
            ]);
            ProjectWorkflowService::autoApproveRequirementDocuments($requirement->id, $reviewer->id);
        }

        if ($stagePlan === 6) {
            return; // approved, not yet tested
        }

        // Step 9: test results recorded, activities and requirements completed.
        foreach ($requirementModels as $index => $requirement) {
            $pass = ($i + $index) % 5 !== 0; // occasional failure, for realism
            $requirement->update([
                'test_result' => $pass ? 'Pass' : 'Fail',
                'implementation_status' => 'Completed',
                'actual_end_date' => now()->subDays(2),
            ]);
        }
        foreach ($activityModels as $activity) {
            $activity->update(['actual_end_date' => now()->subDays(2), 'status' => 'completed']);
        }

        if ($stagePlan === 7) {
            return; // execution work finished, closure not requested yet
        }

        // Step 10: Planner requests closure (mirrors ProjectController::requestClosure).
        $project->update([
            'lifecycle_stage' => 'closure',
            'closure_requested_at' => now()->subDays(1),
            'closure_requested_by' => $planner->id,
        ]);

        if ($stagePlan === 8) {
            return; // awaiting reviewer sign-off
        }

        // Step 11: Reviewer signs off and closes (mirrors ProjectController::close).
        $project->update([
            'status' => 'Closed',
            'phase' => 'Closed',
            'lifecycle_stage' => 'closure',
            'closed_at' => now(),
            'closed_by' => $reviewer->id,
        ]);
    }

    /**
     * No real file is uploaded for demo data (same approach ProjectSeeder
     * already uses for its own seeded documents) — these rows exist to
     * exercise the workflow's document-gating logic, not to be opened.
     */
    private function attachDocument(
        Project $project,
        string $documentType,
        int $uploaderId,
        ?int $activityId = null,
        ?int $requirementId = null,
        ?string $phase = null,
    ): Document {
        return Document::create([
            'project_id' => $project->id,
            'activity_id' => $activityId,
            'requirement_id' => $requirementId,
            'document_type' => $documentType,
            'phase' => $phase,
            'file_name' => str()->slug($documentType).'-'.$project->id.'-'.uniqid().'.pdf',
            'file_url' => 'documents/demo/'.uniqid('', true).'.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 51200,
            'version_number' => 1,
            'is_current' => true,
            'review_status' => 'pending',
            'uploaded_by' => $uploaderId,
            'uploaded_at' => now(),
        ]);
    }
}
