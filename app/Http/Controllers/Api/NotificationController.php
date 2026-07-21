<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\SortsQueries;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    use SortsQueries;

    /**
     * The authenticated user's own notifications, newest first.
     *
     * Wrapped in the same data/links/meta shape as the products and orders
     * list endpoints, rather than Laravel's raw paginator shape, so API
     * clients handle one pagination format everywhere.
     */
    public function index(Request $request): JsonResponse
    {
        $notifications = $request->user()
            ->notifications()
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => $notifications->items(),
            'links' => [
                'first' => $notifications->url(1),
                'last' => $notifications->url($notifications->lastPage()),
                'prev' => $notifications->previousPageUrl(),
                'next' => $notifications->nextPageUrl(),
            ],
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
        ]);
    }

    /**
     * Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return response()->json(['message' => 'Notification marked as read.']);
    }
}
