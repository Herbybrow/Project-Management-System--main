<?php

namespace App\Services;

use App\Models\Document;
use App\Models\ImplementationActivity;
use App\Models\Project;
use App\Models\Review;
use App\Models\Role;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ProjectWorkflowService
{
    public const REQUIRED_DOCUMENT_TYPES = [
        'Implementation Plan',
    ];

    /**
     * No document ever gets a manual approve/return click of its own — its
     * approval is entirely inherited from whatever it's attached to. A
     * document with neither an activity_id nor a requirement_id (the
     * Implementation Plan itself, or a project-level Survey Report/Cost
     * Estimate/Other upload) has nothing to attach to but the plan review
     * itself, so approving the plan approves all of these at once.
     */
    public static function autoApproveProjectLevelDocuments(int $projectId, int $reviewerId): void
    {
        $documents = Document::where('project_id', $projectId)
            ->whereNull('activity_id')
            ->whereNull('requirement_id')
            ->where('is_current', true)
            ->where('review_status', '!=', 'approved')
            ->get();

        self::approveDocuments($documents, $reviewerId, "Auto-approved with the implementation plan's approval.");
    }

    /**
     * Approving an activity is the reviewer's sign-off on everything attached
     * to it too — a supporting document doesn't need its own separate review
     * once the activity carrying it has already been approved.
     */
    public static function autoApproveActivityDocuments(int $activityId, int $reviewerId): void
    {
        $documents = Document::where('activity_id', $activityId)
            ->where('is_current', true)
            ->where('review_status', '!=', 'approved')
            ->get();

        self::approveDocuments($documents, $reviewerId, 'Auto-approved with the activity\'s approval.');
    }

    /**
     * Same as above, for a requirement's own documents (its SRS extract,
     * etc.) — approving the requirement in the RTM is the sign-off on
     * everything attached to that requirement specifically, not on every
     * SRS document in the project.
     */
    public static function autoApproveRequirementDocuments(int $requirementId, int $reviewerId): void
    {
        $documents = Document::where('requirement_id', $requirementId)
            ->where('is_current', true)
            ->where('review_status', '!=', 'approved')
            ->get();

        self::approveDocuments($documents, $reviewerId, "Auto-approved with the requirement's approval.");
    }

    private static function approveDocuments($documents, int $reviewerId, string $comment): void
    {
        foreach ($documents as $document) {
            $document->update([
                'review_status' => 'approved',
                'review_comment' => null,
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
            ]);

            Review::create([
                'project_id' => $document->project_id,
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'reviewer_id' => $reviewerId,
                'decision' => 'approved',
                'comment' => $comment,
                'reviewed_at' => now(),
            ]);
        }
    }

    /**
     * Real work is understood to begin the moment the project enters
     * Execution — approved activities that haven't recorded a start yet get
     * one now, rather than requiring a separate manual "Start" click. Kept
     * on/after each activity's own planned start so it never records work
     * starting earlier than it was ever scheduled to.
     */
    public static function autoStartApprovedActivities(Project $project): void
    {
        $today = now()->startOfDay();

        $activities = $project->implementationActivities()
            ->whereNull('actual_start_date')
            ->where(function ($query) {
                $query->whereNull('plan_change_status')->orWhere('plan_change_status', '!=', 'rejected');
            })
            ->get();

        foreach ($activities as $activity) {
            $start = $activity->planned_start_date && $activity->planned_start_date->gt($today)
                ? $activity->planned_start_date
                : $today;

            $activity->update(['actual_start_date' => $start->toDateString()]);
        }
    }

    /**
     * SDMM / IDMM → Coordinator recommendation. DICT → Approver execution sign-off.
     */
    public static function reviewTrack(Project $project): string
    {
        $explicit = strtoupper(trim((string) $project->review_track));
        if (in_array($explicit, ['DICT', 'IDMM', 'SDMM'], true)) {
            return $explicit;
        }

        // No track explicitly set — derive it from category, per the BRD's
        // automatic-routing rule. Category names ("System", "Infrastructure",
        // "Security") don't literally contain "SDMM"/"IDMM"/"DICT", so this
        // needs its own mapping rather than a substring match.
        return self::trackForCategory($project->category);
    }

    public static function trackForCategory(?string $category): string
    {
        return match (strtolower(trim((string) $category))) {
            'infrastructure' => 'IDMM',
            'security' => 'DICT',
            default => 'SDMM',
        };
    }

    public static function destinationForProject(Project $project): array
    {
        if (self::reviewTrack($project) === 'DICT') {
            return [
                'role_name' => 'Project Approver',
                'key' => 'approver',
                'track' => 'DICT',
            ];
        }

        return [
            'role_name' => 'Project Coordinator',
            'key' => 'coordinator',
            'track' => self::reviewTrack($project),
        ];
    }

    /**
     * @deprecated Use destinationForProject(). Kept so category-only callers still resolve.
     */
    public static function destinationForCategory(?string $category): array
    {
        $project = new Project(['category' => $category, 'review_track' => $category]);

        return self::destinationForProject($project);
    }

    public static function executionBlockers(Project $project): array
    {
        $blockers = [];

        if ($project->plan_review_status !== 'approved') {
            $blockers[] = 'Implementation plan has not been reviewed and approved.';
        }

        $currentDocs = $project->documents()->where('is_current', true)
            ->get(['document_type', 'review_status']);

        foreach (self::REQUIRED_DOCUMENT_TYPES as $type) {
            $hasType = $currentDocs->contains(function ($document) use ($type) {
                return strcasecmp((string) $document->document_type, $type) === 0;
            });
            if (! $hasType) {
                $blockers[] = "Required document missing: {$type}.";
            }
        }

        $returned = $currentDocs->where('review_status', 'returned');
        if ($returned->isNotEmpty()) {
            $blockers[] = 'Returned documents still have unresolved reviewer comments.';
        }

        $activitiesWithoutDocs = $project->implementationActivities()
            ->whereDoesntHave('documents', fn ($query) => $query->where('is_current', true))
            ->orderBy('id')->limit(5)->get(['name']);
        if ($activitiesWithoutDocs->isNotEmpty()) {
            $names = $activitiesWithoutDocs->pluck('name')->take(5)->implode(', ');
            $blockers[] = "Activities missing documents: {$names}.";
        }

        return $blockers;
    }

    public static function assertCanEnterExecution(Project $project): void
    {
        $blockers = self::executionBlockers($project);
        if ($blockers !== []) {
            throw ValidationException::withMessages([
                'execution' => $blockers,
            ]);
        }
    }

    /**
     * The one rule that applies no matter who's asking or what they were
     * able to do before: a closed project is frozen. No new activities or
     * requirements, no edits, no documents — regardless of role or whether
     * that person's own part of the workflow already passed. Shared here so
     * every controller that can mutate something under a project (not just
     * ProjectController's own actions) enforces it the same way.
     */
    public static function assertProjectOpen(Project $project): void
    {
        if ($project->closed_at) {
            throw ValidationException::withMessages([
                'project' => ['This project is closed and can no longer be changed.'],
            ]);
        }
    }

    public static function resolveForwardUser(Project $project, string $key, string $roleName): ?User
    {
        if ($key === 'approver' && $project->approver_id) {
            return User::find($project->approver_id);
        }
        if ($key === 'coordinator' && $project->coordinator_id) {
            return User::find($project->coordinator_id);
        }

        $role = Role::where('name', $roleName)->first();
        if (! $role) {
            return null;
        }

        return User::whereHas('roles', fn ($query) => $query->where('roles.id', $role->id))->first();
    }

    public static function recommendAndMoveToExecution(Project $project): Project
    {
        if (self::reviewTrack($project) === 'DICT') {
            throw ValidationException::withMessages([
                'recommend' => ['DICT projects require Approver execution sign-off, not Coordinator recommendation.'],
            ]);
        }

        if ($project->plan_review_status !== 'approved') {
            throw ValidationException::withMessages([
                'recommend' => ['The implementation plan must be approved before a Coordinator can recommend execution.'],
            ]);
        }

        self::assertCanEnterExecution($project);

        return self::moveToExecution($project, 'coordinator');
    }

    public static function approveExecution(Project $project): Project
    {
        if ($project->plan_review_status !== 'approved') {
            throw ValidationException::withMessages([
                'execution' => ['The implementation plan must be approved before execution sign-off.'],
            ]);
        }

        if (self::reviewTrack($project) !== 'DICT' && ! $project->recommended_at) {
            throw ValidationException::withMessages([
                'execution' => ['SDMM/IDMM projects must be recommended by a Coordinator before Approver sign-off.'],
            ]);
        }

        self::assertCanEnterExecution($project);

        $updated = self::moveToExecution($project, 'approver');
        $updated->update(['execution_approved_at' => now()]);

        return $updated->fresh([
            'reviewer',
            'planner',
            'coordinator',
            'approver',
            'forwardedTo',
        ]);
    }

    public static function moveToExecution(Project $project, string $actorKey): Project
    {
        $destination = self::destinationForProject($project);
        $forwardUser = self::resolveForwardUser($project, $destination['key'], $destination['role_name']);

        $updates = [
            'lifecycle_stage' => 'execution',
            'execution_started_at' => now(),
            'phase' => 'Execution',
            'status' => 'In Execution',
            'forwarded_role' => $destination['role_name'],
            'forwarded_to_user_id' => $forwardUser?->id,
        ];

        if ($actorKey === 'coordinator') {
            $updates['recommended_at'] = now();
        }

        if ($destination['key'] === 'approver' && $forwardUser) {
            $updates['approver_id'] = $forwardUser->id;
        }
        if ($destination['key'] === 'coordinator' && $forwardUser) {
            $updates['coordinator_id'] = $forwardUser->id;
        }

        $project->update($updates);
        self::autoStartApprovedActivities($project);

        return $project->fresh([
            'reviewer',
            'planner',
            'coordinator',
            'approver',
            'forwardedTo',
        ]);
    }

    public static function planningLocked(Project $project): bool
    {
        return in_array($project->currentPlanStatus(), ['pending_review'], true);
    }

    public static function workflowPayload(Project $project): array
    {
        $blockers = self::executionBlockers($project);
        $destination = self::destinationForProject($project);
        $track = self::reviewTrack($project);
        $inExecution = (bool) $project->execution_started_at;
        $closed = (bool) $project->closed_at;

        $closureChecks = $project->closureChecks();
        $readyToClose = collect($closureChecks)->every(fn ($check) => $check['passed']);

        return [
            'plan_review_status' => $project->plan_review_status,
            'plan_review_comment' => $project->plan_review_comment,
            'plan_pending_reapproval' => (bool) $project->plan_pending_reapproval,
            'phase' => $project->phase,
            'status' => $project->status,
            'category' => $project->category,
            'review_track' => $track,
            'queue' => self::queueName(
                $project->plan_review_status,
                $track,
                $inExecution,
                $closed,
                (bool) $project->closure_requested_at,
                (bool) $project->recommended_at,
            ),
            'forward_target' => $destination,
            'forwarded_role' => $project->forwarded_role,
            'forwarded_to_user_id' => $project->forwarded_to_user_id,
            'recommended_at' => $project->recommended_at,
            'execution_started_at' => $project->execution_started_at,
            'execution_approved_at' => $project->execution_approved_at,
            'closed_at' => $project->closed_at,
            'closure_requested_at' => $project->closure_requested_at,
            'closure_request_comment' => $project->closure_request_comment,
            'closure_return_comment' => $project->closure_return_comment,
            'can_request_closure' => $readyToClose && ! $project->closure_requested_at && ! $closed,
            'can_approve_closure' => $readyToClose && (bool) $project->closure_requested_at && ! $closed,
            'matrix_return_comment' => $project->matrix_return_comment,
            'matrix_returned_at' => $project->matrix_returned_at,
            'can_submit_plan' => $project->canSubmitPlan()
                && $project->implementationActivities()->exists()
                && ! $closed,
            'can_review_plan' => $project->plan_review_status === 'pending_review' && ! $closed,
            'can_recommend' => $project->plan_review_status === 'approved' && $track !== 'DICT' && ! $inExecution && ! $closed,
            'can_sign_off_execution' => $project->plan_review_status === 'approved' && ! $inExecution && ! $closed
                && ($track === 'DICT' || (bool) $project->recommended_at),
            'can_enter_execution' => $blockers === [],
            'execution_blockers' => $blockers,
            'required_document_types' => self::REQUIRED_DOCUMENT_TYPES,
            'closure' => [
                'ready' => $readyToClose,
                'checks' => $closureChecks,
            ],
        ];
    }

    /**
     * Lightweight summary for project list endpoints — avoids closure/execution
     * gate scans that were making GET /projects slow for every row.
     */
    public static function workflowListPayload(Project $project): array
    {
        $track = self::reviewTrack($project);
        $inExecution = (bool) $project->execution_started_at;
        $closed = (bool) $project->closed_at;

        return [
            'plan_review_status' => $project->plan_review_status,
            'phase' => $project->phase,
            'status' => $project->status,
            'review_track' => $track,
            'queue' => self::queueName(
                $project->plan_review_status,
                $track,
                $inExecution,
                $closed,
                (bool) $project->closure_requested_at,
                (bool) $project->recommended_at,
            ),
            'closure_requested_at' => $project->closure_requested_at,
            'closure_return_comment' => $project->closure_return_comment,
            'closed_at' => $project->closed_at,
        ];
    }

    /**
     * Pure/scalar on purpose (no Project param) — this is called per-row
     * over a couple thousand projects on the list endpoint, straight off raw
     * query results, and doesn't need a hydrated model to decide a queue name.
     */
    public static function queueName(
        ?string $planReviewStatus,
        string $track,
        bool $inExecution,
        bool $closed,
        bool $closureRequested,
        bool $recommended,
    ): string {
        if ($closed) {
            return 'closed';
        }
        if ($closureRequested) {
            return 'closure_sign_off';
        }
        if ($inExecution) {
            return 'in_execution';
        }
        if ($planReviewStatus === 'pending_review') {
            return 'plan_review';
        }
        if ($planReviewStatus === 'approved' && $track === 'DICT') {
            return 'execution_sign_off';
        }
        if ($planReviewStatus === 'approved' && ! $recommended) {
            return 'recommendation';
        }
        if ($planReviewStatus === 'approved') {
            return 'execution_sign_off';
        }

        return 'planning';
    }
}
