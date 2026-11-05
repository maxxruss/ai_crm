<?php

declare(strict_types=1);

namespace App\Contracts;

interface EmbeddingServiceInterface
{
    /**
     * Create a vector for a document chunk.
     *
     * @return list<float>
     */
    public function embedDocument(string $text): array;

    /**
     * Create a vector for a user query.
     *
     * @return list<float>
     */
    public function embedQuery(string $text): array;
}
