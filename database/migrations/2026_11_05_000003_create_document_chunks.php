<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('document_id');
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('token_count')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->foreign(['document_id', 'tenant_id'])
                ->references(['id', 'tenant_id'])
                ->on('documents')
                ->cascadeOnDelete();
            $table->unique(['document_id', 'chunk_index']);
            $table->index(['tenant_id', 'document_id']);
        });

        // This dimension is fixed to intfloat/multilingual-e5-base.
        DB::statement('ALTER TABLE document_chunks ADD COLUMN embedding vector(768)');
        DB::statement(
            'CREATE INDEX document_chunks_embedding_cosine_hnsw '
            .'ON document_chunks USING hnsw (embedding vector_cosine_ops)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
