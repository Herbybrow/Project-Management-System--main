<?php

namespace Database\Seeders;

use App\Models\Document;
use App\Models\ImplementationActivity;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Review;
use App\Models\User;
use App\Support\ProjectCatalog;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Sample data for the Administrator dashboard at "/". Covers every axis it
 * reports on: lifecycle_stage, the actual_start/end-derived status, a
 * transition blocker of each kind, an overdue activity at each breakdown
 * bucket, and a non-zero count for every "awaiting action" card.
 */
class ProjectSeeder extends Seeder
{
    public function run(): void
    {
        DemoDataGuard::check();
        DB::transaction(fn () => $this->seedProjects());
    }

    private function seedProjects(): void
    {
        $references = array_map(
            fn ($number) => 'NSSF-2026-APR-'.$number,
            array_merge(range(101, 110), range(201, 238))
        );
        $existing = Project::whereIn('annual_plan_reference', $references)->pluck('annual_plan_reference');
        if ($existing->isNotEmpty()) {
            if ($existing->count() === 48 && $existing->unique()->count() === 48) {
                $invalid = Project::whereIn('annual_plan_reference', $references)
                    ->where(function ($query) {
                        $query->whereDoesntHave('reviewer.activeRoles', fn ($role) => $role->where('roles.name', 'Project Reviewer'))
                            ->orWhereDoesntHave('planner.activeRoles', fn ($role) => $role->where('roles.name', 'Project Planner'));
                    })->exists();
                if ($invalid) {
                    throw new \RuntimeException('Existing demo projects have missing or incorrect reviewer/planner roles. Review their assignments manually; no project was changed.');
                }
                return;
            }

            throw new \RuntimeException(
                'Incomplete or duplicate demo project set detected. No data was changed. Use a new disposable database for DemoSeeder; review existing projects manually.'
            );
        }

        $reviewer = DemoUsers::resolve('Project Reviewer', 'reviewer@example.test');
        $planner = DemoUsers::resolve('Project Planner', 'planner@example.test');
        $coordinator = DemoUsers::resolve('Project Coordinator', 'coordinator@example.test');
        $approver = DemoUsers::resolve('Project Approver', 'approver@example.test');

        // 1) Execution, ongoing, overdue activity at 1 day, a pending document.
        $p1 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-101',
            'category' => 'System',
            'project_type' => 'New Implementation',
            'activity_name' => 'CFMS',
            'name' => 'Member Portal Modernization',
            'budget' => 450000,
            'team_type' => 'Internal',
            'review_track' => 'SDMM',
            'phase' => 'Execution',
            'lifecycle_stage' => 'execution',
            'status' => 'In Execution',
            'plan_review_status' => 'approved',
            'plan_status' => 'approved',
            'plan_reviewed_at' => now()->subDays(30),
            'execution_started_at' => now()->subDays(20),
            'actual_start_date' => now()->subDays(20),
            'overall_implementation_score' => 72,
        ], $reviewer, $planner, $coordinator, $approver);
        $this->activity($p1, 'API Gateway Rollout', $planner, now()->subDays(1), null);
        $this->activity($p1, 'Portal UAT Sign-off', $planner, now()->addDays(5), null);
        $this->requirement($p1, 'REQ-101-1', 'Completed', 'Pass');
        $this->requirement($p1, 'REQ-101-2', 'Completed', 'Pass');
        $this->requirement($p1, 'REQ-101-3', 'Completed', 'Fail');
        $this->document($p1, 'SRS', 'pending', null);

        // 2) Execution, ongoing, overdue activity at 3 days, matrix item pending approval.
        $p2 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-102',
            'category' => 'System',
            'project_type' => 'Review/Enhancement',
            'activity_name' => 'HRMS',
            'name' => 'HRMS Self-Service Upgrade',
            'budget' => 220000,
            'team_type' => 'Vendor',
            'review_track' => 'SDMM',
            'phase' => 'Execution',
            'lifecycle_stage' => 'execution',
            'status' => 'In Execution',
            'plan_review_status' => 'approved',
            'plan_status' => 'approved',
            'plan_reviewed_at' => now()->subDays(25),
            'execution_started_at' => now()->subDays(15),
            'actual_start_date' => now()->subDays(15),
            'overall_implementation_score' => 45,
        ], $reviewer, $planner, $coordinator, $approver);
        $this->activity($p2, 'Leave Module Cutover', $planner, now()->subDays(3), null);
        $this->requirement($p2, 'REQ-102-1', 'Completed', 'Pass');
        $this->requirement($p2, 'REQ-102-2', 'Pending', null);

        // 3) Execution, ongoing, overdue activity at 6 days (over_3_days bucket).
        $p3 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-103',
            'category' => 'System',
            'project_type' => 'New Implementation',
            'activity_name' => 'IPEMS',
            'name' => 'IPEMS Data Migration',
            'budget' => 310000,
            'team_type' => 'Internal',
            'review_track' => 'IDMM',
            'phase' => 'Execution',
            'lifecycle_stage' => 'execution',
            'status' => 'In Execution',
            'plan_review_status' => 'approved',
            'plan_status' => 'approved',
            'plan_reviewed_at' => now()->subDays(40),
            'execution_started_at' => now()->subDays(30),
            'actual_start_date' => now()->subDays(30),
            'overall_implementation_score' => 88,
        ], $reviewer, $planner, $coordinator, $approver);
        $this->activity($p3, 'Legacy Records Import', $planner, now()->subDays(6), null);
        $this->requirement($p3, 'REQ-103-1', 'Completed', 'Pass');
        $this->requirement($p3, 'REQ-103-2', 'Completed', 'Pass');

        // 4) Execution, ongoing, stuck: UAT scores missing (execution started, no test results).
        $p4 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-104',
            'category' => 'System',
            'project_type' => 'New Implementation',
            'activity_name' => 'ERP',
            'name' => 'ERP Vendor Integration',
            'budget' => 500000,
            'team_type' => 'Vendor',
            'review_track' => 'DICT',
            'phase' => 'Execution',
            'lifecycle_stage' => 'execution',
            'status' => 'In Execution',
            'plan_review_status' => 'approved',
            'plan_status' => 'approved',
            'plan_reviewed_at' => now()->subDays(18),
            'execution_started_at' => now()->subDays(10),
            'actual_start_date' => now()->subDays(10),
            'overall_implementation_score' => 60,
        ], $reviewer, $planner, $coordinator, $approver);
        $this->activity($p4, 'Vendor API Certification', $planner, now()->addDays(3), null);
        $this->requirement($p4, 'REQ-104-1', 'Pending', null);
        $this->requirement($p4, 'REQ-104-2', 'Pending', null);

        // 5) Closure, completed, every closure gate passed but not yet closed.
        $p5 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-105',
            'category' => 'System',
            'project_type' => 'Review/Enhancement',
            'activity_name' => 'CFMS',
            'name' => 'CFMS Reporting Enhancement',
            'budget' => 150000,
            'team_type' => 'Internal',
            'review_track' => 'SDMM',
            'phase' => 'Execution',
            'lifecycle_stage' => 'closure',
            'status' => 'In Execution',
            'plan_review_status' => 'approved',
            'plan_status' => 'approved',
            'plan_reviewed_at' => now()->subDays(60),
            'execution_started_at' => now()->subDays(50),
            'actual_start_date' => now()->subDays(50),
            'actual_end_date' => now()->subDays(2),
            'overall_implementation_score' => 95,
        ], $reviewer, $planner, $coordinator, $approver);
        $this->activity($p5, 'Report Templates Delivered', $planner, now()->subDays(45), now()->subDays(5), 'completed');
        $this->requirement($p5, 'REQ-105-1', 'Completed', 'Pass');
        $this->requirement($p5, 'REQ-105-2', 'Completed', 'Pass');
        $this->document($p5, 'Implementation Plan', 'approved', now()->subDays(10));

        // 6) Closure, completed, already closed — shouldn't appear in the closure-signoffs card.
        $p6 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-106',
            'category' => 'System',
            'project_type' => 'New Implementation',
            'activity_name' => 'IPEMS',
            'name' => 'Go-Live Support — IPEMS Phase 2',
            'budget' => 90000,
            'team_type' => 'Internal',
            'review_track' => 'IDMM',
            'phase' => 'Closed',
            'lifecycle_stage' => 'closure',
            'status' => 'Closed',
            'plan_review_status' => 'approved',
            'plan_status' => 'approved',
            'plan_reviewed_at' => now()->subDays(90),
            'execution_started_at' => now()->subDays(80),
            'actual_start_date' => now()->subDays(80),
            'actual_end_date' => now()->subDays(20),
            'overall_implementation_score' => 100,
            'closed_at' => now()->subDays(15),
            'closed_by' => $reviewer?->id,
        ], $reviewer, $planner, $coordinator, $approver);
        $this->requirement($p6, 'REQ-106-1', 'Completed', 'Pass');

        // 7) Planning, not started, stuck: plan submitted 6 days ago, still pending review.
        $p7 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-107',
            'category' => 'Infrastructure',
            'project_type' => 'New Implementation',
            'activity_name' => 'Data Center',
            'name' => 'Data Center Refresh',
            'budget' => 600000,
            'team_type' => 'Vendor',
            'review_track' => 'DICT',
            'phase' => 'Plan Review',
            'lifecycle_stage' => 'planning',
            'status' => 'Plan Submitted',
            'plan_review_status' => 'pending_review',
            'plan_status' => 'pending_review',
            'plan_submitted_at' => now()->subDays(6),
        ], $reviewer, $planner, $coordinator, $approver);

        // 8) Planning, not started, stuck: a returned document unresolved for 4 days.
        $p8 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-108',
            'category' => 'Security',
            'project_type' => 'New Implementation',
            'activity_name' => 'Vulnerability',
            'name' => 'Vulnerability Assessment Q3',
            'budget' => 80000,
            'team_type' => 'Internal',
            'review_track' => 'SDMM',
            'phase' => 'Planning',
            'lifecycle_stage' => 'planning',
            'status' => 'Plan Returned',
            'plan_review_status' => 'approved',
            'plan_status' => 'approved',
            'plan_reviewed_at' => now()->subDays(12),
        ], $reviewer, $planner, $coordinator, $approver);
        $this->document($p8, 'Implementation Plan', 'returned', now()->subDays(4));

        // 9) Initiation, not started, stuck: registered 9 days ago, no initiation documents.
        $p9 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-109',
            'category' => 'Infrastructure',
            'project_type' => 'New Implementation',
            'activity_name' => 'Working Tools',
            'name' => 'Network Working Tools Rollout',
            'budget' => 95000,
            'team_type' => 'Internal',
            'review_track' => 'IDMM',
            'phase' => 'Registration',
            'lifecycle_stage' => 'initiation',
            'status' => 'Initiated',
            'plan_review_status' => 'draft',
            'plan_status' => 'draft',
        ], $reviewer, $planner, null, null);
        $p9->forceFill(['created_at' => now()->subDays(9)])->save();

        // 10) Initiation, not started, both initiation documents attached — no blocker.
        $p10 = $this->makeProject([
            'annual_plan_reference' => 'NSSF-2026-APR-110',
            'category' => 'Security',
            'project_type' => 'New Implementation',
            'activity_name' => 'Activities Control',
            'name' => 'Security Assessment — Core Banking',
            'budget' => 120000,
            'team_type' => 'Mixed',
            'review_track' => 'DICT',
            'phase' => 'Registration',
            'lifecycle_stage' => 'initiation',
            'status' => 'Initiated',
            'plan_review_status' => 'draft',
            'plan_status' => 'draft',
        ], $reviewer, $planner, null, null);
        $this->document($p10, 'concept_note', null, null, 'initiation');
        $this->document($p10, 'ega_approval_letter', null, null, 'initiation');

        $this->reviewLoadHistory($reviewer, [$p1, $p2, $p3, $p4, $p5, $p6, $p7, $p8, $p9, $p10]);

        // Bulk out to a departmental-scale project count (~48 total) for the
        // ViewOnly dashboard's portfolio-wide panels and progress table.
        $this->bulkProjects($reviewer, $planner, $coordinator, $approver);
    }

    /**
     * Generates volume: plain, deterministic (no randomness, so the seed is
     * reproducible) projects cycling through category/phase/lifecycle_stage
     * combinations, each with a couple of requirements so the requirement
     * status breakdown has real variety at scale.
     */
    private function bulkProjects(?User $reviewer, ?User $planner, ?User $coordinator, ?User $approver): void
    {
        $categories = ProjectCatalog::CATEGORIES;
        $projectTypes = ProjectCatalog::PROJECT_TYPES;
        $reviewTracks = ProjectCatalog::REVIEW_TRACKS;
        $teamTypes = ProjectCatalog::TEAM_TYPES;
        $stages = ['initiation', 'planning', 'execution', 'closure'];
        $phaseByStage = ['initiation' => 'Registration', 'planning' => 'Planning', 'execution' => 'Execution', 'closure' => 'Execution'];
        $nameSuffixes = ['Rollout', 'Upgrade', 'Modernization', 'Enhancement', 'Refresh', 'Integration'];
        $requirementStatuses = ['Pending', 'Ongoing', 'Completed'];

        for ($i = 1; $i <= 38; $i++) {
            $category = $categories[$i % count($categories)];
            $stage = $stages[$i % count($stages)];
            $activities = ProjectCatalog::activitiesFor($category);
            $activityName = $activities[$i % count($activities)];

            $hasStarted = in_array($stage, ['execution', 'closure'], true);
            $hasEnded = $stage === 'closure' && $i % 2 === 0;
            $planStatus = $hasStarted ? 'approved' : 'draft';

            $project = $this->makeProject([
                'annual_plan_reference' => 'NSSF-2026-APR-'.(200 + $i),
                'category' => $category,
                'project_type' => $projectTypes[$i % count($projectTypes)],
                'activity_name' => $activityName,
                'name' => $activityName.' '.$nameSuffixes[$i % count($nameSuffixes)].' '.$i,
                'budget' => 50000 + (($i * 15000) % 400000),
                'team_type' => $teamTypes[$i % count($teamTypes)],
                'review_track' => $reviewTracks[$i % count($reviewTracks)],
                'phase' => $phaseByStage[$stage],
                'lifecycle_stage' => $stage,
                'status' => $hasEnded ? 'Closed' : ($hasStarted ? 'In Execution' : 'Initiated'),
                'plan_review_status' => $planStatus,
                'plan_status' => $planStatus,
                'actual_start_date' => $hasStarted ? now()->subDays(10 + $i) : null,
                'actual_end_date' => $hasEnded ? now()->subDays($i) : null,
                'overall_implementation_score' => $hasStarted ? min(100, 20 + (($i * 7) % 80)) : null,
            ], $reviewer, $planner, $coordinator, $approver);

            $requirementCount = 1 + ($i % 3);
            for ($r = 1; $r <= $requirementCount; $r++) {
                $status = $requirementStatuses[($i + $r) % 3];
                $testResult = $status === 'Completed' ? (($i + $r) % 4 === 0 ? 'Fail' : 'Pass') : null;
                $this->requirement($project, 'REQ-'.(200 + $i).'-'.$r, $status, $testResult);
            }
        }
    }

    /**
     * Review-table activity for the Reviewer dashboard's "Review load
     * overview" chart and "Turnaround performance" panel. Each cycle is a
     * 'submitted' row paired with a later decision row for the same
     * project + entity_type, so Project::reviewerDashboard() can match
     * them back up to compute turnaround days. Only months up to the
     * current one are seeded — a departmental dashboard has no data from
     * the future.
     */
    private function reviewLoadHistory(?User $reviewer, array $projects): void
    {
        if (! $reviewer) {
            return;
        }

        $year = now()->year;
        $currentMonth = now()->month;
        $submittedPerMonth = [1 => 4, 2 => 5, 3 => 3, 4 => 6, 5 => 4, 6 => 7, 7 => 5, 8 => 3, 9 => 5, 10 => 4, 11 => 6, 12 => 4];
        $completionLagDays = [3, 5, 7, 4, 6, 9, 2, 8];
        $decisionCycle = ['approved', 'approved', 'approved', 'approved', 'approved', 'returned', 'returned', 'rejected', 'needs_revision'];

        $projectIndex = 0;
        $decisionIndex = 0;

        foreach ($submittedPerMonth as $month => $count) {
            if ($month > $currentMonth) {
                break;
            }

            for ($i = 0; $i < $count; $i++) {
                $day = min(27, 2 + $i * 4);
                $submittedAt = Carbon::create($year, $month, $day, 10, 0);
                if ($submittedAt->isFuture()) {
                    continue;
                }

                $project = $projects[$projectIndex % count($projects)];
                $projectIndex++;

                Review::create([
                    'project_id' => $project->id,
                    'entity_type' => 'plan',
                    'entity_id' => $project->id,
                    'reviewer_id' => $reviewer->id,
                    'role_snapshot' => 'Project Reviewer',
                    'decision' => 'submitted',
                    'reviewed_at' => $submittedAt,
                ]);

                $lag = $completionLagDays[$decisionIndex % count($completionLagDays)];
                $completedAt = $submittedAt->copy()->addDays($lag);
                $decisionIndex++;
                if ($completedAt->isFuture()) {
                    // Still awaiting a decision — contributes to backlog, not turnaround.
                    continue;
                }

                $decision = $decisionCycle[$decisionIndex % count($decisionCycle)];
                Review::create([
                    'project_id' => $project->id,
                    'entity_type' => 'plan',
                    'entity_id' => $project->id,
                    'reviewer_id' => $reviewer->id,
                    'role_snapshot' => 'Project Reviewer',
                    'decision' => $decision,
                    'comment' => $decision !== 'approved' ? 'Needs changes before resubmission.' : null,
                    'reviewed_at' => $completedAt,
                ]);
            }
        }
    }

    private function makeProject(array $attributes, ?User $reviewer, ?User $planner, ?User $coordinator, ?User $approver): Project
    {
        return Project::create(array_merge([
            'reviewer_id' => $reviewer?->id,
            'planner_id' => $planner?->id,
            'coordinator_id' => $coordinator?->id,
            'approver_id' => $approver?->id,
        ], $attributes));
    }

    private function activity(
        Project $project,
        string $name,
        ?User $responsible,
        $plannedStart,
        $actualEnd,
        ?string $status = null,
    ): ImplementationActivity {
        return ImplementationActivity::create([
            'project_id' => $project->id,
            'name' => $name,
            'expected_deliverable' => $name.' deliverable',
            'planned_start_date' => $plannedStart,
            'planned_end_date' => $plannedStart?->copy()->addDays(14),
            'actual_start_date' => $actualEnd ? $plannedStart : null,
            'actual_end_date' => $actualEnd,
            'responsible_person_id' => $responsible?->id,
            'status' => $status ?? ($actualEnd ? 'completed' : 'not_started'),
        ]);
    }

    private function requirement(Project $project, string $code, string $implementationStatus, ?string $testResult): Requirement
    {
        return Requirement::create([
            'project_id' => $project->id,
            'requirement_code' => $code,
            'description' => $code.' description',
            'implementation_status' => $implementationStatus,
            'test_result' => $testResult,
            'review_decision' => $implementationStatus === 'Completed' && $testResult ? 'approved' : null,
        ]);
    }

    private function document(
        Project $project,
        string $documentType,
        ?string $reviewStatus,
        $reviewedAt,
        ?string $phase = null,
    ): Document {
        $uploader = $project->planner_id ?? $project->reviewer_id;

        if ($uploader === null) {
            throw new \RuntimeException(
                'Cannot seed document for project '.$project->id.': no uploader user is assigned.'
            );
        }

        return Document::create([
            'project_id' => $project->id,
            'document_type' => $documentType,
            'phase' => $phase,
            'file_name' => str()->slug($documentType).'.pdf',
            'file_url' => 'documents/seed/'.str()->slug($project->name.'-'.$documentType).'.pdf',
            'file_type' => 'application/pdf',
            'file_size' => 102400,
            'version_number' => 1,
            'is_current' => true,
            'review_status' => $reviewStatus,
            'uploaded_by' => $uploader,
            'uploaded_at' => $reviewedAt ?? now(),
            'reviewed_at' => $reviewedAt,
        ]);
    }
}
