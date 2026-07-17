<?php

namespace Webkul\Whatsapp\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Webkul\Whatsapp\Models\QuickReplyProxy;

/**
 * Canned responses for the inbox composer. A reply is either global
 * (user_id null, whole team) or personal (owned by one advisor).
 */
class QuickReplyController extends Controller
{
    /**
     * Replies available to the current advisor: globals + their own.
     */
    public function index(): JsonResponse
    {
        $replies = QuickReplyProxy::modelClass()::query()
            ->visibleTo($this->userId())
            ->orderBy('shortcut')
            ->get();

        return response()->json(['quick_replies' => $replies->map(fn ($reply) => $this->transform($reply))]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if ($this->shortcutTaken($data['shortcut'])) {
            return response()->json(['message' => "Ya existe una respuesta con el atajo /{$data['shortcut']}."], 422);
        }

        $reply = QuickReplyProxy::modelClass()::create($data + [
            'user_id' => $request->boolean('is_global') ? null : $this->userId(),
        ]);

        return response()->json([
            'quick_reply' => $this->transform($reply),
            'message'     => 'Respuesta rápida creada.',
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $reply = $this->findVisible($id);

        $data = $this->validated($request);

        if ($this->shortcutTaken($data['shortcut'], (int) $reply->id)) {
            return response()->json(['message' => "Ya existe una respuesta con el atajo /{$data['shortcut']}."], 422);
        }

        $reply->update($data + [
            'user_id' => $request->boolean('is_global') ? null : $this->userId(),
        ]);

        return response()->json([
            'quick_reply' => $this->transform($reply),
            'message'     => 'Respuesta rápida actualizada.',
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $this->findVisible($id)->delete();

        return response()->json(['message' => 'Respuesta rápida eliminada.']);
    }

    /**
     * Personal replies of other advisors are invisible — also not editable.
     */
    protected function findVisible(string $id)
    {
        return QuickReplyProxy::modelClass()::query()
            ->visibleTo($this->userId())
            ->findOrFail($id);
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'shortcut' => 'required|string|max:50',
            'title'    => 'nullable|string|max:120',
            'content'  => 'required|string',
        ]);
    }

    /**
     * A shortcut must be unique among the replies the user can see
     * (their own + the team's globals), or the "/" picker gets ambiguous.
     */
    protected function shortcutTaken(string $shortcut, ?int $ignoreId = null): bool
    {
        return QuickReplyProxy::modelClass()::query()
            ->visibleTo($this->userId())
            ->where('shortcut', $shortcut)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();
    }

    protected function userId(): int
    {
        return (int) auth()->guard('user')->id();
    }

    protected function transform($reply): array
    {
        return [
            'id'        => $reply->id,
            'shortcut'  => $reply->shortcut,
            'title'     => $reply->title,
            'content'   => $reply->content,
            'is_global' => $reply->user_id === null,
            'is_mine'   => $reply->user_id === $this->userId(),
        ];
    }
}
