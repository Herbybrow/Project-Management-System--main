<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\Project;
use App\Services\ProjectWorkflowService;
use App\Support\InitiationDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function index(Request $request, int $projectId): JsonResponse
    {
        $query = Document::where('project_id', $projectId)->latest('uploaded_at');

        if ($request->boolean('lite')) {
            if ($request->filled('activity_id')) {
                $query->where('activity_id', $request->integer('activity_id'));
            }
            if ($request->filled('requirement_id')) {
                $query->where('requirement_id', $request->integer('requirement_id'));
            }

            return response()->json([
                'data' => $query->get([
                    'id',
                    'project_id',
                    'file_name',
                    'document_type',
                    'version_number',
                    'review_status',
                    'is_current',
                    'phase',
                    'uploaded_at',
                ]),
            ]);
        }

        $query->with(['uploader:id,name', 'reviewer:id,name', 'activity:id,name', 'requirement:id,requirement_code']);

        if ($request->filled('activity_id')) {
            $query->where('activity_id', $request->integer('activity_id'));
        }
        if ($request->filled('requirement_id')) {
            $query->where('requirement_id', $request->integer('requirement_id'));
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['required', 'exists:projects,id'],
            'activity_id' => ['nullable', Rule::exists('implementation_activities', 'id')->where('project_id', $request->input('project_id'))],
            'requirement_id' => ['nullable', Rule::exists('requirements', 'id')->where('project_id', $request->input('project_id'))],
            'document_type' => ['nullable', 'string', 'max:100'],
            'file' => ['required', 'file', 'mimes:pdf,docx,xlsx', 'max:10240'],
        ]);

        $project = Project::findOrFail($validated['project_id']);
        ProjectWorkflowService::assertProjectOpen($project);
        $this->authorizeWrite($request, $project);

        $file = $request->file('file');
        // 'local' (storage/app/private) is not web-reachable — every file is
        // only ever served through file() below, which checks auth first.
        $path = $file->store('documents', 'local');

        $activityId = $validated['activity_id'] ?? null;
        $documentType = $validated['document_type'] ?? null;

        $version = 1;
        $existing = null;

        if ($activityId) {
            $existing = Document::query()
                ->where('project_id', $validated['project_id'])
                ->where('activity_id', $activityId)
                ->where('file_name', $file->getClientOriginalName())
                ->orderByDesc('version_number')
                ->first();
        } elseif ($documentType) {
            // Project-level required docs (Implementation Plan, SRS) version by type,
            // not filename — otherwise uploading the same file name for two types
            // would replace the wrong document.
            $existing = Document::query()
                ->where('project_id', $validated['project_id'])
                ->whereNull('activity_id')
                ->where('document_type', $documentType)
                ->where('is_current', true)
                ->orderByDesc('version_number')
                ->first();
        } else {
            $existing = Document::query()
                ->where('project_id', $validated['project_id'])
                ->whereNull('activity_id')
                ->where('file_name', $file->getClientOriginalName())
                ->orderByDesc('version_number')
                ->first();
        }

        if ($existing) {
            $version = $existing->version_number + 1;
            if ($activityId) {
                Document::query()
                    ->where('project_id', $validated['project_id'])
                    ->where('activity_id', $activityId)
                    ->where('file_name', $file->getClientOriginalName())
                    ->update(['is_current' => false]);
            } elseif ($documentType) {
                Document::query()
                    ->where('project_id', $validated['project_id'])
                    ->whereNull('activity_id')
                    ->where('document_type', $documentType)
                    ->update(['is_current' => false]);
            } else {
                Document::query()
                    ->where('project_id', $validated['project_id'])
                    ->whereNull('activity_id')
                    ->where('file_name', $file->getClientOriginalName())
                    ->update(['is_current' => false]);
            }
        }

        $document = Document::create([
            'project_id' => $validated['project_id'],
            'activity_id' => $validated['activity_id'] ?? null,
            'requirement_id' => $validated['requirement_id'] ?? null,
            'document_type' => $validated['document_type'] ?? $this->inferType($file->getClientOriginalName()),
            'file_name' => $file->getClientOriginalName(),
            'file_url' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'version_number' => $version,
            'is_current' => true,
            'review_status' => 'pending',
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Document uploaded',
            'data' => $document->load(['uploader:id,name', 'activity:id,name']),
        ], 201);
    }

    public function storeInitiation(Request $request, Project $project): JsonResponse
    {
        if (! $request->user() || ! $request->user()->hasPermission('projects.register')) {
            return response()->json(['message' => 'Unauthorized access.'], 403);
        }
        ProjectWorkflowService::assertProjectOpen($project);

        $validated = $request->validate([
            'document_type' => ['required', Rule::in(InitiationDocuments::keys())],
            'file' => ['required', 'file', 'mimes:pdf,docx,xlsx', 'max:10240'],
        ]);

        $file = $request->file('file');
        $path = $file->store('documents', 'local');

        $existing = Document::query()
            ->where('project_id', $project->id)
            ->where('document_type', $validated['document_type'])
            ->where('is_current', true)
            ->first();

        $version = 1;
        if ($existing) {
            $version = $existing->version_number + 1;
            $existing->update(['is_current' => false]);
        }

        $document = Document::create([
            'project_id' => $project->id,
            'document_type' => $validated['document_type'],
            'phase' => 'initiation',
            'file_name' => $file->getClientOriginalName(),
            'file_url' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'version_number' => $version,
            'is_current' => true,
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Document uploaded',
            'data' => $document->load('uploader:id,name'),
        ], 201);
    }

    public function file(Request $request, Document $document): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($document->file_url), 404);

        // Opening a document used to write an audit_logs row on every single
        // view — the accountability log is meant for actions (approvals,
        // closures, edits), not routine reads, and this was both cluttering
        // it and adding a synchronous DB write to the hottest read path in
        // the app.
        return Storage::disk('local')->response(
            $document->file_url,
            $document->file_name,
            ['Content-Type' => $document->file_type ?: 'application/octet-stream']
        );
    }

    public function replace(Request $request, Document $document): JsonResponse
    {
        ProjectWorkflowService::assertProjectOpen($document->project);
        $this->authorizeWrite($request, $document->project);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,docx,xlsx', 'max:10240'],
        ]);

        $file = $validated['file'];
        $path = $file->store('documents', 'local');

        $document->update(['is_current' => false]);

        $replacement = Document::create([
            'project_id' => $document->project_id,
            'activity_id' => $document->activity_id,
            'requirement_id' => $document->requirement_id,
            'document_type' => $document->document_type,
            'phase' => $document->phase,
            'file_name' => $file->getClientOriginalName(),
            'file_url' => $path,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'version_number' => $document->version_number + 1,
            'is_current' => true,
            'review_status' => 'pending',
            'uploaded_by' => $request->user()->id,
            'uploaded_at' => now(),
        ]);

        return response()->json([
            'message' => 'Document replaced.',
            'data' => $replacement->load(['uploader:id,name', 'activity:id,name']),
        ], 201);
    }

    public function destroy(Request $request, Document $document): JsonResponse
    {
        ProjectWorkflowService::assertProjectOpen($document->project);
        $this->authorizeWrite($request, $document->project);

        if ($document->file_url) {
            Storage::disk('local')->delete($document->file_url);
        }
        $document->delete();

        return response()->json(['message' => 'Document deleted']);
    }

    private function inferType(string $fileName): string
    {
        $lower = strtolower($fileName);
        if (str_ends_with($lower, '.pdf')) {
            return 'PDF Document';
        }
        if (str_ends_with($lower, '.docx')) {
            return 'Word Document';
        }
        if (str_ends_with($lower, '.xlsx')) {
            return 'Excel Document';
        }

        return 'Document';
    }

    private function authorizeWrite(Request $request, Project $project): void
    {
        $user = $request->user();
        // Match existing editable document roles; planners are scoped to
        // their assigned projects, as they are for implementation activities.
        abort_unless($project->canBeManagedBy($user)
            || $user->hasRole('Project Reviewer')
            || $user->hasRole('ICT Support'), 403);
    }
}
