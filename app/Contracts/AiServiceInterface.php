<?php

declare(strict_types=1);

namespace App\Contracts;

interface AiServiceInterface
{
    /**
     * Generate an answer grounded in the supplied context.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $history
     */
    public function generateAnswer(string $prompt, string $context, array $history = []): string;
}
