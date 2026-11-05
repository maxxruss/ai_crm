<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\AiServiceInterface;
use App\Contracts\EmbeddingServiceInterface;

final class RagChatService
{
    public function __construct(
        private readonly EmbeddingServiceInterface $embeddingService,
        private readonly VectorSearchService $vectorSearch,
        private readonly AiServiceInterface $aiService,
    ) {
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history
     * @return array{answer: string, citations: list<array{ref: string, document_id: int, document_title: string, chunk_index: int, excerpt: string, distance: float}>}
     */
    public function answer(int $tenantId, string $question, array $history = []): array
    {
        $query = $question;
        if (mb_strlen($question) < 100) {
            foreach (array_reverse($history) as $message) {
                if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
                    $query = mb_substr(trim($message['content']), -250)."\n".$question;
                    break;
                }
            }
        }

        $matches = $this->vectorSearch->search(
            $tenantId,
            $this->embeddingService->embedQuery($query),
            max(1, min(10, (int) config('services.rag.result_limit', 5))),
        );
        $maxDistance = (float) config('services.rag.max_cosine_distance', 0.55);
        $relevant = array_values(array_filter(
            $matches,
            static fn (object $match): bool => is_numeric($match->distance ?? null)
                && is_finite((float) $match->distance)
                && (float) $match->distance <= $maxDistance,
        ));

        if ($relevant === []) {
            return [
                'answer' => 'В проиндексированных документах не нашёл достаточно релевантной информации, чтобы уверенно ответить. Попробуйте уточнить вопрос или проверьте, что документ имеет статус «Готов».',
                'citations' => [],
            ];
        }

        $citations = [];
        $contextParts = [];
        foreach ($relevant as $index => $match) {
            $ref = 'S'.($index + 1);
            $title = (string) $match->document_title;
            $chunkIndex = (int) $match->chunk_index;
            $content = (string) $match->content;
            $distance = (float) $match->distance;

            $contextParts[] = sprintf(
                '[%s] Документ: %s; фрагмент: %d'."\n%s",
                $ref,
                $title,
                $chunkIndex + 1,
                $content,
            );
            $citations[] = [
                'ref' => $ref,
                'document_id' => (int) $match->document_id,
                'document_title' => $title,
                'chunk_index' => $chunkIndex,
                'excerpt' => mb_substr($content, 0, 420),
                'distance' => round($distance, 4),
            ];
        }

        return [
            'answer' => $this->aiService->generateAnswer(
                $question,
                implode("\n\n", $contextParts),
                $this->boundedHistory($history),
            ),
            'citations' => $citations,
        ];
    }

    /**
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history
     * @return list<array{role: 'user'|'assistant', content: string}>
     */
    private function boundedHistory(array $history): array
    {
        return array_values(array_map(
            static fn (array $message): array => [
                'role' => $message['role'],
                'content' => mb_substr($message['content'], 0, 1200),
            ],
            array_slice($history, -6),
        ));
    }
}
