<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RagChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ChatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenantForUser($request->user(), (int) $validated['tenant_id']);

        $conversations = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')
            ->limit(50)
            ->get(['id', 'title', 'updated_at']);

        return response()->json(['conversations' => $conversations]);
    }

    public function show(Request $request, int $conversationId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenantForUser($request->user(), (int) $validated['tenant_id']);
        $conversation = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $request->user()->id)
            ->findOrFail($conversationId);

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'updated_at' => $conversation->updated_at,
            ],
            'messages' => $conversation->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->where('status', 'completed')
                ->get(['id', 'role', 'content', 'citations', 'created_at'])
                ->map(fn (Message $message): array => $this->messagePayload($message))
                ->values(),
        ]);
    }

    public function ask(Request $request, RagChatService $chat): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'question' => ['required', 'string', 'max:2000'],
        ]);
        $tenant = $this->tenantForUser($request->user(), (int) $validated['tenant_id']);
        $conversation = null;

        if (! empty($validated['conversation_id'])) {
            $conversation = Conversation::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $request->user()->id)
                ->findOrFail((int) $validated['conversation_id']);
        }

        $history = $conversation
            ? $conversation->messages()
                ->whereIn('role', ['user', 'assistant'])
                ->where('status', 'completed')
                ->latest('id')
                ->limit(6)
                ->get(['role', 'content'])
                ->reverse()
                ->map(static fn (Message $message): array => [
                    'role' => $message->role,
                    'content' => $message->content,
                ])
                ->values()
                ->all()
            : [];

        try {
            $result = $chat->answer(
                $tenant->id,
                trim($validated['question']),
                $history,
            );
        } catch (Throwable $exception) {
            Log::warning('RAG chat request failed.', [
                'tenant_id' => $tenant->id,
                'exception_type' => $exception::class,
            ]);

            return response()->json([
                'message' => 'Не удалось сформировать ответ. Проверьте подключение к AI-сервису и попробуйте ещё раз.',
            ], 502);
        }

        [$conversation, $userMessage, $assistantMessage] = DB::transaction(
            function () use ($conversation, $tenant, $request, $validated, $result): array {
                if (! $conversation) {
                    $conversation = Conversation::query()->create([
                        'tenant_id' => $tenant->id,
                        'user_id' => $request->user()->id,
                        'title' => Str::limit(trim($validated['question']), 80, '…'),
                        'status' => 'active',
                    ]);
                } elseif ($conversation->title === null || $conversation->title === '') {
                    $conversation->update([
                        'title' => Str::limit(trim($validated['question']), 80, '…'),
                    ]);
                }

                $conversation->forceFill(['updated_at' => now()])->save();

                $userMessage = $conversation->messages()->create([
                    'tenant_id' => $tenant->id,
                    'role' => 'user',
                    'status' => 'completed',
                    'content' => trim($validated['question']),
                ]);
                $assistantMessage = $conversation->messages()->create([
                    'tenant_id' => $tenant->id,
                    'role' => 'assistant',
                    'status' => 'completed',
                    'content' => $result['answer'],
                    'citations' => $result['citations'],
                ]);

                return [$conversation, $userMessage, $assistantMessage];
            },
        );

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'updated_at' => $conversation->updated_at,
            ],
            'user_message' => $this->messagePayload($userMessage),
            'assistant_message' => $this->messagePayload($assistantMessage),
        ]);
    }

    private function tenantForUser(User $user, int $tenantId): Tenant
    {
        $tenant = $user->tenants()->where('tenants.id', $tenantId)->first();

        if (! $tenant) {
            abort(404);
        }

        return $tenant;
    }

    /**
     * @return array{id: int, role: string, content: string, citations: array, created_at: mixed}
     */
    private function messagePayload(Message $message): array
    {
        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'citations' => $message->citations ?? [],
            'created_at' => $message->created_at,
        ];
    }
}
