<?php

namespace Modules\Foundation\Notification\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Notification\Models\AppNotification;
use App\Http\Controllers\Controller;

class NotificationController extends Controller
{
    /**
     * Render the Notification Center page.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index');
    }

    /**
     * API: Get notifications for the current user and active property.
     */
    public function getNotifications(Request $request): JsonResponse
    {
        $propertyId = $request->header('X-Property-ID') ?? $request->cookie('active_property_id');
        
        $notifications = AppNotification::where('user_id', $request->user()->id)
            ->when($propertyId, function ($query, $propertyId) {
                return $query->where('property_id', $propertyId);
            })
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 15));

        return response()->json($notifications);
    }

    /**
     * API: Get unread count.
     */
    public function getUnreadCount(Request $request): JsonResponse
    {
        $propertyId = $request->header('X-Property-ID') ?? $request->cookie('active_property_id');
        
        $count = AppNotification::where('user_id', $request->user()->id)
            ->when($propertyId, function ($query, $propertyId) {
                return $query->where('property_id', $propertyId);
            })
            ->where('is_read', false)
            ->count();

        return response()->json(['count' => $count]);
    }

    /**
     * API: Mark a single notification as read.
     */
    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = AppNotification::where('user_id', $request->user()->id)
            ->findOrFail($id);
            
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * API: Mark all notifications as read for current property/user.
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        $propertyId = $request->header('X-Property-ID') ?? $request->cookie('active_property_id');
        
        AppNotification::where('user_id', $request->user()->id)
            ->when($propertyId, function ($query, $propertyId) {
                return $query->where('property_id', $propertyId);
            })
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return response()->json(['success' => true]);
    }
}
