<?php

use App\Http\Controllers\Admin\AdminAuthController;
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
    });
});

