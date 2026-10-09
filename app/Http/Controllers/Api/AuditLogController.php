<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    /**
     * Paginated, most-recent-first — this table grows on every login and
     * every document view, so unlike the rest of the app's list endpoints it
     * can't just return everything in one response. `per_page` is allowed up
     * to 5000 so the "export" button on the page can pull every row matching
     * the current filters in one call instead of paging through them.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::with('user:id,name,email')->latest('created_at');

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($inner) use ($term) {
                $inner->whereLike('description', $term)
                    ->orWhereLike('ip_address', $term)
                    ->orWhereHas('user', function ($userQuery) use ($term) {
                        $userQuery->whereLike('name', $term)->orWhereLike('email', $term);
                    });
            });
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }

        $perPage = max(1, min($request->integer('per_page', 25), 5000));
        $paginated = $query->paginate($perPage);

        $flaggedIds = $this->flaggedLoginFailureIds($paginated->getCollection());
        $paginated->getCollection()->each(function (AuditLog $log) use ($flaggedIds) {
            $log->flagged = in_array($log->id, $flaggedIds, true);
        });

        return response()->json($paginated);
    }

    /**
     * "Flagged" = the 3rd or later failed login for the same account within
     * a 15-minute window — the actual signal someone's guessing a password,
     * as opposed to one person mistyping it once. Small table (only
     * login_failed rows), so one pass in PHP rather than a per-row subquery.
     */
    private function flaggedLoginFailureIds(Collection $page): array
    {
        $targets = $page->where('action', 'login_failed')->whereNotNull('user_id');
        if ($targets->isEmpty()) {
            return [];
        }
        $targetIds = array_fill_keys($targets->modelKeys(), true);

        // Only scan the time range and users relevant to the displayed page.
        // Include the preceding 15 minutes so page boundaries do not hide
        // earlier failures contributing to a flagged login attempt.
        $failures = AuditLog::where('action', 'login_failed')
            ->whereIn('user_id', $targets->pluck('user_id')->unique())
            ->whereBetween('created_at', [
                $targets->min('created_at')->copy()->subMinutes(15),
                $targets->max('created_at'),
            ])
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->select(['id', 'user_id', 'created_at'])
            ->cursor();

        $flagged = [];
        $window = [];
        $currentUser = null;
        foreach ($failures as $entry) {
            if ($entry->user_id !== $currentUser) {
                $window = [];
                $currentUser = $entry->user_id;
            }
            $timestamp = $entry->created_at->timestamp;
            $window = array_values(array_filter($window, fn ($previous) => $timestamp - $previous <= 900));
            $window[] = $timestamp;
            if (count($window) >= 3 && isset($targetIds[$entry->id])) {
                $flagged[] = $entry->id;
            }
        }

        return $flagged;
    }
}
