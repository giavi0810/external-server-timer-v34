<?php

use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\SlaPolicyController;
use App\Http\Controllers\WebhookController;
use App\Models\FreshdeskGroup;
use App\Services\FreshdeskApiService;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthCheckController::class, 'check']);
Route::get('/health/db', [HealthCheckController::class, 'checkDb']);

Route::prefix('webhooks')->middleware('auth.basic.fd')->group(function () {
    Route::post('/freshdesk', [WebhookController::class, 'handleFreshdeskTicketEvent']);
    Route::post('/batch', [WebhookController::class, 'handleBatchEvents']);
});

Route::prefix('admin')->middleware('auth.basic.fd')->group(function () {
    Route::post('/refresh-groups', function () {
        if (class_exists(FreshdeskApiService::class)) {
            app(FreshdeskApiService::class)->refreshGroupMappings();
        }

        return response()->json([
            'message' => 'Group mappings refreshed successfully',
            'groups' => FreshdeskGroup::all(['group_id', 'name', 'main_layer', 'is_active'])->toArray(),
        ]);
    });

    Route::get('/sla-policies', [SlaPolicyController::class, 'index']);
    Route::get('/sla-policies/{id}', [SlaPolicyController::class, 'show']);
    Route::post('/sla-policies', [SlaPolicyController::class, 'store']);
    Route::put('/sla-policies/{id}', [SlaPolicyController::class, 'update']);
    Route::delete('/sla-policies/{id}', [SlaPolicyController::class, 'destroy']);
    Route::get('/sla-policies/{id}/history', [SlaPolicyController::class, 'history']);
});
