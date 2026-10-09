<?php

use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\UserManagementController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ImplementationActivityController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\RequirementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware(['auth:sanctum', 'account.active']);

// 5 attempts per minute per IP — stops scripted password guessing
// without a real user ever noticing it's there.
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

Route::middleware(['auth:sanctum', 'account.active', 'log.denied'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::get('/users', [AuthController::class, 'users']);
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/notifications', [NotificationController::class, 'index']);

    Route::middleware(['can.mutate', 'manage.users'])->prefix('admin')->group(function () {
        Route::get('/users', [UserManagementController::class, 'index']);
        Route::post('/users', [UserManagementController::class, 'store']);
        Route::put('/users/{user}/roles', [UserManagementController::class, 'syncRoles']);
        Route::get('/roles', [UserManagementController::class, 'roles']);
        Route::post('/users/{user}/password', [UserManagementController::class, 'updatePassword']);
        Route::post('/users/{user}/toggle-status', [UserManagementController::class, 'toggleStatus']);
        Route::get('/audit-logs', [AuditLogController::class, 'index']);
    });

    Route::middleware('can.mutate')->group(function () {
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::post('/projects/{project}/documents', [DocumentController::class, 'storeInitiation']);
        Route::post('/projects/{project}/advance-to-planning', [ProjectController::class, 'advanceToPlanning']);
        Route::put('/projects/{project}/reassign', [ProjectController::class, 'reassign']);
        Route::post('/projects/{project}/plan/submit', [ProjectController::class, 'submitPlan']);
        Route::post('/projects/{project}/plan/review', [ProjectController::class, 'reviewPlan']);
        Route::post('/projects/{project}/recommend', [ProjectController::class, 'recommend']);
        Route::post('/projects/{project}/approve-execution', [ProjectController::class, 'approveExecution']);
        Route::post('/projects/{project}/close', [ProjectController::class, 'close']);
        Route::post('/projects/{project}/closure/request', [ProjectController::class, 'requestClosure']);
        Route::post('/projects/{project}/closure/return', [ProjectController::class, 'returnClosure']);

        Route::post('/activities', [ImplementationActivityController::class, 'store']);
        Route::put('/activities/{id}', [ImplementationActivityController::class, 'update']);
        Route::delete('/activities/{id}', [ImplementationActivityController::class, 'destroy']);
        Route::post('/activities/{id}/plan-changes/approve', [ImplementationActivityController::class, 'approvePlanChange']);
        Route::post('/activities/{id}/plan-changes/reject', [ImplementationActivityController::class, 'rejectPlanChange']);
        Route::post('/activities/{id}/progress-review/submit', [ImplementationActivityController::class, 'submitProgressReview']);
        Route::post('/activities/{id}/progress-review/approve', [ImplementationActivityController::class, 'approveProgressReview']);
        Route::post('/activities/{id}/progress-review/reject', [ImplementationActivityController::class, 'rejectProgressReview']);

        Route::post('/requirements', [RequirementController::class, 'store']);
        Route::put('/requirements/{id}', [RequirementController::class, 'update']);
        Route::patch('/requirements/{id}/status', [RequirementController::class, 'updateStatus']);
        Route::patch('/requirements/{id}/review', [RequirementController::class, 'review']);
        Route::post('/projects/{projectId}/matrix/return', [RequirementController::class, 'returnMatrix']);

        Route::post('/documents', [DocumentController::class, 'store']);
        Route::post('/documents/{document}/replace', [DocumentController::class, 'replace']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
    });

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::get('/projects/{project}/workflow', [ProjectController::class, 'workflow']);
    Route::get('/projects/{project}/reviews', [ProjectController::class, 'reviews']);
    Route::get('/projects/{project}/closure-readiness', [ProjectController::class, 'closureReadiness']);
    Route::get('/projects/{project}/initiation-readiness', [ProjectController::class, 'initiationReadiness']);
    Route::get('/projects/{project}/archive', [ProjectController::class, 'archive']);
    Route::get('/projects/{project}/report', [ProjectController::class, 'report']);

    Route::get('/projects/{projectId}/activities', [ImplementationActivityController::class, 'index']);
    Route::get('/activities/{id}/progress', [ImplementationActivityController::class, 'progressHistory']);

    Route::get('/projects/{projectId}/requirements', [RequirementController::class, 'index']);
    Route::get('/projects/{project}/progress', [RequirementController::class, 'getProjectProgress']);

    Route::get('/projects/{projectId}/documents', [DocumentController::class, 'index']);
    Route::get('/documents/{document}/file', [DocumentController::class, 'file']);
});
