<?php

namespace App\Http\Controllers;

use App\Models\ProgressUpdate;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Review;
use App\Services\ProgressCalculator;
use App\Services\ProjectWorkflowService;
use App\Support\ProgressDateRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RequirementController extends Controller
{
    public function index($projectId, Request $request): JsonResponse
    {
        $query = Requirement::where('project_id', $projectId)->orderBy('requirement_code');

        if ($request->boolean('lite')) {
            $requirements = $query->get([
                'id',
                'project_id',
                'requirement_code',
                'description',
                'implementation_status',
                'test_result',
            ]);
            $requirements->each->setAppends([]);
        } else {
            $requirements = $query
                ->with(['progressUpdates' => fn ($q) => $q->latest()->with('updater:id,name')])
                ->get();
        }

        return response()->json(['data' => $requirements]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => 'required|exists:projects,id',
            'requirement_code' => 'required|string',
            'description' => 'required|string',
            'remarks' => 'nullable|string',
        ]);

        $project = Project::findOrFail($validated['project_id']);
        ProjectWorkflowService::assertProjectOpen($project);
        abort_unless($project->canBeManagedBy($request->user()), 403);

        $validated['implementation_status'] = 'Pending';

        $requirement = Requirement::create($validated);

        return response()->json([
            'message' => 'Requirement added to matrix',
            'data' => $requirement,
        ], 201);
    }

    /**
     * Editing a rejected requirement's own content (code/description) puts
     * it back in front of the reviewer — clearing the prior decision and
     * reason rather than leaving a stale rejection sitting next to new text.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $requirement = Requirement::with('project')->findOrFail($id);
        ProjectWorkflowService::assertProjectOpen($requirement->project);
        abort_unless($requirement->project->canBeManagedBy($request->user()), 403);

        $validated = $request->validate([
            'requirement_code' => 'required|string',
            'description' => 'required|string',
        ]);

        $requirement->update(array_merge($validated, [
            'review_decision' => null,
            'review_comment' => null,
        ]));

        return response()->json([
            'message' => 'Requirement updated and resubmitted for review',
            'data' => $requirement->fresh(),
        ]);
    }

    public function getProgress($project): JsonResponse
    {
        return $this->getProjectProgress($project);
    }

    public function getProjectProgress($project): JsonResponse
    {
        $requirements = Requirement::where('project_id', $project)->get();

        $total = $requirements->count();
        $completed = $requirements->where('implementation_status', 'Completed')->count();
        $ongoing = $requirements->where('implementation_status', 'Ongoing')->count();
        $pending = $requirements->where('implementation_status', 'Pending')->count();

        $overallProgress = ProgressCalculator::calculateProjectProgress($project);

        return response()->json([
            'project_id' => (int) $project,
            'overall_progress' => $overallProgress.'%',
            'score' => $overallProgress,
            'scoring' => [
                'Pending' => 0,
                'Ongoing' => 50,
                'Completed' => 100,
            ],
            'metrics' => [
                'total_requirements' => $total,
                'completed' => $completed,
                'ongoing' => $ongoing,
                'pending' => $pending,
            ],
        ]);
    }

    public function updateStatus(Request $request, $id): JsonResponse
    {
        $requirement = Requirement::with('project')->findOrFail($id);
        ProjectWorkflowService::assertProjectOpen($requirement->project);
        abort_unless($requirement->project->canBeManagedBy($request->user()), 403);
        $dateRules = ProgressDateRules::actual(
            optional($requirement->project?->planned_start_date)->toDateString(),
            null,
            optional($requirement->project?->planned_end_date)->toDateString()
        );

        $validated = $request->validate(array_merge([
            'implementation_status' => 'nullable|in:Pending,Ongoing,Completed',
            'test_result' => 'nullable|in:Pass,Fail',
            'remarks' => 'nullable|string',
        ], $dateRules));

        if (! empty($validated['actual_end_date'])) {
            $validated['implementation_status'] = 'Completed';
        } elseif (! empty($validated['actual_start_date'])) {
            $validated['implementation_status'] = 'Ongoing';
        } elseif (! isset($validated['implementation_status'])) {
            $validated['implementation_status'] = $requirement->implementation_status ?: 'Pending';
        }

        $requirement->update($validated);

        if (! empty($validated['remarks']) || isset($validated['actual_start_date']) || isset($validated['actual_end_date'])) {
            ProgressUpdate::create([
                'entity_type' => 'requirement',
                'entity_id' => $requirement->id,
                'actual_start_date' => $validated['actual_start_date'] ?? $requirement->actual_start_date,
                'actual_end_date' => $validated['actual_end_date'] ?? $requirement->actual_end_date,
                'remark' => $validated['remarks'] ?? null,
                'test_result' => $validated['test_result'] ?? $requirement->test_result,
                'status' => $requirement->implementation_status,
                'updated_by' => $request->user()->id,
            ]);
        }

        $overallProgress = ProgressCalculator::calculateProjectProgress($requirement->project_id);

        return response()->json([
            'message' => 'Requirement status updated successfully',
            'overall_implementation_score' => $overallProgress.'%',
            'data' => $requirement->fresh()->load(['progressUpdates' => fn ($q) => $q->latest()]),
        ]);
    }

    public function review(Request $request, $id): JsonResponse
    {
        $requirement = Requirement::with('project')->findOrFail($id);
        ProjectWorkflowService::assertProjectOpen($requirement->project);
        abort_unless($request->user()->hasPermission('projects.review'), 403);
        $dateRules = ProgressDateRules::actual(
            optional($requirement->project?->planned_start_date)->toDateString(),
            null,
            optional($requirement->project?->planned_end_date)->toDateString()
        );

        $validated = $request->validate(array_merge([
            'review_decision' => 'required|in:approved,rejected,needs_revision',
            'comment' => ['required_if:review_decision,rejected', 'nullable', 'string'],
            'implementation_status' => 'nullable|in:Pending,Ongoing,Completed',
            'test_result' => 'nullable|in:Pass,Fail',
            'remarks' => 'nullable|string',
        ], $dateRules));

        $requirement->fill(collect($validated)->only([
            'review_decision',
            'implementation_status',
            'test_result',
            'remarks',
            'actual_start_date',
            'actual_end_date',
        ])->filter(fn ($value) => $value !== null)->all());

        // The rejection reason lives on the requirement itself so the planner
        // sees it directly; clear it out once a fresh decision is approved.
        $requirement->review_comment = $validated['review_decision'] === 'rejected'
            ? $validated['comment']
            : null;

        if (! empty($validated['actual_end_date'])) {
            $requirement->implementation_status = 'Completed';
        } elseif (! empty($validated['actual_start_date']) && ! $requirement->implementation_status) {
            $requirement->implementation_status = 'Ongoing';
        }

        $requirement->save();

        Review::create([
            'project_id' => $requirement->project_id,
            'entity_type' => 'requirement',
            'entity_id' => $requirement->id,
            'reviewer_id' => $request->user()->id,
            'decision' => $validated['review_decision'],
            'comment' => $validated['comment'] ?? null,
            'reviewed_at' => now(),
        ]);

        if ($validated['review_decision'] === 'approved') {
            ProjectWorkflowService::autoApproveRequirementDocuments($requirement->id, $request->user()->id);
        }

        if (! empty($validated['comment'])) {
            ProgressUpdate::create([
                'entity_type' => 'requirement',
                'entity_id' => $requirement->id,
                'remark' => $validated['comment'],
                'status' => $requirement->implementation_status,
                'updated_by' => $request->user()->id,
            ]);
        }

        return response()->json([
            'message' => 'Requirement review saved',
            'data' => $requirement->fresh(),
        ]);
    }

    public function returnMatrix(Request $request, $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        ProjectWorkflowService::assertProjectOpen($project);
        abort_unless($request->user()->hasPermission('projects.review'), 403);
        $validated = $request->validate([
            'comment' => ['required', 'string'],
        ]);

        Requirement::where('project_id', $project->id)->update([
            'review_decision' => 'needs_revision',
        ]);

        $project->applyPlanStatus('changes_requested', [
            'matrix_return_comment' => $validated['comment'],
            'plan_return_comment' => $validated['comment'],
            'matrix_returned_at' => now(),
            'phase' => 'Planning',
            'status' => 'Plan Returned',
        ]);

        Review::create([
            'project_id' => $project->id,
            'entity_type' => 'matrix',
            'entity_id' => $project->id,
            'reviewer_id' => $request->user()->id,
            'decision' => 'needs_revision',
            'comment' => $validated['comment'],
            'reviewed_at' => now(),
        ]);

        $fresh = $project->fresh();

        return response()->json([
            'message' => 'Matrix returned to planner with comments.',
            'data' => [
                'project_id' => $fresh->id,
                'plan_status' => $fresh->plan_status,
                'return_comment' => $validated['comment'],
                'comment' => $validated['comment'],
                'returned_at' => $fresh->matrix_returned_at,
                'requirements' => Requirement::where('project_id', $fresh->id)->get(),
            ],
        ]);
    }
}
