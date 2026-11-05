<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('status', 24)->default('active');
            $table->timestamps();
            $table->unique(['id', 'tenant_id'], 'conversations_id_tenant_id_unique');
            $table->index(['tenant_id', 'updated_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id');
            $table->foreignId('conversation_id');
            $table->string('role', 24);
            $table->string('status', 24)->default('completed');
            $table->longText('content');
            $table->jsonb('citations')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamps();

            $table->foreign(['conversation_id', 'tenant_id'])
                ->references(['id', 'tenant_id'])
                ->on('conversations')
                ->cascadeOnDelete();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
    }
};
