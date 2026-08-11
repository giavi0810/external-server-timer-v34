<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RocketChatDeliveryStatus;
use App\Services\RocketChatService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

use Illuminate\Support\Facades\Redis;

class LogMonitorController extends Controller
{
    public function dashboard(Request $request)
    {
        // 1. RocketChat Spool Stats
        $auditRoot = config('rocketchat_audit.root', storage_path('app/rocketchat-audit'));
        $spoolCounts = [
            'pending' => count(glob(rtrim($auditRoot, '/\\').'/pending/*.json') ?: []),
            'ready' => count(glob(rtrim($auditRoot, '/\\').'/ready/*.json') ?: []),
            'processing' => count(glob(rtrim($auditRoot, '/\\').'/processing/*.json') ?: []),
            'temporary' => count(glob(rtrim($auditRoot, '/\\').'/temporary/*.tmp') ?: []),
        ];

        // 1b. Freshdesk Webhook Spool Stats
        $freshdeskSpoolRoot = storage_path('app/freshdesk-spool');
        $freshdeskSpoolCounts = [
            'temporary' => File::exists($freshdeskSpoolRoot.'/temporary') ? count(File::files($freshdeskSpoolRoot.'/temporary')) : 0,
            'ready' => File::exists($freshdeskSpoolRoot.'/ready') ? count(File::allFiles($freshdeskSpoolRoot.'/ready')) : 0,
            'enqueued' => File::exists($freshdeskSpoolRoot.'/enqueued') ? count(File::files($freshdeskSpoolRoot.'/enqueued')) : 0,
            'processing' => File::exists($freshdeskSpoolRoot.'/processing') ? count(File::files($freshdeskSpoolRoot.'/processing')) : 0,
            'committed-gc' => File::exists($freshdeskSpoolRoot.'/committed-gc') ? count(File::files($freshdeskSpoolRoot.'/committed-gc')) : 0,
            'quarantine' => File::exists($freshdeskSpoolRoot.'/quarantine') ? count(File::files($freshdeskSpoolRoot.'/quarantine')) : 0,
        ];

        // 3. Database & Services Health Check (Fast check first)
        $dbStatus = $request->attributes->get('admin_auth_degraded', false)
            ? 'Error: '.$request->attributes->get('admin_database_error', 'PostgreSQL is unavailable.')
            : 'OK';

        if ($dbStatus === 'OK') {
            try {
                DB::connection()->getPdo();
            } catch (Throwable $e) {
                $dbStatus = 'Error: '.$e->getMessage();
            }
        }

        $redisStatus = 'OK';
        try {
            Redis::connection('health')->ping();
        } catch (Throwable $e) {
            $redisStatus = 'Error: '.$e->getMessage();
        }

        // 2. RocketChat Delivery Status Stats (24h) - Only query if DB is connected
        $deliveryStats = null;
        $recentAuditLogs = collect();

        if ($dbStatus === 'OK') {
            try {
                $since24h = Carbon::now()->subHours(24);
                $deliveryStats = [
                    'total_24h' => RocketChatDeliveryStatus::query()->where('attempted_at', '>=', $since24h)->count(),
                    'success_24h' => RocketChatDeliveryStatus::query()->where('attempted_at', '>=', $since24h)->where('status', RocketChatDeliveryStatus::STATUS_SUCCESS)->count(),
                    'failed_24h' => RocketChatDeliveryStatus::query()->where('attempted_at', '>=', $since24h)->where('status', RocketChatDeliveryStatus::STATUS_FAILED)->count(),
                    'unknown_24h' => RocketChatDeliveryStatus::query()->where('attempted_at', '>=', $since24h)->where('status', RocketChatDeliveryStatus::STATUS_UNKNOWN)->count(),
                ];

                $recentAuditLogs = RocketChatDeliveryStatus::query()
                    ->orderBy('attempted_at', 'desc')
                    ->limit(10)
                    ->get();
            } catch (Throwable $e) {
                $dbStatus = 'Error: '.$e->getMessage();
            }
        }

        // 4. Log Files Stats (Disk-based, independent of DB)
        $logPath = storage_path('logs');
        $logFiles = [];
        if (File::exists($logPath)) {
            $files = File::files($logPath);
            foreach ($files as $file) {
                if ($file->getExtension() === 'log') {
                    $logFiles[] = [
                        'name' => $file->getFilename(),
                        'size' => number_format($file->getSize() / 1024, 2).' KB',
                        'updated_at' => Carbon::createFromTimestamp($file->getMTime())->format('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        return view('admin.dashboard', compact('spoolCounts', 'freshdeskSpoolCounts', 'deliveryStats', 'dbStatus', 'redisStatus', 'logFiles', 'recentAuditLogs'));
    }

    public function rocketchatAudit(Request $request)
    {
        $dbError = null;
        $logs = collect();
        $eventCodes = collect();

        try {
            DB::connection()->getPdo();
            
            $query = RocketChatDeliveryStatus::query();

            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }

            if ($request->filled('event_code')) {
                $query->where('event_code', $request->input('event_code'));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('delivery_id', 'like', "%{$search}%")
                        ->orWhere('rocketchat_message_id', 'like', "%{$search}%");
                });
            }

            if ($request->filled('date_from')) {
                $query->where('attempted_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
            }

            if ($request->filled('date_to')) {
                $query->where('attempted_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
            }

            $logs = $query->orderBy('attempted_at', 'desc')->paginate(20)->withQueryString();

            $eventCodes = RocketChatDeliveryStatus::query()
                ->select('event_code')
                ->distinct()
                ->pluck('event_code');
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }

        return view('admin.rocketchat_audit', compact('logs', 'eventCodes', 'dbError'));
    }

    public function retryRocketChatAudit(string $deliveryId, RocketChatService $rocketChatService)
    {
        $record = RocketChatDeliveryStatus::query()->where('delivery_id', $deliveryId)->firstOrFail();

        try {
            $text = "🔄 [RETRY DISPATCH] Thử gửi lại thông báo cho sự cố: {$record->event_code}\n"
                ."- **Delivery ID:** `{$record->delivery_id}`\n"
                ."- **Lần gửi ban đầu:** {$record->attempted_at}";

            $sent = $rocketChatService->sendMessage($text, [
                'color' => '#36a64f',
                'title' => 'Retry Audit Dispatch',
                'text' => "Original Event Code: {$record->event_code}",
            ], $record->event_code);

            if ($sent) {
                return back()->with('success', 'Đã thực hiện gửi lại thông báo Rocket.Chat thành công!');
            }

            return back()->with('error', 'Thử gửi lại thất bại. Vui lòng kiểm tra lại dịch vụ Rocket.Chat.');
        } catch (Throwable $e) {
            return back()->with('error', 'Có lỗi xảy ra khi thử gửi lại: '.$e->getMessage());
        }
    }

    public function systemLogs(Request $request)
    {
        $logPath = storage_path('logs');
        $files = [];

        if (File::exists($logPath)) {
            foreach (File::files($logPath) as $file) {
                if ($file->getExtension() === 'log') {
                    $files[] = $file->getFilename();
                }
            }
        }

        rsort($files);

        $defaultFile = ! empty($files) ? $files[0] : 'laravel.log';
        $selectedFile = $request->input('file', $defaultFile);
        $hours = (int) $request->input('hours', 6);

        if (! in_array($selectedFile, $files, true) && ! empty($files)) {
            $selectedFile = $files[0];
        }

        $logContent = [];
        $fullPath = $logPath.DIRECTORY_SEPARATOR.$selectedFile;

        if (File::exists($fullPath)) {
            $fileSize = filesize($fullPath);
            $rawLines = [];

            if ($fileSize > 10 * 1024 * 1024) {
                $bytesToRead = 5 * 1024 * 1024;
                if ($hours >= 12 || $hours === 0) {
                    $bytesToRead = 15 * 1024 * 1024;
                }

                $fp = fopen($fullPath, 'r');
                if ($fp) {
                    $offset = max(0, $fileSize - $bytesToRead);
                    fseek($fp, $offset);

                    if ($offset > 0) {
                        fgets($fp);
                    }

                    while (($line = fgets($fp)) !== false) {
                        $line = trim($line, "\r\n");
                        if ($line !== '') {
                            $rawLines[] = $line;
                        }
                    }
                    fclose($fp);
                }
            } else {
                $rawLines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            }

            $cutoff = $hours > 0 ? Carbon::now()->subHours($hours) : null;
            $filteredLines = [];
            $keepCurrentBlock = true;

            foreach ($rawLines as $line) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                    if ($cutoff === null) {
                        $keepCurrentBlock = true;
                    } else {
                        try {
                            $lineTime = Carbon::parse($matches[1]);
                            $keepCurrentBlock = $lineTime->gte($cutoff);
                        } catch (Throwable $e) {
                            $keepCurrentBlock = true;
                        }
                    }
                }

                if ($keepCurrentBlock) {
                    $filteredLines[] = $line;
                }
            }

            $logContent = array_reverse($filteredLines);
        }

        return view('admin.system_logs', compact('files', 'selectedFile', 'logContent', 'hours'));
    }

    public function downloadSystemLog(Request $request)
    {
        $fileName = basename($request->input('file', 'laravel.log'));
        $fullPath = storage_path('logs').DIRECTORY_SEPARATOR.$fileName;

        if (File::exists($fullPath)) {
            return response()->download($fullPath);
        }

        return back()->with('error', 'File log không tồn tại.');
    }

    public function exportRocketChatAudit(Request $request)
    {
        $query = RocketChatDeliveryStatus::query();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('event_code')) {
            $query->where('event_code', $request->input('event_code'));
        }
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('delivery_id', 'like', "%{$search}%")
                    ->orWhere('rocketchat_message_id', 'like', "%{$search}%");
            });
        }
        if ($request->filled('date_from')) {
            $query->where('attempted_at', '>=', Carbon::parse($request->input('date_from'))->startOfDay());
        }
        if ($request->filled('date_to')) {
            $query->where('attempted_at', '<=', Carbon::parse($request->input('date_to'))->endOfDay());
        }

        $logs = $query->orderBy('attempted_at', 'desc')->get();
        $fileName = 'rocketchat_audit_export_'.date('Ymd_His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$fileName}\"",
        ];

        $callback = function () use ($logs) {
            $file = fopen('php://output', 'w');
            fputs($file, "\xEF\xBB\xBF"); // UTF-8 BOM

            fputcsv($file, [
                'Mã Lượt Gửi (Delivery ID)',
                'Loại Sự Cố (Event Code)',
                'Trạng Thái',
                'Mã HTTP',
                'Mã Tin Nhắn (Message ID)',
                'Số Lần Thử',
                'Thời Gian Gửi',
                'Thông Báo Lỗi',
            ]);

            foreach ($logs as $log) {
                fputcsv($file, [
                    $log->delivery_id,
                    $log->event_code,
                    $log->status,
                    $log->http_status ?? '',
                    $log->rocketchat_message_id ?? '',
                    $log->attempt_count,
                    $log->formatted_attempted_at,
                    $log->error_message ?? '',
                ]);
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    public function getSpoolFiles(Request $request)
    {
        $type = strtolower($request->input('type', 'rocketchat'));
        $folder = strtolower($request->input('folder', 'ready'));

        if ($type === 'freshdesk') {
            $spoolRoot = storage_path('app/freshdesk-spool');
            $allowedFolders = ['ready', 'processing', 'enqueued', 'temporary', 'committed-gc', 'quarantine'];
        } else {
            $spoolRoot = config('rocketchat_audit.root', storage_path('app/rocketchat-audit'));
            $allowedFolders = ['ready', 'processing', 'pending', 'temporary'];
        }

        if (!in_array($folder, $allowedFolders, true)) {
            $folder = 'ready';
        }

        $targetDir = rtrim($spoolRoot, '/\\') . DIRECTORY_SEPARATOR . $folder;

        $filesList = [];
        if (File::exists($targetDir)) {
            $allFiles = ($type === 'freshdesk' && $folder === 'ready') ? File::allFiles($targetDir) : File::files($targetDir);
            foreach ($allFiles as $file) {
                $relativePath = str_replace(rtrim($spoolRoot, '/\\') . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $filesList[] = [
                    'name' => $file->getFilename(),
                    'path' => str_replace('\\', '/', $relativePath),
                    'size' => number_format($file->getSize() / 1024, 2) . ' KB',
                    'updated_at' => Carbon::createFromTimestamp($file->getMTime())->format('Y-m-d H:i:s'),
                ];
            }
            usort($filesList, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
        }

        return response()->json([
            'type' => $type,
            'folder' => $folder,
            'count' => count($filesList),
            'files' => array_slice($filesList, 0, 100),
        ]);
    }

    public function readSpoolFileContent(Request $request)
    {
        $filePathParam = $request->input('path');
        $type = strtolower($request->input('type', 'rocketchat'));

        if (!$filePathParam) {
            return response()->json(['error' => 'Thiếu tham số path'], 400);
        }

        if ($type === 'freshdesk') {
            $spoolRoot = storage_path('app/freshdesk-spool');
        } else {
            $spoolRoot = config('rocketchat_audit.root', storage_path('app/rocketchat-audit'));
        }

        $normalizedPath = str_replace(['..', '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $filePathParam);
        $fullPath = rtrim($spoolRoot, '/\\') . DIRECTORY_SEPARATOR . $normalizedPath;

        if (!File::exists($fullPath)) {
            return response()->json(['error' => 'File không tồn tại hoặc đã được đồng bộ/xóa.'], 404);
        }

        $content = File::get($fullPath);
        $jsonDecoded = json_decode($content, true);

        return response()->json([
            'filename' => basename($fullPath),
            'raw_content' => $content,
            'parsed' => $jsonDecoded ?? null,
        ]);
    }
}
