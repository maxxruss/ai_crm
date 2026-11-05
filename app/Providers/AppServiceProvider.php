<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\AiServiceInterface;
use App\Contracts\EmbeddingServiceInterface;
use App\Services\Ai\GigaChatService;
use App\Services\Ai\LocalEmbeddingService;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AiServiceInterface::class, GigaChatService::class);
        $this->app->bind(EmbeddingServiceInterface::class, LocalEmbeddingService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
