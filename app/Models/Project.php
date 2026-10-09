<?php

namespace App\Models;

use App\Support\InitiationDocuments;
use App\Support\Roles;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'annual_plan_reference',
        'category',
        'review_track',
        'project_type',
        'activity_name',
        'name',
        'description',
        'budget',
        'team_type',
        'initiation_document_id',
        'planner_id',
        'reviewer_id',
        'coordinator_id',
        'approver_id',
        'status',
        'phase',
        'lifecycle_stage',
        'plan_review_status',
        'plan_review_comment',
        'plan_reviewed_at',
        'plan_pending_reapproval',
        'plan_status',
        'plan_submitted_at',
        'plan_return_comment',
        'recommended_at',
        'execution_started_at',
        'execution_approved_at',
        'forwarded_role',
        'forwarded_to_user_id',
        'matrix_return_comment',
        'matrix_returned_at',
        'planned_start_date',
        'planned_end_date',
        'actual_start_date',
        'actual_end_date',
        'overall_implementation_score',
        'closed_at',
        'closed_by',
        'closure_comment',
        'closure_requested_at',
        'closure_requested_by',
        'closure_request_comment',
        'closure_return_comment',
    ];

    protected $casts = [
        'plan_reviewed_at' => 'datetime',
        'plan_pending_reapproval' => 'boolean',
        'plan_submitted_at' => 'datetime',
        'recommended_at' => 'datetime',
        'execution_started_at' => 'datetime',
        'execution_approved_at' => 'datetime',
        'matrix_returned_at' => 'datetime',
        'closed_at' => 'datetime',
        'closure_requested_at' => 'datetime',
        'budget' => 'float',
        'overall_implementation_score' => 'float',
        'planned_start_date' => 'date',
        'planned_end_date' => 'date',
        'actual_start_date' => 'date',
        'actual_end_date' => 'date',
    ];

    public function requirements(): HasMany
    {
        return $this->hasMany(Requirement::class);
    }

    public function implementationActivities(): HasMany
    {
        return $this->hasMany(ImplementationActivity::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function planner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'planner_id');
    }

    public function coordinator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'coordinator_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function forwardedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'forwarded_to_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * Prefer planner-scope plan_status, then Person 3 plan_review_status.
     */
    public function currentPlanStatus(): string
    {
        $status = $this->plan_status ?: 'draft';
        $review = $this->plan_review_status ?: 'draft';

        return $status !== 'draft' ? $status : $review;
    }

    public function canSubmitPlan(): bool
    {
        return in_array($this->currentPlanStatus(), ['draft', 'changes_requested'], true);
    }

    public function isPlanLocked(): bool
    {
        return $this->currentPlanStatus() === 'pending_review';
    }

    public function canBeManagedBy(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->hasRole(Roles::ADMINISTRATOR_ROLE)) {
            return true;
        }

        return (int) $this->planner_id === (int) $user->id;
    }

    public function applyPlanStatus(string $status, array $extra = []): void
    {
        $this->update(array_merge([
            'plan_status' => $status,
            'plan_review_status' => $status,
        ], $extra));
    }

    public function reopenPlanIfApproved(): void
    {
        // Once execution has actually started, there's no more "whole plan"
        // submit/review cycle left to redo — a post-sign-off edit to one
        // activity's planning fields is settled entirely through that
        // activity's own plan_change_status (see approvePlanChange), not by
        // dragging the whole project back into "Planning" / "Plan Returned".
        // Without this, editing a single approved activity after execution
        // sign-off silently relabelled the whole project as returned-to-planning.
        if ($this->execution_started_at) {
            return;
        }

        if ($this->currentPlanStatus() === 'approved') {
            $this->applyPlanStatus('changes_requested', [
                'phase' => 'Planning',
                'status' => 'Plan Returned',
            ]);
        }
    }

    /**
     * Contract for Person 1 dashboard KPIs.
     */
    public static function getReviewerMetrics(): array
    {
        $open = static::query()->whereNull('closed_at');

        return [
            'new_registrations' => (clone $open)->where('phase', 'Registration')->count(),
            'plans_pending_review' => (clone $open)->where('plan_review_status', 'pending_review')->count(),
            'awaiting_recommendation' => (clone $open)
                ->where('plan_review_status', 'approved')
                ->whereNull('recommended_at')
                ->whereNull('execution_started_at')
                ->count(),
            'awaiting_execution_sign_off' => (clone $open)
                ->where('plan_review_status', 'approved')
                ->whereNull('execution_started_at')
                ->count(),
            'in_execution' => (clone $open)->where('phase', 'Execution')->count(),
            'closed' => static::query()->whereNotNull('closed_at')->count(),
        ];
    }

    /**
     * Person 3 contract consumed by Person 1 dashboard.
     */
    public function scopeAssignedToPlanner($query, ?int $plannerId = null)
    {
        $query->whereNotNull('planner_id')->whereNull('closed_at');

        if ($plannerId) {
            $query->where('planner_id', $plannerId);
        }

        return $query;
    }

    public static function getPlannerMetrics(?int $plannerId = null): array
    {
        $projects = static::query()->assignedToPlanner($plannerId);
        $projectIds = (clone $projects)->pluck('id');

        return [
            'assigned_projects' => (clone $projects)->count(),
            'active_activities' => ImplementationActivity::query()->whereIn('project_id', $projectIds)->count(),
            'pending_matrix_items' => Requirement::query()
                ->whereIn('project_id', $projectIds)
                ->incomplete()
                ->count(),
            'plans_returned' => (clone $projects)->where('plan_review_status', 'changes_requested')->count(),
            'overdue_activities' => ImplementationActivity::query()
                ->whereIn('project_id', $projectIds)
                ->whereNull('actual_end_date')
                ->whereDate('planned_end_date', '<', now())
                ->count(),
            'closure_requests_pending' => (clone $projects)->whereNotNull('closure_requested_at')->count(),
        ];
    }

    /**
     * Person 3 contract — Person 2 closure engine calls this.
     */
    public function hasCompletedAllActivities(): bool
    {
        if ($this->implementationActivities()->doesntExist()) {
            return false;
        }

        return $this->implementationActivities()
            ->whereNull('actual_end_date')
            ->where(function ($query) {
                $query->whereNull('status')
                    ->orWhereRaw('LOWER(status) <> ?', ['completed']);
            })
            ->doesntExist();
    }

    /**
     * Person 3 contract — every matrix item is Completed with Pass/Fail.
     */
    public function hasPassedAllUAT(): bool
    {
        if ($this->requirements()->doesntExist()) {
            return false;
        }

        if ($this->requirements()->incomplete()->exists()) {
            return false;
        }

        return $this->requirements()
            ->where(function ($query) {
                $query->whereNull('test_result')
                    ->orWhereNotIn('test_result', ['Pass', 'Fail']);
            })
            ->doesntExist();
    }

    public function markEditedAfterReturn(): void
    {
        if ($this->plan_review_status === 'changes_requested' && ! $this->plan_pending_reapproval) {
            $this->update(['plan_pending_reapproval' => true]);
        }
    }

    /**
     * Person 4 contract — current closure/initiation documents must be reviewed.
     */
    public function hasAllClosureDocsReviewed(): bool
    {
        // Initiation package is accepted at registration; closure gates apply to
        // planning/execution deliverables only.
        $documents = $this->documents()->where('is_current', true)
            ->whereRaw("LOWER(COALESCE(phase, '')) <> ?", ['initiation']);

        return (clone $documents)->exists()
            && ! (clone $documents)->whereRaw("LOWER(COALESCE(review_status, '')) <> ?", ['approved'])->exists();
    }

    public function closureChecks(): array
    {
        return [
            [
                'key' => 'plan_approved',
                'label' => 'Implementation plan is approved',
                'passed' => $this->plan_review_status === 'approved',
            ],
            [
                'key' => 'activities',
                'label' => 'All activities are completed',
                'passed' => $this->hasCompletedAllActivities(),
            ],
            [
                'key' => 'uat',
                'label' => 'All matrix items are completed with Pass/Fail',
                'passed' => $this->hasPassedAllUAT(),
            ],
            [
                'key' => 'documents',
                'label' => 'All current documents are reviewed and approved',
                'passed' => $this->hasAllClosureDocsReviewed(),
            ],
        ];
    }

    public function isReadyToClose(): bool
    {
        return collect($this->closureChecks())->every(fn (array $check) => $check['passed']);
    }

    /**
     * SQL count of open projects that pass every closure gate — same rules as
     * isReadyToClose(), without hydrating each project and N+1 relation loads.
     */
    public static function countProjectsReadyToClose(): int
    {
        return static::query()
            ->whereNull('closed_at')
            ->where('plan_review_status', 'approved')
            ->whereHas('implementationActivities')
            ->whereDoesntHave('implementationActivities', function ($query) {
                $query->whereNull('actual_end_date')
                    ->where(function ($inner) {
                        $inner->whereNull('status')
                            ->orWhereRaw('LOWER(status) <> ?', ['completed']);
                    });
            })
            ->whereHas('requirements')
            ->whereDoesntHave('requirements', function ($query) {
                $query->incomplete();
            })
            ->whereDoesntHave('requirements', function ($query) {
                $query->where(function ($inner) {
                    $inner->whereNull('test_result')
                        ->orWhereNotIn('test_result', ['Pass', 'Fail']);
                });
            })
            ->whereHas('documents', function ($query) {
                $query->where('is_current', true)
                    ->whereRaw("LOWER(COALESCE(phase, '')) <> ?", ['initiation']);
            })
            ->whereDoesntHave('documents', function ($query) {
                $query->where('is_current', true)
                    ->whereRaw("LOWER(COALESCE(phase, '')) <> ?", ['initiation'])
                    ->whereRaw("LOWER(COALESCE(review_status, '')) <> ?", ['approved']);
            })
            ->count();
    }

    /**
     * Initiation projects still missing at least one required initiation document.
     */
    public static function queryInitiationMissingRequiredDocs()
    {
        $requiredTypes = collect(InitiationDocuments::TYPES)
            ->filter(fn (array $meta) => ($meta['required'] ?? false) === true)
            ->keys()
            ->all();

        return static::query()
            ->where('lifecycle_stage', 'initiation')
            ->where(function ($query) use ($requiredTypes) {
                foreach ($requiredTypes as $type) {
                    $query->orWhereDoesntHave('documents', function ($documents) use ($type) {
                        $documents->where('is_current', true)->where('document_type', $type);
                    });
                }
            });
    }

    /**
     * Initiation -> Planning gate. Checked both for the frontend checklist
     * and, authoritatively, by advance-to-planning before flipping
     * lifecycle_stage — a disabled button is not security.
     */
    public function initiationReadiness(): array
    {
        $current = $this->documents()
            ->where('is_current', true)
            ->whereIn('document_type', InitiationDocuments::keys())
            ->get()
            ->keyBy('document_type');

        $blockers = [];
        $documents = [];

        foreach (InitiationDocuments::TYPES as $key => $meta) {
            $document = $current->get($key);

            $documents[] = [
                'key' => $key,
                'label' => $meta['label'],
                'required' => $meta['required'],
                'uploaded' => (bool) $document,
                'document' => $document,
            ];

            if ($meta['required'] && ! $document) {
                $blockers[] = "Missing required document: {$meta['label']}.";
            }
        }

        return [
            'ready' => $blockers === [],
            'blockers' => $blockers,
            'documents' => $documents,
        ];
    }

    public function isReadyForPlanning(): bool
    {
        return $this->initiationReadiness()['ready'];
    }

    /**
     * The single gate currently holding this project back, in priority
     * order, for the Administrator dashboard's "transition blockers" table.
     * Null once closed or when nothing is blocking it.
     */
    public function transitionBlocker(): ?array
    {
        if ($this->closed_at) {
            return null;
        }

        if ($this->lifecycle_stage === 'initiation') {
            if (! $this->initiationReadiness()['ready']) {
                return ['reason' => 'Initiation documents missing', 'since' => $this->created_at];
            }

            return null;
        }

        if ($this->plan_review_status === 'pending_review') {
            return ['reason' => 'Plan not reviewed', 'since' => $this->plan_submitted_at ?? $this->updated_at];
        }

        $returnedDocument = $this->documents()
            ->where('is_current', true)
            ->where('review_status', 'returned')
            ->oldest('reviewed_at')
            ->first();
        if ($returnedDocument) {
            return [
                'reason' => 'Returned documents unresolved',
                'since' => $returnedDocument->reviewed_at ?? $returnedDocument->updated_at,
            ];
        }

        if ($this->execution_started_at && ! $this->hasPassedAllUAT()) {
            return ['reason' => 'UAT scores missing', 'since' => $this->execution_started_at];
        }

        return null;
    }

    /**
     * Aggregates for the Administrator dashboard at "/". Kept on the model,
     * alongside getReviewerMetrics()/getPlannerMetrics(), so it's queried the
     * same way the rest of the dashboard contracts are.
     */
    public static function administratorDashboard(): array
    {
        $metrics = static::portfolioMetricSnapshot();

        return [
            'status_counts' => $metrics['status_counts'],
            'phase_counts' => $metrics['phase_counts'],
            'implementation_score_average' => $metrics['implementation_score_average'],
            'uat_pass_rate' => $metrics['uat_pass_rate'],
            'total_budget' => $metrics['total_budget'],
            'requirement_total' => $metrics['requirement_total'],
            'transition_blockers' => static::transitionBlockersSummary(),
            'overdue_activities' => static::overdueActivityBuckets(),
            'awaiting_action' => [
                'new_registrations' => static::where('phase', 'Registration')->count(),
                'plans_pending_review' => static::where('plan_review_status', 'pending_review')->count(),
                'matrices_pending_approval' => Requirement::whereNull('review_decision')->distinct('project_id')->count('project_id'),
                'documents_pending_review' => Document::where('review_status', 'pending')->count(),
                'closure_signoffs' => static::countProjectsReadyToClose(),
            ],
        ];
    }

    /**
     * Shared portfolio COUNTs used by Admin and ViewOnly — no N+1 blocker walk.
     */
    public static function portfolioMetricSnapshot(): array
    {
        $status = DB::table('projects')->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN actual_start_date IS NOT NULL AND actual_end_date IS NULL THEN 1 ELSE 0 END) as ongoing,
            SUM(CASE WHEN actual_end_date IS NOT NULL THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN actual_start_date IS NULL THEN 1 ELSE 0 END) as not_started
        ")->first();

        $phaseRows = DB::table('projects')
            ->selectRaw('lifecycle_stage, COUNT(*) as c')
            ->groupBy('lifecycle_stage')
            ->pluck('c', 'lifecycle_stage');

        $phaseCounts = collect(['initiation', 'planning', 'execution', 'closure'])
            ->mapWithKeys(fn (string $stage) => [$stage => (int) ($phaseRows[$stage] ?? 0)])
            ->all();

        $averageScore = static::whereNotNull('overall_implementation_score')->avg('overall_implementation_score');
        $requirementTotal = Requirement::count();
        $passCount = Requirement::where('test_result', 'Pass')->count();

        return [
            'status_counts' => [
                'total' => (int) ($status->total ?? 0),
                'ongoing' => (int) ($status->ongoing ?? 0),
                'completed' => (int) ($status->completed ?? 0),
                'not_started' => (int) ($status->not_started ?? 0),
            ],
            'phase_counts' => $phaseCounts,
            'implementation_score_average' => $averageScore !== null ? round((float) $averageScore, 1) : null,
            'uat_pass_rate' => $requirementTotal > 0 ? round(($passCount / $requirementTotal) * 100, 1) : 0.0,
            'total_budget' => (float) static::sum('budget'),
            'requirement_total' => $requirementTotal,
        ];
    }

    /**
     * Same priority rules as transitionBlocker(), but set-based — never walks
     * every open project with per-row document/UAT queries.
     */
    public static function transitionBlockersSummary(int $limit = 50): array
    {
        $blockers = [];

        foreach (static::queryInitiationMissingRequiredDocs()
            ->whereNull('closed_at')
            ->get(['id', 'name', 'created_at']) as $project) {
            $blockers[$project->id] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'reason' => 'Initiation documents missing',
                'days_stuck' => self::daysSince($project->created_at),
            ];
        }

        foreach (static::query()
            ->whereNull('closed_at')
            ->where('plan_review_status', 'pending_review')
            ->get(['id', 'name', 'plan_submitted_at', 'updated_at']) as $project) {
            if (isset($blockers[$project->id])) {
                continue;
            }
            $since = $project->plan_submitted_at ?? $project->updated_at;
            $blockers[$project->id] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'reason' => 'Plan not reviewed',
                'days_stuck' => $since ? self::daysSince($since) : 0,
            ];
        }

        $returnedDocs = Document::query()
            ->where('documents.is_current', true)
            ->where('documents.review_status', 'returned')
            ->join('projects', 'projects.id', '=', 'documents.project_id')
            ->whereNull('projects.closed_at')
            ->where('projects.lifecycle_stage', '!=', 'initiation')
            ->groupBy('projects.id', 'projects.name')
            ->selectRaw('projects.id as project_id, projects.name as project_name, MIN(documents.reviewed_at) as since_at')
            ->get();

        foreach ($returnedDocs as $row) {
            if (isset($blockers[$row->project_id])) {
                continue;
            }
            $since = $row->since_at ? Carbon::parse($row->since_at) : null;
            $blockers[$row->project_id] = [
                'project_id' => $row->project_id,
                'project_name' => $row->project_name,
                'reason' => 'Returned documents unresolved',
                'days_stuck' => $since ? self::daysSince($since) : 0,
            ];
        }

        $uatIncomplete = static::query()
            ->whereNull('closed_at')
            ->whereNotNull('execution_started_at')
            ->where(function ($query) {
                $query->whereDoesntHave('requirements')
                    ->orWhereHas('requirements', function ($requirements) {
                        $requirements->incomplete();
                    })
                    ->orWhereHas('requirements', function ($requirements) {
                        $requirements->where(function ($inner) {
                            $inner->whereNull('test_result')
                                ->orWhereNotIn('test_result', ['Pass', 'Fail']);
                        });
                    });
            })
            ->get(['id', 'name', 'execution_started_at']);

        foreach ($uatIncomplete as $project) {
            if (isset($blockers[$project->id])) {
                continue;
            }
            $blockers[$project->id] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'reason' => 'UAT scores missing',
                'days_stuck' => $project->execution_started_at ? self::daysSince($project->execution_started_at) : 0,
            ];
        }

        return collect($blockers)
            ->sortByDesc('days_stuck')
            ->take($limit)
            ->values()
            ->all();
    }

    public static function overdueActivityBuckets(): array
    {
        $overdueDays = ImplementationActivity::query()
            ->whereNull('actual_start_date')
            ->whereNotNull('planned_start_date')
            ->whereDate('planned_start_date', '<', now()->toDateString())
            ->pluck('planned_start_date')
            ->map(fn ($date) => self::daysSince(Carbon::parse($date)));

        return [
            'total' => $overdueDays->count(),
            '1_day' => $overdueDays->filter(fn ($days) => $days === 1)->count(),
            '3_days' => $overdueDays->filter(fn ($days) => $days === 3)->count(),
            'over_3_days' => $overdueDays->filter(fn ($days) => $days > 3)->count(),
        ];
    }

    /**
     * Aggregates for the Reviewer dashboard at "/": the six queue counts,
     * the unified pending/returned queue behind the "awaiting review" table
     * and the urgent/upcoming panels, the monthly review-load line chart,
     * and turnaround metrics — all sourced from the reviews table, keyed on
     * reviewed_at.
     */
    public static function reviewerDashboard(): array
    {
        $today = now()->startOfDay();
        $slaDays = 3;
        // No stored due-date column for review items — a due date is derived
        // as submission + this SLA, consistently for overdue/upcoming and for
        // the awaiting-review table.
        $dueAt = fn ($submittedAt) => $submittedAt ? Carbon::parse($submittedAt)->addDays($slaDays)->startOfDay() : null;

        $newRegistrationProjects = static::queryInitiationMissingRequiredDocs()
            ->get(['id', 'name', 'created_at']);

        $plansPendingProjects = static::query()
            ->where('plan_status', 'pending_review')
            ->get(['id', 'name', 'plan_submitted_at', 'updated_at', 'plan_pending_reapproval']);

        $matrixPendingMeta = Requirement::query()
            ->whereNull('review_decision')
            ->selectRaw('project_id, MIN(updated_at) as submitted_at')
            ->groupBy('project_id')
            ->get()
            ->keyBy('project_id');
        $matricesPendingProjects = $matrixPendingMeta->isNotEmpty()
            ? static::whereIn('id', $matrixPendingMeta->keys())->get(['id', 'name'])
            : collect();

        $documentsPendingList = Document::query()
            ->where('review_status', 'pending')
            ->where('is_current', true)
            ->with('project:id,name')
            ->get(['id', 'project_id', 'uploaded_at', 'review_status']);

        $returnedDocuments = Document::query()
            ->where('review_status', 'returned')
            ->where('is_current', true)
            ->with('project:id,name')
            ->get(['id', 'project_id', 'reviewed_at']);

        $returnedPlanProjects = static::query()
            ->where('plan_status', 'changes_requested')
            ->get(['id', 'name', 'plan_reviewed_at']);

        $returnedMatrixProjects = static::query()
            ->whereNotNull('matrix_returned_at')
            ->whereHas('requirements', function ($query) {
                $query->whereIn('review_decision', ['needs_revision', 'rejected']);
            })
            ->get(['id', 'name', 'matrix_returned_at']);

        $closureSignoffs = static::countProjectsReadyToClose();

        $pending = collect();

        foreach ($newRegistrationProjects as $project) {
            $submittedAt = $project->created_at;
            $pending->push([
                'project' => $project->name,
                'project_id' => $project->id,
                'type' => 'Registration',
                'submitted_at' => $submittedAt,
                'due_at' => $dueAt($submittedAt),
                'status' => 'Pending',
            ]);
        }

        foreach ($plansPendingProjects as $project) {
            $submittedAt = $project->plan_submitted_at ?? $project->updated_at;
            $pending->push([
                'project' => $project->name,
                'project_id' => $project->id,
                'type' => 'Plan',
                'submitted_at' => $submittedAt,
                'due_at' => $dueAt($submittedAt),
                'status' => $project->plan_pending_reapproval ? 'In review' : 'Pending',
            ]);
        }

        $projectsWithAnyDecision = $matricesPendingProjects->isEmpty()
            ? collect()
            : Requirement::query()
                ->whereIn('project_id', $matricesPendingProjects->pluck('id'))
                ->whereNotNull('review_decision')
                ->distinct()
                ->pluck('project_id')
                ->flip();

        foreach ($matricesPendingProjects as $project) {
            $submittedAt = $matrixPendingMeta->get($project->id)?->submitted_at;
            $pending->push([
                'project' => $project->name,
                'project_id' => $project->id,
                'type' => 'Matrix',
                'submitted_at' => $submittedAt,
                'due_at' => $dueAt($submittedAt),
                'status' => $projectsWithAnyDecision->has($project->id) ? 'In review' : 'Pending',
            ]);
        }

        foreach ($documentsPendingList as $document) {
            $pending->push([
                'project' => $document->project?->name ?? '—',
                'project_id' => $document->project_id,
                'type' => 'Document',
                'submitted_at' => $document->uploaded_at,
                'due_at' => $dueAt($document->uploaded_at),
                'status' => 'Pending',
            ]);
        }

        $returned = collect();

        foreach ($returnedDocuments as $document) {
            $returned->push([
                'project' => $document->project?->name ?? '—',
                'project_id' => $document->project_id,
                'type' => 'Document',
                'submitted_at' => $document->reviewed_at,
                'status' => 'Returned',
            ]);
        }

        foreach ($returnedPlanProjects as $project) {
            $returned->push([
                'project' => $project->name,
                'project_id' => $project->id,
                'type' => 'Plan',
                'submitted_at' => $project->plan_reviewed_at,
                'status' => 'Returned',
            ]);
        }

        foreach ($returnedMatrixProjects as $project) {
            $returned->push([
                'project' => $project->name,
                'project_id' => $project->id,
                'type' => 'Matrix',
                'submitted_at' => $project->matrix_returned_at,
                'status' => 'Returned',
            ]);
        }

        $awaitingReview = $pending->concat($returned)
            ->filter(fn (array $item) => $item['submitted_at'] !== null)
            ->sortByDesc(fn (array $item) => Carbon::parse($item['submitted_at'])->timestamp)
            ->take(8)
            ->map(fn (array $item) => [
                'project' => $item['project'],
                'project_id' => $item['project_id'],
                'type' => $item['type'],
                'submitted_at' => Carbon::parse($item['submitted_at'])->toISOString(),
                'status' => $item['status'],
            ])
            ->values()
            ->all();

        $overdueReviews = $pending->filter(fn (array $item) => $item['due_at'] && $item['due_at']->lt($today))->count();

        $returnedOverFiveDays = $returned->filter(
            fn (array $item) => $item['submitted_at'] && Carbon::parse($item['submitted_at'])->lt($today->copy()->subDays(5))
        )->count();

        $plansDueToday = $pending->filter(
            fn (array $item) => $item['type'] === 'Plan' && $item['due_at'] && $item['due_at']->isSameDay($today)
        )->count();

        $dueTodayCount = $pending->filter(fn (array $item) => $item['due_at'] && $item['due_at']->isSameDay($today))->count();
        $dueNext3Days = $pending->filter(
            fn (array $item) => $item['due_at'] && $item['due_at']->gt($today) && $item['due_at']->lte($today->copy()->addDays(3))
        )->count();
        $dueNext7Days = $pending->filter(
            fn (array $item) => $item['due_at'] && $item['due_at']->gt($today->copy()->addDays(3)) && $item['due_at']->lte($today->copy()->addDays(7))
        )->count();

        // Per-requirement decisions land on entity_type "requirement"; a
        // bulk matrix return lands on "matrix" — both are the reviewer's own
        // decisions, so both count toward review load and turnaround.
        $reviewerEntityTypes = ['plan', 'document', 'requirement', 'matrix', 'closure'];

        $yearReviews = Review::query()
            ->whereIn('entity_type', $reviewerEntityTypes)
            ->where('reviewed_at', '>=', now()->startOfYear())
            ->get(['project_id', 'entity_type', 'decision', 'reviewed_at']);

        $reviewLoad = collect(range(1, 8))->map(function (int $month) use ($yearReviews) {
            $inMonth = $yearReviews->filter(fn (Review $review) => $review->reviewed_at->month === $month);

            return [
                'month' => Carbon::create(2000, $month, 1)->format('M'),
                'received' => $inMonth->where('decision', 'submitted')->count(),
                'completed' => $inMonth->where('decision', 'approved')->count(),
                'returned' => $inMonth->whereIn('decision', ['returned', 'rejected', 'needs_revision'])->count(),
            ];
        })->values()->all();

        $completedReviews = $yearReviews->where('decision', '!=', 'submitted');
        $submittedByKey = $yearReviews->where('decision', 'submitted')
            ->groupBy(fn (Review $review) => $review->project_id.'-'.$review->entity_type);

        $turnaroundDays = $completedReviews
            ->map(function (Review $completed) use ($submittedByKey) {
                $key = $completed->project_id.'-'.$completed->entity_type;
                $submission = ($submittedByKey->get($key) ?? collect())
                    ->filter(fn (Review $submitted) => $submitted->reviewed_at <= $completed->reviewed_at)
                    ->sortByDesc('reviewed_at')
                    ->first();

                return $submission ? self::daysSince($submission->reviewed_at, $completed->reviewed_at) : null;
            })
            ->filter(fn ($days) => $days !== null);

        $reviewedThisMonth = $completedReviews
            ->filter(fn (Review $review) => $review->reviewed_at->isSameMonth(now()) && $review->reviewed_at->isSameYear(now()))
            ->count();

        $totalDecisions = $completedReviews->count();
        // 'returned' is plan/document vocabulary for a different flow — the
        // return rate only counts requirement/matrix rejections.
        $returnedDecisions = $completedReviews->whereIn('decision', ['rejected', 'needs_revision'])->count();

        $newRegistrations = $newRegistrationProjects->count();
        $plansPending = $plansPendingProjects->count();
        $matricesPending = $matrixPendingMeta->count();
        $documentsPending = $documentsPendingList->count();
        $returnedUnresolved = $returnedDocuments->count() + $returnedPlanProjects->count() + $returnedMatrixProjects->count();

        return [
            'queue' => [
                'new_registrations' => $newRegistrations,
                'plans_pending' => $plansPending,
                'matrices_pending' => $matricesPending,
                'documents_pending' => $documentsPending,
                'returned_unresolved' => $returnedUnresolved,
                'closure_signoffs' => $closureSignoffs,
            ],
            'awaiting_review' => $awaitingReview,
            'urgent' => [
                'overdue_reviews' => $overdueReviews,
                'returned_over_5_days' => $returnedOverFiveDays,
                'plans_due_today' => $plansDueToday,
            ],
            'upcoming' => [
                'due_today' => $dueTodayCount,
                'next_3_days' => $dueNext3Days,
                'next_7_days' => $dueNext7Days,
            ],
            'review_load' => $reviewLoad,
            'turnaround' => [
                'avg_review_days' => $turnaroundDays->isNotEmpty() ? round($turnaroundDays->avg(), 1) : 0,
                'reviewed_this_month' => $reviewedThisMonth,
                'returned' => $returnedDecisions,
                'backlog' => $newRegistrations + $plansPending + $matricesPending + $documentsPending + $returnedUnresolved + $closureSignoffs,
                'return_rate' => $totalDecisions > 0 ? round(($returnedDecisions / $totalDecisions) * 100, 1) : 0,
            ],
        ];
    }

    /**
     * Aggregates for the read-only ViewOnly dashboard at "/". Uses shared
     * portfolio COUNTs only — never runs the Administrator blocker walk.
     */
    public static function viewOnlyDashboard(): array
    {
        $metrics = static::portfolioMetricSnapshot();

        $pendingRequirements = Requirement::where(function ($query) {
            $query->where('implementation_status', 'Pending')->orWhereNull('implementation_status');
        })->count();
        $ongoingRequirements = Requirement::where('implementation_status', 'Ongoing')->count();
        $completedRequirements = Requirement::where('implementation_status', 'Completed')->count();

        $projects = static::orderBy('name')
            ->get(['id', 'name', 'category', 'phase', 'overall_implementation_score'])
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'name' => $project->name,
                'category' => $project->category,
                'phase' => $project->phase,
                'overall_implementation_score' => $project->overall_implementation_score,
            ])
            ->values()
            ->all();

        return [
            'status_counts' => $metrics['status_counts'],
            'phase_counts' => $metrics['phase_counts'],
            'implementation_score_average' => $metrics['implementation_score_average'],
            'uat_pass_rate' => $metrics['uat_pass_rate'],
            'total_budget' => $metrics['total_budget'],
            'requirement_status_counts' => [
                'pending' => $pendingRequirements,
                'ongoing' => $ongoingRequirements,
                'completed' => $completedRequirements,
            ],
            'projects' => $projects,
        ];
    }

    /**
     * Whole days between $since and $until (defaults to now), always
     * non-negative. Carbon 3 flipped diffInDays() to return a signed value
     * by default, which for a past $since gives a negative number —
     * computed via raw timestamps here so the result doesn't depend on
     * that default.
     */
    private static function daysSince($since, $until = null): int
    {
        $untilTimestamp = $until?->timestamp ?? now()->timestamp;

        return max(0, (int) floor(($untilTimestamp - $since->timestamp) / 86400));
    }
}
