<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

class NotificationController extends Controller
{
    public function index()
    {
        return auth()->user()->notifications()
            ->latest()
            ->paginate(15)
            ->through(function ($n) {
                return [
                    'id'         => $n->id,
                    'type'       => class_basename($n->type),
                    'data'       => $n->data,
                    'created_at' => $n->created_at->toDateTimeString(),
                    'read_at'    => $n->read_at?->toDateTimeString(),
                ];
            });
    }

    public function unread()
    {
        return Auth::user()->unreadNotifications
            ->map(fn($n) => [
                'id'         => $n->id,
                'type'       => class_basename($n->type),
                'data'       => $n->data,
                'created_at' => $n->created_at->toDateTimeString(),
                'read_at'    => $n->read_at,
            ]);
    }

    public function markAllRead()
    {
        Auth::user()->unreadNotifications->markAsRead();
        return response()->json();
    }

    public function markRead(string $id)
    {
        $notification = Auth::user()
            ->notifications()
            ->where('id', $id)
            ->firstOrFail();

        $notification->markAsRead();

        return response()->json([
            'id'      => $notification->id,
            'read_at' => $notification->read_at->toDateTimeString(),
        ]);
    }
}
