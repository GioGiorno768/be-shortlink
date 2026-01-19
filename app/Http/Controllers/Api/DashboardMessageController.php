<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardMessage;
use Illuminate\Http\Request;

class DashboardMessageController extends Controller
{
    // Admin: List all messages
    public function index()
    {
        $messages = DashboardMessage::latest()->get();
        return $this->successResponse($messages, 'Dashboard messages retrieved');
    }

    // Admin: Create a new message
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'link' => 'nullable|url',
            'button_label' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'theme_color' => 'nullable|string|max:50',
            'type' => 'required|in:important,event,latest,warning',
            'is_active' => 'boolean',
            'published_at' => 'nullable|date',
            'expired_at' => 'nullable|date|after:published_at',
        ]);

        $message = DashboardMessage::create($validated);

        return $this->successResponse($message, 'Message created successfully', 201);
    }

    // Admin: Update a message
    public function update(Request $request, $id)
    {
        $message = DashboardMessage::findOrFail($id);

        $validated = $request->validate([
            'title' => 'sometimes|string|max:255',
            'content' => 'sometimes|string',
            'link' => 'nullable|url',
            'button_label' => 'nullable|string|max:50',
            'icon' => 'nullable|string|max:50',
            'theme_color' => 'nullable|string|max:50',
            'type' => 'sometimes|in:important,event,latest,warning',
            'is_active' => 'boolean',
            'published_at' => 'nullable|date',
            'expired_at' => 'nullable|date|after:published_at',
        ]);

        $message->update($validated);

        return $this->successResponse($message, 'Message updated successfully');
    }

    // Admin: Delete a message
    public function destroy($id)
    {
        $message = DashboardMessage::findOrFail($id);
        $message->delete();

        return $this->successResponse(null, 'Message deleted successfully');
    }

    // User: Get active messages
    public function activeMessages()
    {
        $now = now();
        $messages = DashboardMessage::where('is_active', true)
            ->where(function ($query) use ($now) {
                $query->whereNull('published_at')
                      ->orWhere('published_at', '<=', $now);
            })
            ->where(function ($query) use ($now) {
                $query->whereNull('expired_at')
                      ->orWhere('expired_at', '>=', $now);
            })
            ->latest()
            ->get();
        return $this->successResponse($messages, 'Active messages retrieved');
    }
}
