<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\DocumentController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'app');

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/api/session', [AuthController::class, 'session']);

    Route::prefix('/api')->group(function (): void {
        Route::get('/conversations', [ChatController::class, 'index']);
        Route::get('/conversations/{conversationId}', [ChatController::class, 'show'])
            ->whereNumber('conversationId');
        Route::post('/chat', [ChatController::class, 'ask'])->middleware('throttle:10,1');
        Route::get('/documents', [DocumentController::class, 'index']);
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::post('/documents/{documentId}/retry', [DocumentController::class, 'retry'])
            ->whereNumber('documentId');
        Route::delete('/documents/{documentId}', [DocumentController::class, 'destroy'])
            ->whereNumber('documentId');
    });
});
