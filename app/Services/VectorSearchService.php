<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Pgvector\Vector;

final class VectorSearchService
{
    /**
     * Return the nearest ready document chunks without crossing tenant boundaries.
     *
     * @param  list<float>  $queryEmbedding
     * @return list<object>
     */
    public function search(int $tenantId, array $queryEmbedding, int $limit = 3): array
    {
        if ($tenantId < 1 || $limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('A valid tenant ID and result limit are required.');
        }

        if (count($queryEmbedding) !== (int) config('services.local_embeddings.dimensions', 768)) {
            throw new InvalidArgumentException('Query embedding has an unexpected dimension.');
        }

        $vector = (string) new Vector($queryEmbedding);

        return DB::select(
            <<<'SQL'
                SELECT
                    chunks.id,
                    chunks.document_id,
                    chunks.chunk_index,
                    chunks.content,
                    documents.title AS document_title,
                    chunks.embedding <=> ?::vector AS distance
                FROM document_chunks AS chunks
                INNER JOIN documents ON documents.id = chunks.document_id
                    AND documents.tenant_id = chunks.tenant_id
                WHERE chunks.tenant_id = ?
                    AND chunks.embedding IS NOT NULL
                    AND documents.index_status = 'ready'
                    AND documents.deleted_at IS NULL
                ORDER BY chunks.embedding <=> ?::vector
                LIMIT ?
            SQL,
            [$vector, $tenantId, $vector, $limit],
        );
    }
}
