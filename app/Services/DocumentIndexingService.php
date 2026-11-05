<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\EmbeddingServiceInterface;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class DocumentIndexingService
{
    public function __construct(
        private readonly DocumentChunker $chunker,
        private readonly EmbeddingServiceInterface $embeddings,
    ) {
    }

    /**
     * Rebuild a document's chunks atomically. Existing chunks remain intact
     * until every replacement embedding has been created successfully.
     */
    public function index(Document $document): int
    {
        $hasPreviousIndex = $document->chunks()->whereNotNull('embedding')->exists();
        $document->forceFill(['index_status' => 'processing'])->save();

        try {
            $texts = $this->chunker->split($document->content);

            if ($texts === []) {
                throw new RuntimeException('Document has no indexable text.');
            }

            $rows = [];

            foreach ($texts as $index => $text) {
                $rows[] = [
                    'tenant_id' => $document->tenant_id,
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content' => $text,
                    'token_count' => null,
                    'embedding' => $this->embeddings->embedDocument($text),
                    'metadata' => ['characters' => mb_strlen($text)],
                ];
            }

            DB::transaction(function () use ($document, $rows): void {
                $document->chunks()->delete();

                // Use Eloquent so the pgvector cast serializes embedding arrays
                // into PostgreSQL's vector representation.
                foreach ($rows as $row) {
                    DocumentChunk::query()->create($row);
                }

                $document->forceFill(['index_status' => 'ready'])->save();
            });

            return count($rows);
        } catch (Throwable $exception) {
            $document->forceFill([
                'index_status' => $hasPreviousIndex ? 'ready' : 'failed',
            ])->save();

            throw $exception;
        }
    }
}
