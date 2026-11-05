<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Deterministic paragraph/sentence chunking with a small character overlap.
 *
 * Character limits are kept conservative because the local E5 model accepts
 * at most 512 tokens per input; later we can replace this with tokenizer-aware
 * chunking without changing indexing or retrieval.
 */
final class DocumentChunker
{
    public function __construct(
        private readonly int $maxCharacters = 1100,
        private readonly int $overlapCharacters = 120,
    ) {
    }

    /**
     * @return list<string>
     */
    public function split(string $content): array
    {
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $content = preg_replace('/[ \t]+/u', ' ', $content) ?? $content;
        $content = trim($content);

        if ($content === '') {
            return [];
        }

        $paragraphs = preg_split('/\n{2,}/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];

        foreach ($paragraphs as $paragraph) {
            $segments = [...$segments, ...$this->splitParagraph(trim($paragraph))];
        }

        $chunks = [];
        $current = '';

        foreach ($segments as $segment) {
            $candidate = $current === '' ? $segment : $current."\n".$segment;

            if ($current !== '' && mb_strlen($candidate) > $this->maxCharacters) {
                $chunks[] = $current;

                $tail = mb_substr($current, -$this->overlapCharacters);
                $withOverlap = trim($tail."\n".$segment);
                $current = mb_strlen($withOverlap) <= $this->maxCharacters
                    ? $withOverlap
                    : $segment;

                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return array_values(array_filter($chunks, static fn (string $chunk): bool => trim($chunk) !== ''));
    }

    /**
     * @return list<string>
     */
    private function splitParagraph(string $paragraph): array
    {
        if (mb_strlen($paragraph) <= $this->maxCharacters) {
            return [$paragraph];
        }

        $sentences = preg_split('/(?<=[.!?։])\s+/u', $paragraph, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);

            if (mb_strlen($sentence) <= $this->maxCharacters) {
                $segments[] = $sentence;
                continue;
            }

            // Avoid cutting UTF-8 codepoints when a paragraph has no sentence boundaries.
            for ($offset = 0, $length = mb_strlen($sentence); $offset < $length; $offset += $this->maxCharacters) {
                $segments[] = trim(mb_substr($sentence, $offset, $this->maxCharacters));
            }
        }

        return $segments;
    }
}
