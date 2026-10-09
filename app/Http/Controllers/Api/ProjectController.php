<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterProjectRequest;
use App\Models\ImplementationActivity;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Review;
use App\Services\ProjectArchiveSummaryBuilder;
use App\Services\ProjectWorkflowService;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProjectController extends Controller
{
    public function store(RegisterProjectRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $validated['reviewer_id'] = $request->user()->id;
        $validated['status'] = 'Initiated';
        $validated['phase'] = 'Registration';
        $validated['lifecycle_stage'] = 'initiation';
        $validated['plan_review_status'] = 'draft';
        $validated['plan_status'] = 'draft';
        // review_track is intentionally left unset here — it's not a
        // registration-time decision. ProjectWorkflowService::reviewTrack()
        // derives SDMM/IDMM/DICT from category on the fly wherever it's
        // needed (queue routing, dashboards) until a Coordinator/Approver
        // actually recommends the project.

        $project = Project::create($validated)->load(['reviewer', 'planner', 'coordinator', 'approver']);

        return response()->json([
            'message' => 'Project successfully registered in Process 1.',
            'data' => $project,
            'workflow' => ProjectWorkflowService::workflowPayload($project),
        ], 201);
    }

    /**
     * projects.* has 13 date/datetime-cast columns, and Eloquent's toArray()
     * spends real time per row re-parsing each of those through Carbon just
     * to serialize it back out — negligible at dozens of projects, but at a
     * couple thousand it alone was the entire cost of this endpoint (~2s).
     * This does the same joins Eloquent's eager-loaded relations would have,
     * but reshapes plain query-builder rows instead of hydrating and
     * re-serializing full models, and formats dates with a direct string
     * transform instead of a Carbon round-trip — same output, far cheaper.
     */
    public function index(Request $request): JsonResponse
    {
        $plannerId = $request->integer('planner_id') ?: null;
        $role = $request->query('role');

        $query = DB::table('projects')
            ->leftJoin('users as reviewer_u', 'reviewer_u.id', '=', 'projects.reviewer_id')
            ->leftJoin('users as planner_u', 'planner_u.id', '=', 'projects.planner_id')
            ->leftJoin('users as coordinator_u', 'coordinator_u.id', '=', 'projects.coordinator_id')
            ->leftJoin('users as approver_u', 'approver_u.id', '=', 'projects.approver_id')
            ->leftJoin('users as forwarded_u', 'forwarded_u.id', '=', 'projects.forwarded_to_user_id')
            // List UIs never render description / long comment blobs — dropping
            // them cuts payload size hard once the portfolio grows.
            ->select([
                'projects.id',
                'projects.annual_plan_reference',
                'projects.category',
                'projects.review_track',
                'projects.project_type',
                'projects.activity_name',
                'projects.name',
                'projects.budget',
                'projects.team_type',
                'projects.planner_id',
                'projects.reviewer_id',
                'projects.coordinator_id',
                'projects.approver_id',
                'projects.forwarded_to_user_id',
                'projects.status',
                'projects.phase',
                'projects.lifecycle_stage',
                'projects.plan_status',
                'projects.plan_review_status',
                'projects.plan_pending_reapproval',
                'projects.plan_review_comment',
                'projects.plan_submitted_at',
                'projects.plan_reviewed_at',
                'projects.recommended_at',
                'projects.execution_started_at',
                'projects.execution_approved_at',
                'projects.matrix_returned_at',
                'projects.closed_at',
                'projects.closure_requested_at',
                'projects.closure_return_comment',
                'projects.planned_start_date',
                'projects.planned_end_date',
                'projects.actual_start_date',
                'projects.actual_end_date',
                'projects.overall_implementation_score',
                'projects.created_at',
                'projects.updated_at',
                'reviewer_u.id as reviewer_user_id', 'reviewer_u.name as reviewer_name', 'reviewer_u.email as reviewer_email',
                'planner_u.id as planner_user_id', 'planner_u.name as planner_name', 'planner_u.email as planner_email',
                'coordinator_u.id as coordinator_user_id', 'coordinator_u.name as coordinator_name', 'coordinator_u.email as coordinator_email',
                'approver_u.id as approver_user_id', 'approver_u.name as approver_name', 'approver_u.email as approver_email',
                'forwarded_u.id as forwarded_user_id', 'forwarded_u.name as forwarded_name', 'forwarded_u.email as forwarded_email',
            ]);

        // Project Planners only see projects assigned to them.
        if ($role === Roles::PLANNER_ROLE && $request->user()) {
            $query->where('projects.planner_id', $request->user()->id);
        } elseif ($role === 'Project Approver' && $request->user()) {
            // History: projects this Approver has signed off (ongoing + completed).
            $approverId = $request->user()->id;
            $query->where(function ($scoped) use ($approverId) {
                $scoped->where('projects.approver_id', $approverId)
                    ->orWhereExists(function ($reviews) use ($approverId) {
                        $reviews->select(DB::raw(1))
                            ->from('reviews')
                            ->whereColumn('reviews.project_id', 'projects.id')
                            ->where('reviews.reviewer_id', $approverId)
                            ->where('reviews.entity_type', 'execution')
                            ->where('reviews.decision', 'approved');
                    });
            });
        } elseif ($role === 'Project Coordinator' && $request->user()) {
            // History: projects this Coordinator has recommended (ongoing + completed).
            $coordinatorId = $request->user()->id;
            $query->where(function ($scoped) use ($coordinatorId) {
                $scoped->where('projects.coordinator_id', $coordinatorId)
                    ->orWhereExists(function ($reviews) use ($coordinatorId) {
                        $reviews->select(DB::raw(1))
                            ->from('reviews')
                            ->whereColumn('reviews.project_id', 'projects.id')
                            ->where('reviews.reviewer_id', $coordinatorId)
                            ->where('reviews.entity_type', 'recommendation')
                            ->where('reviews.decision', 'recommended');
                    });
            });
        } elseif ($plannerId) {
            $query->where('projects.planner_id', $plannerId);
        }

        $rows = $query->orderByDesc('projects.created_at')->orderByDesc('projects.id')->get();

        $projects = $rows->map(fn ($row) => self::listRowToPayload($row));

        return response()->json([
            'data' => $projects,
        ], 200);
    }

    // datetime-cast columns keep whatever time-of-day is actually stored.
    private const LIST_DATETIME_COLUMNS = [
        'plan_reviewed_at', 'plan_submitted_at', 'recommended_at', 'execution_started_at',
        'execution_approved_at', 'matrix_returned_at', 'closed_at', 'closure_requested_at',
        'created_at', 'updated_at',
    ];

    // date-cast columns — Eloquent's `date` cast always normalizes these to
    // midnight regardless of any time-of-day component in the stored value,
    // so the raw string has to be truncated to just the date part first.
    private const LIST_DATE_ONLY_COLUMNS = [
        'planned_start_date', 'planned_end_date', 'actual_start_date', 'actual_end_date',
    ];

    private static function listRowToPayload(object $row): array
    {
        $attributes = (array) $row;

        $relations = [
            'reviewer' => self::relationOrNull($attributes, 'reviewer'),
            'planner' => self::relationOrNull($attributes, 'planner'),
            'coordinator' => self::relationOrNull($attributes, 'coordinator'),
            'approver' => self::relationOrNull($attributes, 'approver'),
            'forwarded_to' => self::relationOrNull($attributes, 'forwarded'),
        ];
        foreach (['reviewer', 'planner', 'coordinator', 'approver', 'forwarded'] as $prefix) {
            unset($attributes["{$prefix}_user_id"], $attributes["{$prefix}_name"], $attributes["{$prefix}_email"]);
        }

        foreach (self::LIST_DATETIME_COLUMNS as $column) {
            if (array_key_exists($column, $attributes)) {
                $attributes[$column] = self::isoDateOrNull($attributes[$column]);
            }
        }
        foreach (self::LIST_DATE_ONLY_COLUMNS as $column) {
            if (array_key_exists($column, $attributes) && $attributes[$column] !== null) {
                $attributes[$column] = self::isoDateOrNull(substr($attributes[$column], 0, 10));
            }
        }
        if (array_key_exists('budget', $attributes) && $attributes['budget'] !== null) {
            $attributes['budget'] = (float) $attributes['budget'];
        }
        // PostgreSQL returns NUMERIC as strings; keep the list API's
        // existing numeric score contract across database drivers.
        if (array_key_exists('overall_implementation_score', $attributes) && $attributes['overall_implementation_score'] !== null) {
            $attributes['overall_implementation_score'] = $attributes['overall_implementation_score'] + 0;
        }
        if (array_key_exists('plan_pending_reapproval', $attributes)) {
            $attributes['plan_pending_reapproval'] = (bool) $attributes['plan_pending_reapproval'];
        }

        // Deliberately not routed through workflowListPayload()/a hydrated
        // Project here — constructing a model (even via forceFill, skipping
        // toArray()) still runs every date column through Eloquent's cast
        // system to set it, which was almost as expensive as the toArray()
        // this rewrite was trying to avoid. queueName() is pure/scalar for
        // exactly this reason; the track/queue logic below mirrors
        // reviewTrack()'s own (trivial, stable) explicit-override check.
        $explicitTrack = strtoupper(trim((string) ($row->review_track ?? '')));
        $track = in_array($explicitTrack, ['DICT', 'IDMM', 'SDMM'], true)
            ? $explicitTrack
            : ProjectWorkflowService::trackForCategory($row->category ?? null);
        $inExecution = (bool) $row->execution_started_at;
        $closed = (bool) $row->closed_at;
        $closureRequested = (bool) $row->closure_requested_at;
        $recommended = (bool) $row->recommended_at;

        return array_merge($attributes, $relations, [
            'workflow' => [
                'plan_review_status' => $row->plan_review_status,
                'phase' => $row->phase,
                'status' => $row->status,
                'review_track' => $track,
                'queue' => ProjectWorkflowService::queueName(
                    $row->plan_review_status,
                    $track,
                    $inExecution,
                    $closed,
                    $closureRequested,
                    $recommended,
                ),
                'closure_requested_at' => self::isoDateOrNull($row->closure_requested_at),
                'closure_return_comment' => $row->closure_return_comment,
                'closed_at' => self::isoDateOrNull($row->closed_at),
            ],
        ]);
    }

    private static function relationOrNull(array $attributes, string $prefix): ?array
    {
        $id = $attributes["{$prefix}_user_id"] ?? null;
        if (! $id) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $attributes["{$prefix}_name"] ?? null,
            'email' => $attributes["{$prefix}_email"] ?? null,
        ];
    }

    /**
     * Matches Carbon's default JSON serialization for date/datetime casts
     * (e.g. "2026-09-17T00:00:00.000000Z") without constructing a Carbon
     * instance — SQLite stores these as "Y-m-d" or "Y-m-d H:i:s", and both
     * are a fixed-format string away from that, so there's nothing to parse.
     */
    private static function isoDateOrNull($raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (strlen($raw) === 10) {
            return $raw.'T00:00:00.000000Z';
        }

        return str_replace(' ', 'T', $raw).'.000000Z';
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $project->load([
            'reviewer:id,name,email',
            'planner:id,name,email',
            'coordinator:id,name,email',
            'approver:id,name,email',
            'forwardedTo:id,name,email',
        ]);

        if ($request->boolean('summary')) {
            $project->loadCount(['implementationActivities', 'documents', 'requirements']);
        } else {
            $project->load(['implementationActivities', 'documents', 'requirements']);
        }

        return response()->json([
            'data' => $project,
            'workflow' => ProjectWorkflowService::workflowPayload($project),
        ]);
    }

    public function initiationReadiness(Project $project): JsonResponse
    {
        return response()->json([
            'data' => $project->initiationReadiness(),
        ]);
    }

    public function advanceToPlanning(Request $request, Project $project): JsonResponse
    {
        if (! $this->allows($request, ['projects.register'])) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $this->guardOpen($project);

        $readiness = $project->initiationReadiness();
        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'blockers' => $readiness['blockers'],
            ]);
        }

        $project->update([
            'lifecycle_stage' => 'planning',
            'phase' => 'Planning',
            'status' => 'Planning',
        ]);

        return response()->json([
            'message' => 'Project advanced to Planning.',
            'data' => $project->fresh(),
        ]);
    }

    public function reassign(Request $request, Project $project): JsonResponse
    {
        if (! $this->allows($request, ['projects.assign_planner', 'projects.reassign_planner'])) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $this->guardOpen($project);

        $validated = $request->validate([
            'planner_id' => ['required', 'exists:users,id'],
        ]);

        $project->update(['planner_id' => $validated['planner_id']]);

        return response()->json([
            'message' => 'Project planner reassigned.',
            'data' => $project->fresh(['reviewer', 'planner', 'coordinator', 'approver']),
        ], 200);
    }

    public function workflow(Project $project): JsonResponse
    {
        return response()->json([
            'data' => ProjectWorkflowService::workflowPayload($project),
        ]);
    }

    public function submitPlan(Request $request, Project $project): JsonResponse
    {
        $this->guardOpen($project);

        if (! $project->canBeManagedBy($request->user())) {
            return response()->json(['message' => 'You can only submit a plan for a project assigned to you.'], 403);
        }

        if (! $project->canSubmitPlan()) {
            throw ValidationException::withMessages([
                'plan' => ['This plan is already in review or approved. Wait for a return before submitting again.'],
            ]);
        }

        if ($project->implementationActivities()->count() === 0) {
            throw ValidationException::withMessages([
                'plan' => ['Add at least one activity before submitting the plan for review.'],
            ]);
        }

        $project->applyPlanStatus('pending_review', [
            'phase' => 'Plan Review',
            'status' => 'Plan Submitted',
            'plan_review_comment' => null,
            'plan_return_comment' => null,
            'plan_pending_reapproval' => false,
            'plan_submitted_at' => now(),
        ]);

        Review::create([
            'project_id' => $project->id,
            'entity_type' => 'plan',
            'entity_id' => $project->id,
            'reviewer_id' => $request->user()->id,
            'decision' => 'submitted',
            'comment' => $request->input('comment'),
            'reviewed_at' => now(),
        ]);

        $fresh = $project->fresh();

        return response()->json([
            'message' => 'Implementation plan submitted for reviewer approval.',
            'data' => $fresh,
            'workflow' => ProjectWorkflowService::workflowPayload($fresh),
        ]);
    }

    public function reviewPlan(Request $request, Project $project): JsonResponse
    {
        if (! $this->allows($request, ['projects.review'])) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $this->guardOpen($project);

        $validated = $request->validate([
            'decision' => ['required', 'in:approved,returned'],
            'comment' => ['required_if:decision,returned', 'nullable', 'string'],
        ]);

        if ($project->currentPlanStatus() !== 'pending_review') {
            throw ValidationException::withMessages([
                'plan' => ['Only plans in pending review can be approved or returned.'],
            ]);
        }

        if ($validated['decision'] === 'approved') {
            $project->applyPlanStatus('approved', [
                'plan_review_comment' => $validated['comment'] ?? null,
                'plan_reviewed_at' => now(),
                'phase' => 'Plan Approved',
                'status' => 'Plan Approved',
            ]);
            $project->implementationActivities()->update([
                'plan_change_status' => 'approved',
                'pending_changes' => null,
            ]);
            ProjectWorkflowService::autoApproveProjectLevelDocuments($project->id, $request->user()->id);
            foreach ($project->implementationActivities as $activity) {
                ProjectWorkflowService::autoApproveActivityDocuments($activity->id, $request->user()->id);
            }
            $message = 'Implementation plan approved.';
        } else {
            $project->applyPlanStatus('changes_requested', [
                'plan_review_comment' => $validated['comment'],
                'plan_return_comment' => $validated['comment'],
                'plan_reviewed_at' => now(),
                'plan_pending_reapproval' => false,
                'phase' => 'Planning',
                'status' => 'Plan Returned',
            ]);
            $message = 'Implementation plan returned. Changes do not stick until re-approved.';
        }

        Review::create([
            'project_id' => $project->id,
            'entity_type' => 'plan',
            'entity_id' => $project->id,
            'reviewer_id' => $request->user()->id,
            'role_snapshot' => 'Project Reviewer',
            'decision' => $validated['decision'],
            'comment' => $validated['comment'] ?? null,
            'reviewed_at' => now(),
        ]);

        $fresh = $project->fresh();

        return response()->json([
            'message' => $message,
            'data' => $fresh,
            'workflow' => ProjectWorkflowService::workflowPayload($fresh),
        ]);
    }

    public function recommend(Request $request, Project $project): JsonResponse
    {
        if (! $this->allows($request, ['projects.recommend'])) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $this->guardOpen($project);

        $request->validate(['review_track' => ['nullable', 'string', 'in:SDMM,IDMM']]);
        if ($request->filled('review_track')) {
            $project->update(['review_track' => $request->input('review_track')]);
        }

        $updated = ProjectWorkflowService::recommendAndMoveToExecution($project);
        $updated->update(['coordinator_id' => $request->user()->id]);
        $updated = $updated->fresh();

        Review::create([
            'project_id' => $updated->id,
            'entity_type' => 'recommendation',
            'entity_id' => $updated->id,
            'reviewer_id' => $request->user()->id,
            'role_snapshot' => 'Project Coordinator',
            'decision' => 'recommended',
            'comment' => $request->input('comment'),
            'reviewed_at' => now(),
        ]);

        $destination = $updated->forwarded_role ?? 'next role';

        return response()->json([
            'message' => "Project recommended (SDMM/IDMM) and moved to execution. Forwarded to {$destination}.",
            'data' => $updated,
            'workflow' => ProjectWorkflowService::workflowPayload($updated),
        ], 200);
    }

    public function approveExecution(Request $request, Project $project): JsonResponse
    {
        if (! $this->allows($request, ['projects.approve'])) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $this->guardOpen($project);

        $updated = ProjectWorkflowService::approveExecution($project);
        $updated->update(['approver_id' => $request->user()->id]);
        $updated = $updated->fresh();

        Review::create([
            'project_id' => $updated->id,
            'entity_type' => 'execution',
            'entity_id' => $updated->id,
            'reviewer_id' => $request->user()->id,
            'role_snapshot' => 'Project Approver',
            'decision' => 'approved',
            'comment' => $request->input('comment'),
            'reviewed_at' => now(),
        ]);

        return response()->json([
            'message' => 'DICT execution sign-off recorded. Project moved to execution.',
            'data' => $updated,
            'workflow' => ProjectWorkflowService::workflowPayload($updated),
        ]);
    }

    public function reviews(Project $project): JsonResponse
    {
        $reviews = $project->reviews()
            ->with('reviewer:id,name,email')
            ->latest('reviewed_at')
            ->get();

        return response()->json(['data' => $reviews]);
    }

    public function closureReadiness(Project $project): JsonResponse
    {
        $checks = $project->closureChecks();

        return response()->json([
            'data' => [
                'ready' => collect($checks)->every(fn (array $check) => $check['passed']),
                'checks' => $checks,
                'status' => $project->status,
                'closed_at' => $project->closed_at,
                'closure_requested_at' => $project->closure_requested_at,
                'closure_request_comment' => $project->closure_request_comment,
                'closure_return_comment' => $project->closure_return_comment,
                'helpers' => [
                    'hasCompletedAllActivities' => $project->hasCompletedAllActivities(),
                    'hasPassedAllUAT' => $project->hasPassedAllUAT(),
                    'hasAllClosureDocsReviewed' => $project->hasAllClosureDocsReviewed(),
                ],
            ],
        ]);
    }

    public function requestClosure(Request $request, Project $project): JsonResponse
    {
        $this->guardOpen($project);

        if (! $project->canBeManagedBy($request->user())) {
            return response()->json(['message' => 'You can only request closure for a project assigned to you.'], 403);
        }

        $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        if (! $project->isReadyToClose()) {
            $failed = collect($project->closureChecks())->where('passed', false)->pluck('label')->values()->all();
            throw ValidationException::withMessages([
                'close' => $failed,
            ]);
        }

        $project->update([
            'lifecycle_stage' => 'closure',
            'closure_requested_at' => now(),
            'closure_requested_by' => $request->user()->id,
            'closure_request_comment' => $request->input('comment'),
            'closure_return_comment' => null,
        ]);

        Review::create([
            'project_id' => $project->id,
            'entity_type' => 'closure',
            'entity_id' => $project->id,
            'reviewer_id' => $request->user()->id,
            'role_snapshot' => 'Project Planner',
            'decision' => 'requested',
            'comment' => $request->input('comment'),
            'reviewed_at' => now(),
        ]);

        $fresh = $project->fresh(['planner', 'reviewer']);

        return response()->json([
            'message' => 'Closure requested. The reviewer will verify documents and sign off.',
            'data' => $fresh,
            'workflow' => ProjectWorkflowService::workflowPayload($fresh),
        ]);
    }

    public function returnClosure(Request $request, Project $project): JsonResponse
    {
        if (! $this->allows($request, ['projects.close', 'projects.review'])) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $this->guardOpen($project);

        $validated = $request->validate([
            'comment' => ['required', 'string'],
            'activity_ids' => ['nullable', 'array'],
            'activity_ids.*' => ['integer', 'exists:implementation_activities,id'],
            'requirement_ids' => ['nullable', 'array'],
            'requirement_ids.*' => ['integer', 'exists:requirements,id'],
        ]);

        $project->update([
            'closure_requested_at' => null,
            'closure_requested_by' => null,
            'closure_request_comment' => null,
            'closure_return_comment' => $validated['comment'],
        ]);

        // Reopening a specific item is what actually lets the planner act on
        // the comment — clearing its actual end date (and, for a
        // requirement, its test result) puts it back to "Ongoing" so Update
        // and the Start/Complete/Test cycle become available again.
        if (! empty($validated['activity_ids'])) {
            ImplementationActivity::whereIn('id', $validated['activity_ids'])
                ->where('project_id', $project->id)
                ->update(['actual_end_date' => null, 'status' => null]);
        }
        if (! empty($validated['requirement_ids'])) {
            Requirement::whereIn('id', $validated['requirement_ids'])
                ->where('project_id', $project->id)
                ->update(['actual_end_date' => null, 'test_result' => null, 'implementation_status' => 'Ongoing']);
        }

        Review::create([
            'project_id' => $project->id,
            'entity_type' => 'closure',
            'entity_id' => $project->id,
            'reviewer_id' => $request->user()->id,
            'role_snapshot' => 'Project Reviewer',
            'decision' => 'returned',
            'comment' => $validated['comment'],
            'reviewed_at' => now(),
        ]);

        $fresh = $project->fresh(['planner', 'reviewer']);

        return response()->json([
            'message' => 'Closure request returned to the planner.',
            'data' => $fresh,
            'workflow' => ProjectWorkflowService::workflowPayload($fresh),
        ]);
    }

    public function close(Request $request, Project $project): JsonResponse
    {
        if (! $this->allows($request, ['projects.close', 'projects.review'])) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }

        $this->guardOpen($project);

        $request->validate([
            'comment' => ['nullable', 'string'],
        ]);

        $checks = $project->closureChecks();
        $failed = collect($checks)->where('passed', false)->pluck('label')->values()->all();
        if ($failed !== []) {
            throw ValidationException::withMessages([
                'close' => $failed,
            ]);
        }

        if (! $project->closure_requested_at) {
            throw ValidationException::withMessages([
                'close' => ['The planner must request closure before the reviewer can sign off.'],
            ]);
        }

        $project->update([
            'status' => 'Closed',
            'phase' => 'Closed',
            'lifecycle_stage' => 'closure',
            'closed_at' => now(),
            'closed_by' => $request->user()->id,
            'closure_comment' => $request->input('comment'),
        ]);

        Review::create([
            'project_id' => $project->id,
            'entity_type' => 'closure',
            'entity_id' => $project->id,
            'reviewer_id' => $request->user()->id,
            'role_snapshot' => 'Project Reviewer',
            'decision' => 'closed',
            'comment' => $request->input('comment'),
            'reviewed_at' => now(),
        ]);

        $fresh = $project->fresh(['closedBy', 'planner', 'reviewer']);

        return response()->json([
            'message' => 'Project closed.',
            'data' => $fresh,
            'workflow' => ProjectWorkflowService::workflowPayload($fresh),
        ]);
    }

    /**
     * Everything about a finished project in one file: every current
     * document plus a formatted Word summary of the activities, RTM, and
     * key dates — for handover/archival once there's nothing left to change.
     */
    public function archive(Project $project): BinaryFileResponse|JsonResponse
    {
        if (! $project->closed_at) {
            return response()->json(['message' => 'Only a closed project can be downloaded as an archive.'], 422);
        }

        $documents = $this->loadForSummary($project);

        $tempDir = storage_path('app/private/tmp');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempPath = $tempDir.'/project-'.$project->id.'-'.uniqid().'.zip';

        $zip = new \ZipArchive();
        $zip->open($tempPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('Project Summary.docx', ProjectArchiveSummaryBuilder::build($project, $documents));

        $usedNames = [];
        foreach ($documents as $document) {
            $localPath = Storage::disk('local')->path($document->file_url);
            if (! is_file($localPath)) {
                continue; // sample/demo rows have no real file behind them
            }
            $name = $document->file_name ?: ('document-'.$document->id);
            while (in_array($name, $usedNames, true)) {
                $name = pathinfo($name, PATHINFO_FILENAME).'-'.$document->id.'.'.pathinfo($name, PATHINFO_EXTENSION);
            }
            $usedNames[] = $name;
            $zip->addFile($localPath, 'Documents/'.$name);
        }
        $zip->close();

        $downloadName = str()->slug($project->name ?: 'project').'-archive.zip';

        return response()->download($tempPath, $downloadName)->deleteFileAfterSend(true);
    }

    /**
     * The same formatted Word summary the archive bundles, available for any
     * project at any stage — not gated on closure like archive() is, since a
     * planner or reviewer may want a snapshot of a project that's still open.
     */
    public function report(Project $project): \Illuminate\Http\Response
    {
        $documents = $this->loadForSummary($project);
        $content = ProjectArchiveSummaryBuilder::build($project, $documents);
        $downloadName = str()->slug($project->name ?: 'project').'-report.docx';

        return response($content, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="'.$downloadName.'"',
        ]);
    }

    private function loadForSummary(Project $project): \Illuminate\Support\Collection
    {
        $project->load([
            'implementationActivities.responsiblePerson:id,name',
            'requirements',
            'planner:id,name,email',
            'reviewer:id,name,email',
            'coordinator:id,name,email',
            'approver:id,name,email',
        ]);

        return $project->documents()->where('is_current', true)->get();
    }

    private function allows(Request $request, array $permissions): bool
    {
        $user = $request->user();
        if (! $user) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    private function guardOpen(Project $project): void
    {
        ProjectWorkflowService::assertProjectOpen($project);
    }
}
