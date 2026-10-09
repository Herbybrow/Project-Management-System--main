<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\ImplementationActivity;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\User;
use App\Services\ProjectDashboardMetrics;
use App\Services\ProjectWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $request->query('role') ?: data_get($user->toAuthArray(), 'role');
        if ($role && ! $user->hasRole($role)) {
            return response()->json(['message' => 'This dashboard role is not assigned to your account.'], 403);
        }

        $pendingActions = [];
        $metrics = ProjectDashboardMetrics::getReviewerMetrics();

        // Each role's own queue page now — Administrator isn't project-
        // facing anymore (no Project Management, no reviews queue), so it's
        // excluded from all three of these instead of pointing at pages it
        // can no longer reach.
        if ($role === 'Project Planner') {
            $metrics = array_merge($metrics, ProjectDashboardMetrics::getPlannerMetrics($user->id));
            $pendingActions[] = [
                'label' => 'Open assigned projects',
                // PMS-2 has no /planning-queue route — planners work from /projects.
                'path' => '/projects',
            ];
        }

        if ($role === 'Project Reviewer') {
            $pendingActions[] = [
                'label' => 'Open project management',
                // PMS-2 has no /review-queue route — reviewers work from /projects.
                'path' => '/projects',
            ];
        }

        if ($role === 'Project Coordinator') {
            $pendingActions[] = [
                'label' => 'Open recommendations queue',
                'path' => '/recommendations',
            ];
        }

        if ($role === 'Project Approver') {
            $pendingActions[] = [
                'label' => 'Open execution sign-off queue',
                'path' => '/reviews',
            ];
        }

        if ($role === 'ICT Support') {
            $pendingActions[] = [
                'label' => 'Manage users and roles',
                'path' => '/user-management',
            ];
        }

        $payload = [
            'role' => $role,
            'counts' => $metrics,
            'pending_actions' => $pendingActions,
            'activity_total' => ImplementationActivity::count(),
            'requirement_total' => Requirement::count(),
            'document_total' => Document::count(),
        ];

        if ($role === 'Project Administrator') {
            // Administrator's own two responsibilities now — User
            // Management and Audit Log — not a second copy of the
            // project-portfolio numbers ViewOnly/Reviewer already own.
            $payload['admin'] = array_merge(User::ictSupportDashboard(), [
                'security_highlights' => $this->securityHighlights(),
            ]);
        }

        if ($role === 'Project Planner') {
            $payload['planner'] = $this->plannerDashboard($user->id);
        }

        if ($role === 'Project Reviewer') {
            $payload['reviewer'] = ProjectDashboardMetrics::reviewerDashboard();
        }

        if ($role === 'Project Coordinator') {
            $payload['coordinator'] = $this->coordinatorDashboard();
        }

        if ($role === 'Project Approver') {
            $payload['approver'] = $this->approverDashboard();
        }

        if ($role === 'Project ViewOnly') {
            $payload['view_only'] = ProjectDashboardMetrics::viewOnlyDashboard();
        }

        if ($role === 'ICT Support') {
            $payload['ict_support'] = array_merge(User::ictSupportDashboard(), [
                'flagged_accounts' => $this->flaggedAccounts(),
            ]);
        }

        return response()->json(['data' => $payload]);
    }

    /**
     * The Planner's own three worklists: activities past their planned end
     * with no actual end recorded yet, requirements/activity plan changes a
     * Reviewer has rejected (need rework, not just "returned"), and a
     * lifecycle-stage breakdown of everything currently assigned to them.
     */
    private function plannerDashboard(int $plannerId): array
    {
        $projectIds = Project::query()
            ->where('planner_id', $plannerId)
            ->whereNull('closed_at')
            ->pluck('id');

        $overdueActivities = ImplementationActivity::query()
            ->whereIn('project_id', $projectIds)
            ->whereNull('actual_end_date')
            ->whereNotNull('planned_end_date')
            ->whereDate('planned_end_date', '<', now()->toDateString())
            ->with(['project:id,name', 'responsiblePerson:id,name'])
            ->orderBy('planned_end_date')
            ->limit(10)
            ->get()
            ->map(fn (ImplementationActivity $activity) => [
                'project_id' => $activity->project_id,
                'project_name' => $activity->project?->name,
                'activity' => $activity->name,
                'planned_end_date' => $activity->planned_end_date,
                'days_overdue' => (int) $activity->planned_end_date->diffInDays(now(), true),
                'responsible' => $activity->responsiblePerson?->name,
            ])
            ->all();

        $rejectedRequirements = Requirement::query()
            ->whereIn('project_id', $projectIds)
            ->where('review_decision', 'rejected')
            ->with('project:id,name')
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Requirement $requirement) => [
                'project_id' => $requirement->project_id,
                'project_name' => $requirement->project?->name,
                'label' => 'REQ '.$requirement->requirement_code,
                'reason' => $requirement->review_comment,
            ]);

        $rejectedActivities = ImplementationActivity::query()
            ->whereIn('project_id', $projectIds)
            ->where('plan_change_status', 'rejected')
            ->with('project:id,name')
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (ImplementationActivity $activity) => [
                'project_id' => $activity->project_id,
                'project_name' => $activity->project?->name,
                'label' => 'Activity — '.$activity->name,
                'reason' => $activity->plan_change_comment,
            ]);

        $stageBreakdown = collect(['planning', 'execution', 'closure'])
            ->mapWithKeys(fn (string $stage) => [
                $stage => Project::where('planner_id', $plannerId)
                    ->whereNull('closed_at')
                    ->where('lifecycle_stage', $stage)
                    ->count(),
            ])
            ->all();

        return [
            'overdue_activities' => $overdueActivities,
            'needs_rework' => $rejectedRequirements->concat($rejectedActivities)->values()->all(),
            'stage_breakdown' => $stageBreakdown,
        ];
    }

    /**
     * Projects with an approved plan waiting on a Coordinator's recommendation
     * (SDMM/IDMM only — DICT never reaches this queue), split into ready vs
     * blocked using the exact same executionBlockers() check the Coordinator's
     * own recommend screen enforces, so this count never disagrees with it.
     */
    private function coordinatorDashboard(): array
    {
        $projects = Project::query()
            ->whereNull('closed_at')
            ->whereNull('execution_started_at')
            ->whereNull('recommended_at')
            ->where('plan_review_status', 'approved')
            ->with('planner:id,name')
            ->get()
            ->filter(fn (Project $project) => ProjectWorkflowService::reviewTrack($project) !== 'DICT');

        $rows = $projects->map(function (Project $project) {
            $blockers = ProjectWorkflowService::executionBlockers($project);

            return [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'track' => ProjectWorkflowService::reviewTrack($project),
                'planner' => $project->planner?->name,
                'ready' => $blockers === [],
                'blocker' => $blockers[0] ?? null,
            ];
        })->values();

        return [
            'awaiting_recommendation' => $rows->count(),
            'ready' => $rows->where('ready', true)->count(),
            'blocked' => $rows->where('ready', false)->count(),
            'projects' => $rows->all(),
        ];
    }

    /**
     * Execution sign-off queue split by how a project got here — DICT skips
     * recommendation entirely (direct sign-off), SDMM/IDMM only arrives after
     * a Coordinator has already recommended it — plus a same-role count of
     * what's actually been signed off in the last 7 days.
     */
    private function approverDashboard(): array
    {
        $awaiting = Project::query()
            ->whereNull('closed_at')
            ->whereNull('execution_started_at')
            ->where('plan_review_status', 'approved')
            ->with('planner:id,name')
            ->get()
            ->filter(fn (Project $project) => ProjectWorkflowService::reviewTrack($project) === 'DICT' || $project->recommended_at !== null);

        $rows = $awaiting->map(fn (Project $project) => [
            'project_id' => $project->id,
            'project_name' => $project->name,
            'track' => ProjectWorkflowService::reviewTrack($project),
            'planner' => $project->planner?->name,
            'path' => ProjectWorkflowService::reviewTrack($project) === 'DICT' ? 'direct' : 'recommended',
        ])->values();

        $signedOffThisWeek = Project::query()
            ->whereNotNull('execution_approved_at')
            ->where('execution_approved_at', '>=', now()->startOfWeek())
            ->count();

        return [
            'awaiting_dict' => $rows->where('path', 'direct')->count(),
            'awaiting_recommended' => $rows->where('path', 'recommended')->count(),
            'signed_off_this_week' => $signedOffThisWeek,
            'projects' => $rows->all(),
        ];
    }

    /** Recent login/access events for the Administrator's control-room view. */
    private function securityHighlights(int $limit = 6): array
    {
        return AuditLog::query()
            ->whereIn('action', ['login_failed', 'access_denied', 'account_created'])
            ->with('user:id,name,email')
            ->latest('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => [
                'action' => $log->action,
                'who' => $log->user?->name ?? $log->description ?? 'Unknown',
                'when' => $log->created_at,
            ])
            ->all();
    }

    /**
     * Accounts ICT Support actually needs to act on: repeated failed logins
     * (grouped, so one person's 3 attempts is one row, not three) and
     * disabled accounts — there's no separate "locked" flag in this system,
     * is_active is what a disable actually sets.
     */
    private function flaggedAccounts(int $limit = 8): array
    {
        $failedLogins = AuditLog::query()
            ->where('action', 'login_failed')
            ->where('created_at', '>=', now()->subDays(7))
            ->whereNotNull('user_id')
            ->with('user:id,name,email')
            ->get()
            ->groupBy('user_id')
            ->map(function ($logs) {
                $latest = $logs->sortByDesc('created_at')->first();

                return [
                    'user' => $latest->user?->name ?? $latest->user?->email ?? 'Unknown',
                    'issue' => $logs->count().' failed login'.($logs->count() === 1 ? '' : 's'),
                    'when' => $latest->created_at,
                    'severity' => 'danger',
                ];
            });

        $disabled = User::where('is_active', false)
            ->latest('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (User $account) => [
                'user' => $account->name ?? $account->email,
                'issue' => 'Account disabled',
                'when' => $account->updated_at,
                'severity' => 'danger',
            ]);

        return $failedLogins->values()->concat($disabled)
            ->sortByDesc('when')
            ->take($limit)
            ->values()
            ->all();
    }
}
