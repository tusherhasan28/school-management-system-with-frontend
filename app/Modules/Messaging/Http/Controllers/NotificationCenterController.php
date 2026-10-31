<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

/**
 * The signed-in user's own in-app notifications. Rows are always read
 * through the user, never by id alone.
 */
class NotificationCenterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $items = $request->user()->notifications()->latest()->limit(30)->get()->map(fn (DatabaseNotification $n) => [
            'id' => $n->id,
            'title' => __((string) ($n->data['title'] ?? ''), $n->data['params'] ?? []),
            'body' => isset($n->data['body']) ? __((string) $n->data['body'], $n->data['params'] ?? []) : null,
            // Only same-site paths are followed (no open redirects through notification data).
            'url' => is_string($n->data['url'] ?? null) && str_starts_with($n->data['url'], '/') && ! str_starts_with($n->data['url'], '//') ? $n->data['url'] : null,
            'read' => $n->read_at !== null,
            'created_at' => $n->created_at?->toIso8601String(),
        ]);

        return response()->json(['items' => $items, 'unread' => $request->user()->unreadNotifications()->count()]);
    }

    public function read(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['id' => ['nullable', 'uuid']]);
        $query = $request->user()->unreadNotifications();

        if (isset($data['id'])) {
            $query->whereKey($data['id']);
        }

        $query->update(['read_at' => now()]);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
