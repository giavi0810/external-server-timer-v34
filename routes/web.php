<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\AdminSlaPolicyWebController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\LogMonitorController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Admin Log Monitor & Audit Routes
|--------------------------------------------------------------------------
*/
Route::prefix('admin')->group(function () {
    // Auth Routes
    Route::get('/login', [AdminAuthController::class, 'showLoginForm'])->name('admin.login');
    Route::post('/login', [AdminAuthController::class, 'login'])->name('admin.login.submit');
    Route::post('/logout', [AdminAuthController::class, 'logout'])->name('admin.logout');

    // Protected Admin Routes
    Route::middleware('admin.auth')->group(function () {
        Route::get('/dashboard', [LogMonitorController::class, 'dashboard'])->name('admin.dashboard');
        Route::get('/rocketchat-audit', [LogMonitorController::class, 'rocketchatAudit'])->name('admin.rocketchat_audit');
        Route::get('/rocketchat-audit/export', [LogMonitorController::class, 'exportRocketChatAudit'])->name('admin.rocketchat_audit.export');
        Route::post('/rocketchat-audit/{delivery_id}/retry', [LogMonitorController::class, 'retryRocketChatAudit'])->name('admin.rocketchat_audit.retry');
        Route::get('/system-logs', [LogMonitorController::class, 'systemLogs'])->name('admin.system_logs');
        Route::get('/system-logs/download', [LogMonitorController::class, 'downloadSystemLog'])->name('admin.system_logs.download');
        Route::get('/spool-files', [LogMonitorController::class, 'getSpoolFiles'])->name('admin.spool_files');
        Route::get('/spool-files/view', [LogMonitorController::class, 'readSpoolFileContent'])->name('admin.spool_files.view');

        Route::get('/sla-policies', [AdminSlaPolicyWebController::class, 'index'])->name('admin.sla-policies.index');
        Route::get('/sla-policies/{policy}/history', [AdminSlaPolicyWebController::class, 'history'])->name('admin.sla-policies.history');

        Route::middleware('admin.role:super_admin,sla_manager')->group(function () {
            Route::post('/sla-policies', [AdminSlaPolicyWebController::class, 'store'])->name('admin.sla-policies.store');
            Route::put('/sla-policies/{policy}', [AdminSlaPolicyWebController::class, 'update'])->name('admin.sla-policies.update');
        });

        Route::middleware('admin.role:super_admin')->group(function () {
            Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
            Route::post('/users', [AdminUserController::class, 'store'])->name('admin.users.store');
            Route::put('/users/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
            Route::delete('/users/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');
        });
    });
});
