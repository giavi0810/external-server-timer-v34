<?php

use App\Http\Controllers\WebhookController;
use App\Models\FreshdeskGroup;
use App\Services\FreshdeskApiService;
use Illuminate\Support\Facades\Route;

Route::prefix('webhooks')->middleware('auth.basic.fd')->group(function () {
    Route::post('/freshdesk', [WebhookController::class, 'handleFreshdeskTicketEvent']);
    Route::post('/batch', [WebhookController::class, 'handleBatchEvents']);
});

Route::post('/admin/refresh-groups', function () {
    if (class_exists(FreshdeskApiService::class)) {
        app(FreshdeskApiService::class)->refreshGroupMappings();
    }

    return response()->json([
        'message' => 'Group mappings refreshed successfully',
        'groups' => FreshdeskGroup::all(['group_id', 'name', 'main_layer', 'is_active'])->toArray(),
    ]);
})->middleware('auth.basic.fd');
