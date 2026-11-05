<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentIndexingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class IndexDocumentJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 90];

    public function __construct(public readonly int $documentId)
    {
    }

    public function handle(DocumentIndexingService $indexing): void
    {
        $document = Document::query()->findOrFail($this->documentId);
        $indexing->index($document);
    }
}
