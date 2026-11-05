<?php

use App\Contracts\AiServiceInterface;
use App\Contracts\EmbeddingServiceInterface;
use App\Jobs\IndexDocumentJob;
use App\Models\Tenant;
use App\Models\User;
use App\Services\VectorSearchService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

Artisan::command('about:rag', function (): void {
    $this->comment('Smart RAG Knowledge Base application scaffold is ready.');
})->purpose('Show Smart RAG application status');

Artisan::command('gigachat:check', function (): int {
    $ai = app(AiServiceInterface::class);
    $embeddings = app(EmbeddingServiceInterface::class);
    $hasErrors = false;

    try {
        $embedding = $embeddings->embedQuery('Проверка локальной модели эмбеддингов.');
        $this->info(sprintf('Local embeddings: OK (%d dimensions).', count($embedding)));
    } catch (\Throwable $exception) {
        $hasErrors = true;
        $this->error(sprintf(
            'Local embeddings failed: %s: %s',
            $exception::class,
            $exception->getMessage(),
        ));
    }

    try {
        $answer = $ai->generateAnswer(
            'Ответь одним словом: OK.',
            'Проверка соединения успешно выполнена.',
        );

        $this->info('Chat API: OK.');
        $this->line($answer);
    } catch (\Throwable $exception) {
        $hasErrors = true;
        $this->error(sprintf(
            'Chat API failed: %s: %s',
            $exception::class,
            $exception->getMessage(),
        ));
    }

    return $hasErrors ? self::FAILURE : self::SUCCESS;
})->purpose('Verify local embeddings and GigaChat chat access');

Artisan::command('documents:index {documentId}', function (int $documentId): int {
    IndexDocumentJob::dispatch($documentId);
    $this->info("Document {$documentId} queued for indexing.");

    return self::SUCCESS;
})->purpose('Queue document chunking and local embedding generation');

Artisan::command('rag:search {tenantId} {question} {--limit=3}', function (
    int $tenantId,
    string $question,
    EmbeddingServiceInterface $embeddings,
    VectorSearchService $search,
): int {
    $matches = $search->search(
        $tenantId,
        $embeddings->embedQuery($question),
        (int) $this->option('limit'),
    );

    foreach ($matches as $match) {
        $this->line(sprintf(
            '[distance %.4f] %s — %s',
            (float) $match->distance,
            $match->document_title,
            $match->content,
        ));
    }

    if ($matches === []) {
        $this->comment('No indexed chunks found for this tenant.');
    }

    return self::SUCCESS;
})->purpose('Run a tenant-scoped semantic search from the command line');

Artisan::command('workspace:admin {email} {--name=Administrator} {--tenant=Smart RAG}', function (
    string $email,
): int {
    $email = strtolower(trim($email));
    $tenantName = trim((string) $this->option('tenant'));

    if (! filter_var($email, FILTER_VALIDATE_EMAIL) || $tenantName === '') {
        $this->error('Provide a valid email and non-empty tenant name.');

        return self::INVALID;
    }

    $password = null;
    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $password = $this->secret('Admin password (minimum 12 characters)');
        $confirmation = $this->secret('Confirm password');

        if (! is_string($password) || mb_strlen($password) < 12 || $password !== $confirmation) {
            $this->error('Passwords must match and contain at least 12 characters.');

            return self::INVALID;
        }
    }

    DB::transaction(function () use ($email, $tenantName, $user, $password): void {
        $slug = Str::slug($tenantName) ?: 'workspace-'.Str::lower(Str::random(8));
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => $tenantName],
        );

        $user ??= User::query()->create([
            'name' => trim((string) $this->option('name')) ?: 'Administrator',
            'email' => $email,
            'password' => $password,
        ]);

        if ($user->tenants()->whereKey($tenant->id)->exists()) {
            $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'owner']);
        } else {
            $user->tenants()->attach($tenant->id, ['role' => 'owner']);
        }
    });

    $this->info("Owner access configured for {$email}.");

    return self::SUCCESS;
})->purpose('Create a first owner account and workspace interactively');
