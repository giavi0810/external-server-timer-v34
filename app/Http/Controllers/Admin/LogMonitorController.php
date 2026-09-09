<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RocketChatDeliveryStatus;
use App\Services\Admin\SystemLogReader;
use App\Services\RocketChatService;
use App\Services\Webhooks\DurableWebhookSpool;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Throwable;

class LogMonitorController extends Controller
{
    public function dashboard(Request $request, DurableWebhookSpool $freshdeskSpool)
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
        $freshdeskSpoolRoot = rtrim((string) config('freshdesk_spool.root'), '/\\');
        $freshdeskSpoolCounts = [];
        foreach (DurableWebhookSpool::STATES as $state) {
            $freshdeskSpoolCounts[$state] = $freshdeskSpool->countStateFiles($state);
        }

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
                        'display_name' => $this->logDisplayName($file->getFilename()),
                        'size' => number_format($file->getSize() / 1024, 2).' KB',
                        'updated_at' => Carbon::createFromTimestamp($file->getMTime())->format('Y-m-d H:i:s'),
                    ];
                }
            }
        }

        return view('admin.dashboard', compact('spoolCounts', 'freshdeskSpoolCounts', 'freshdeskSpoolRoot', 'deliveryStats', 'dbStatus', 'redisStatus', 'logFiles', 'recentAuditLogs'));
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

    public function systemLogs(Request $request, SystemLogReader $logReader)
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
        $defaultFile = $files[0] ?? null;
        $selectedFile = $request->input('file', $defaultFile);
        if (! is_string($selectedFile) || ! in_array($selectedFile, $files, true)) {
            $selectedFile = $defaultFile;
        }

        $allowedLevels = ['', 'ERRORS', 'EMERGENCY', 'ALERT', 'CRITICAL', 'ERROR', 'WARNING', 'NOTICE', 'INFO', 'DEBUG'];
        $level = strtoupper(trim((string) $request->input('level', '')));
        if (! in_array($level, $allowedLevels, true)) {
            $level = '';
        }

        $allowedComponents = ['', 'freshdesk', 'sla', 'queue', 'redis', 'postgresql', 'rocketchat', 'application'];
        $component = strtolower(trim((string) $request->input('component', '')));
        if (! in_array($component, $allowedComponents, true)) {
            $component = '';
        }

        $query = mb_substr(trim((string) $request->input('query', '')), 0, 200);
        $timeFrom = (string) $request->input('time_from', '00:00:00');
        $timeTo = (string) $request->input('time_to', '23:59:59');
        $page = max(1, (int) $request->input('page', 1));
        $selectedPath = $selectedFile !== null
            ? $logPath.DIRECTORY_SEPARATOR.$selectedFile
            : null;
        $result = $logReader->search($selectedPath, compact(
            'query',
            'level',
            'component',
            'page'
        ) + [
            'time_from' => $timeFrom,
            'time_to' => $timeTo,
        ]);
        $timeFrom = $result['time_from'];
        $timeTo = $result['time_to'];

        $fileMetadata = [];
        foreach ($files as $file) {
            $path = $logPath.DIRECTORY_SEPARATOR.$file;
            $modifiedAt = File::isFile($path) ? Carbon::createFromTimestamp(File::lastModified($path)) : null;
            $fileMetadata[$file] = [
                'label' => $file,
                'size' => File::isFile($path) ? File::size($path) : 0,
                'modified_at' => $modifiedAt,
            ];
        }

        $latestTimestamp = $selectedFile !== null
            ? ($fileMetadata[$selectedFile]['modified_at'] ?? null)
            : null;
        $staleAfterHours = 24;
        $sourceIsStale = $selectedFile === $defaultFile
            && ($latestTimestamp === null
                || Carbon::parse($latestTimestamp)->lt(now()->subHours($staleAfterHours)));

        return view('admin.system_logs', compact(
            'files',
            'fileMetadata',
            'selectedFile',
            'query',
            'level',
            'component',
            'timeFrom',
            'timeTo',
            'result',
            'latestTimestamp',
            'sourceIsStale',
            'staleAfterHours'
        ));
    }

    private function logDisplayName(string $fileName): string
    {
        return $fileName;
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
            fwrite($file, "\xEF\xBB\xBF"); // UTF-8 BOM

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

    public function getSpoolFiles(Request $request, DurableWebhookSpool $freshdeskSpool)
    {
        $type = strtolower($request->input('type', 'rocketchat'));
        $folder = strtolower($request->input('folder', 'ready'));

        if ($type === 'freshdesk') {
            $spoolRoot = rtrim((string) config('freshdesk_spool.root'), '/\\');
            $allowedFolders = ['ready', 'processing', 'enqueued', 'temporary', 'committed-gc', 'quarantine'];
        } else {
            $spoolRoot = config('rocketchat_audit.root', storage_path('app/rocketchat-audit'));
            $allowedFolders = ['ready', 'processing', 'pending', 'temporary'];
        }

        if (! in_array($folder, $allowedFolders, true)) {
            $folder = 'ready';
        }

        $filesList = [];
        if ($type === 'freshdesk') {
            $snapshot = $freshdeskSpool->stateSnapshot($folder, 100);
            $totalCount = $snapshot['count'];
            $allFiles = array_map(
                static fn (string $path): \SplFileInfo => new \SplFileInfo($path),
                $snapshot['files']
            );
        } else {
            $targetDir = rtrim($spoolRoot, '/\\').DIRECTORY_SEPARATOR.$folder;
            $allFiles = File::exists($targetDir) ? File::files($targetDir) : [];
            $totalCount = count($allFiles);
        }

        foreach ($allFiles as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace(rtrim($spoolRoot, '/\\').DIRECTORY_SEPARATOR, '', $file->getPathname());
                $quarantineMetadata = $type === 'freshdesk' && $folder === 'quarantine'
                    ? $freshdeskSpool->quarantineMetadata($file->getPathname())
                    : null;
                $filesList[] = [
                    'name' => $file->getFilename(),
                    'path' => str_replace('\\', '/', $relativePath),
                    'size' => number_format($file->getSize() / 1024, 2).' KB',
                    'updated_at' => Carbon::createFromTimestamp($file->getMTime())->format('Y-m-d H:i:s'),
                    'quarantine_reason' => $quarantineMetadata['reason_code'] ?? null,
                ];
            }
        }
        usort($filesList, fn ($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

        return response()->json([
            'type' => $type,
            'folder' => $folder,
            'count' => $totalCount,
            'files' => array_slice($filesList, 0, 100),
        ]);
    }

    public function readSpoolFileContent(Request $request, DurableWebhookSpool $freshdeskSpool)
    {
        $filePathParam = $request->input('path');
        $type = strtolower($request->input('type', 'rocketchat'));

        if (! $filePathParam) {
            return response()->json(['error' => 'Thiếu tham số path'], 400);
        }

        if ($type === 'freshdesk') {
            $spoolRoot = rtrim((string) config('freshdesk_spool.root'), '/\\');
        } else {
            $spoolRoot = config('rocketchat_audit.root', storage_path('app/rocketchat-audit'));
        }

        $fullPath = $this->resolveSpoolFilePath($spoolRoot, $filePathParam);
        if ($fullPath === null) {
            return response()->json(['error' => 'File không tồn tại hoặc đã được đồng bộ/xóa.'], 404);
        }

        $content = File::get($fullPath);
        $jsonDecoded = json_decode($content, true);

        return response()->json([
            'filename' => basename($fullPath),
            'raw_content' => $content,
            'parsed' => $jsonDecoded ?? null,
            'quarantine_metadata' => $type === 'freshdesk'
                ? $freshdeskSpool->quarantineMetadata($fullPath)
                : null,
        ]);
    }

    private function resolveSpoolFilePath(string $spoolRoot, mixed $requestedPath): ?string
    {
        if (! is_string($requestedPath)
            || $requestedPath === ''
            || str_contains($requestedPath, "\0")
            || preg_match('/^(?:[a-z]:[\\\\\/]|[\\\\\/]{1,2})/i', $requestedPath)) {
            return null;
        }

        $segments = preg_split('/[\\\\\/]+/', $requestedPath) ?: [];
        if ($segments === [] || in_array('..', $segments, true)) {
            return null;
        }

        $segments = array_values(array_filter(
            $segments,
            static fn (string $segment): bool => $segment !== '' && $segment !== '.'
        ));
        if ($segments === []) {
            return null;
        }

        $resolvedRoot = realpath($spoolRoot);
        $resolvedFile = realpath(
            rtrim($spoolRoot, '/\\').DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments)
        );
        if ($resolvedRoot === false || $resolvedFile === false || ! is_file($resolvedFile)) {
            return null;
        }

        $rootPrefix = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedRoot), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR;
        $normalizedFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $resolvedFile);
        if (PHP_OS_FAMILY === 'Windows') {
            $rootPrefix = strtolower($rootPrefix);
            $normalizedFile = strtolower($normalizedFile);
        }

        return str_starts_with($normalizedFile, $rootPrefix) ? $resolvedFile : null;
    }
}
