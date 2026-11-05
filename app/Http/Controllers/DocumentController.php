<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\IndexDocumentJob;
use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Documents\DocumentExtractionException;
use App\Services\Documents\DocumentTextExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenantForUser($request->user(), (int) $validated['tenant_id']);

        $documents = Document::query()
            ->where('tenant_id', $tenant->id)
            ->withCount(['chunks as chunk_count' => fn ($query) => $query->whereNotNull('embedding')])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get([
                'id',
                'title',
                'source_type',
                'index_status',
                'checksum',
                'metadata',
                'created_at',
                'updated_at',
            ]);

        return response()->json(['documents' => $documents]);
    }

    public function store(Request $request, DocumentTextExtractor $textExtractor): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
            'file' => ['required', 'file', 'max:10240', 'extensions:txt,md,pdf,docx'],
            'title' => ['nullable', 'string', 'max:255'],
        ]);

        $tenant = $this->tenantForUser(
            $request->user(),
            (int) $validated['tenant_id'],
            requireManager: true,
        );
        /** @var UploadedFile $file */
        $file = $validated['file'];
        try {
            $content = $textExtractor->extract($file);
        } catch (DocumentExtractionException $exception) {
            throw ValidationException::withMessages([
                'file' => [$exception->getMessage()],
            ]);
        }

        if (trim($content) === '' || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            throw ValidationException::withMessages([
                'file' => ['Не удалось извлечь из файла непустой UTF-8 текст.'],
            ]);
        }

        $filename = basename($file->getClientOriginalName());
        $title = trim((string) ($validated['title'] ?? '')) ?: pathinfo($filename, PATHINFO_FILENAME);

        $document = DB::transaction(function () use ($request, $tenant, $file, $filename, $title, $content): Document {
            $document = Document::query()->create([
                'tenant_id' => $tenant->id,
                'uploaded_by' => $request->user()->id,
                'title' => $title,
                'content' => $content,
                'source_type' => 'upload',
                'source_id' => (string) Str::uuid(),
                'index_status' => 'queued',
                'checksum' => hash('sha256', $content),
                'metadata' => [
                    'original_filename' => $filename,
                    'mime_type' => $file->getMimeType(),
                ],
            ]);

            IndexDocumentJob::dispatch($document->id)->afterCommit();

            return $document;
        });

        return response()->json([
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'index_status' => $document->index_status,
            ],
        ], 202);
    }

    public function retry(Request $request, int $documentId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenantForUser(
            $request->user(),
            (int) $validated['tenant_id'],
            requireManager: true,
        );
        $document = Document::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($documentId);

        if ($document->index_status === 'processing') {
            return response()->json(['message' => 'Документ уже индексируется.'], 409);
        }

        $document->update(['index_status' => 'queued']);
        IndexDocumentJob::dispatch($document->id);

        return response()->json(['document_id' => $document->id, 'index_status' => 'queued'], 202);
    }

    public function destroy(Request $request, int $documentId): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'integer', 'min:1'],
        ]);
        $tenant = $this->tenantForUser(
            $request->user(),
            (int) $validated['tenant_id'],
            requireManager: true,
        );
        $document = Document::query()
            ->where('tenant_id', $tenant->id)
            ->findOrFail($documentId);

        // Hard delete cascades to chunks and removes vectors from future retrieval.
        $document->forceDelete();

        return response()->json(['deleted' => true]);
    }

    private function tenantForUser(User $user, int $tenantId, bool $requireManager = false): Tenant
    {
        $tenant = $user->tenants()->where('tenants.id', $tenantId)->first();

        if (! $tenant) {
            abort(404);
        }

        if ($requireManager && ! in_array($tenant->pivot->role, ['owner', 'admin'], true)) {
            abort(403, 'Недостаточно прав для управления документами.');
        }

        return $tenant;
    }
}
