<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = auth()->id();

        $query = Notification::forUser($userId)->latest();

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        return response()->json([
            'items'        => $query->limit(30)->get(),
            'unread_count' => Notification::forUser($userId)->unread()->count(),
        ]);
    }

    public function markRead(Notification $notification)
    {
        abort_if($notification->user_id && $notification->user_id !== auth()->id(), 403);

        $notification->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }

    public function markAllRead()
    {
        Notification::forUser(auth()->id())->unread()->update(['read_at' => now()]);

        return response()->json(['message' => 'ok']);
    }
}
