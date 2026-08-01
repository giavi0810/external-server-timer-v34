<?php

namespace App\Console\Commands;

use App\Services\Queue\DispatchResult;
use App\Services\Queue\FreshdeskOutboundDispatcher;
use App\Services\Queue\FreshdeskSpoolDispatcher;
use App\Services\Queue\TicketLogicOutboxDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunDurableDispatcherCommand extends Command
{
    protected $signature = 'durable-dispatch:work
        {--spool-limit=50 : Freshdesk spool files per cycle}
        {--logic-limit=50 : Ticket logic outbox rows per cycle}
        {--outbound-limit=50 : Freshdesk outbound rows per cycle}
        {--idle-sleep=1 : Seconds to wait when no work is available}
        {--max-backoff=30 : Maximum seconds to wait after dependency failures}
        {--max-time=3600 : Maximum process lifetime in seconds; zero disables it}
        {--memory=128 : Memory limit in megabytes; zero disables it}
        {--once : Run one dispatch cycle and exit}';

    protected $description = 'Continuously dispatch durable spool and PostgreSQL outboxes without repeated Laravel bootstraps';

    private bool $shouldQuit = false;

    public function handle(
        FreshdeskSpoolDispatcher $spool,
        TicketLogicOutboxDispatcher $logic,
        FreshdeskOutboundDispatcher $outbound,
    ): int {
        $startedAt = microtime(true);
        $backoffs = $this->backoffSchedule(max(1, (int) $this->option('max-backoff')));
        $backoffIndex = 0;
        $spoolLimit = $this->positiveOption('spool-limit');
        $logicLimit = $this->positiveOption('logic-limit');
        $outboundLimit = $this->positiveOption('outbound-limit');
        $this->registerSignalHandlers();

        $this->info('Durable dispatcher started.');

        do {
            $results = [
                $this->runStep('freshdesk-spool', fn () => $spool->dispatch($spoolLimit)),
                $this->runStep('ticket-logic-outbox', fn () => $logic->dispatch($logicLimit)),
                $this->runStep('freshdesk-outbound', fn () => $outbound->dispatch($outboundLimit)),
            ];

            $failed = false;
            $didWork = false;
            foreach ($results as $result) {
                $failed = $failed || $result->failed();
                $didWork = $didWork || $result->didWork();
            }

            if ($this->option('once')) {
                return $failed ? self::FAILURE : self::SUCCESS;
            }

            if ($failed) {
                $this->sleepInterruptibly($backoffs[min($backoffIndex, count($backoffs) - 1)]);
                $backoffIndex = min($backoffIndex + 1, count($backoffs) - 1);
            } elseif ($didWork) {
                $backoffIndex = 0;
            } elseif ($backoffIndex > 0) {
                // Keep the dependency cooldown until at least one dispatch succeeds.
                // This prevents no-work cycles between per-item retry timestamps from
                // resetting the backoff and hammering an unavailable dependency.
                $this->sleepInterruptibly($backoffs[min($backoffIndex, count($backoffs) - 1)]);
            } else {
                $this->sleepInterruptibly(max(1, (int) $this->option('idle-sleep')));
            }

            if ($this->limitReached($startedAt)) {
                $this->shouldQuit = true;
            }
        } while (! $this->shouldQuit);

        $this->info('Durable dispatcher stopped normally.');

        return self::SUCCESS;
    }

    private function runStep(string $name, callable $callback): DispatchResult
    {
        try {
            return $callback();
        } catch (\Throwable $exception) {
            Log::warning('Durable dispatcher step failed', [
                'component' => $name,
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            return new DispatchResult(0, 1);
        }
    }

    private function positiveOption(string $name): int
    {
        return max(1, (int) $this->option($name));
    }

    /** @return list<int> */
    private function backoffSchedule(int $maximum): array
    {
        $schedule = array_values(array_unique(array_map(
            fn (int $seconds): int => min($seconds, $maximum),
            [1, 2, 5, 10, 30]
        )));

        if (end($schedule) !== $maximum) {
            $schedule[] = $maximum;
        }

        return $schedule;
    }

    private function limitReached(float $startedAt): bool
    {
        $maxTime = max(0, (int) $this->option('max-time'));
        if ($maxTime > 0 && microtime(true) - $startedAt >= $maxTime) {
            return true;
        }

        $memory = max(0, (int) $this->option('memory'));

        return $memory > 0 && memory_get_usage(true) >= $memory * 1024 * 1024;
    }

    private function registerSignalHandlers(): void
    {
        if (! method_exists($this, 'trap') || ! defined('SIGTERM') || ! defined('SIGINT')) {
            return;
        }

        $this->trap(
            [constant('SIGTERM'), constant('SIGINT')],
            function (): void {
                $this->shouldQuit = true;
            }
        );
    }

    private function sleepInterruptibly(int $seconds): void
    {
        $deadline = microtime(true) + max(1, $seconds);
        while (! $this->shouldQuit && microtime(true) < $deadline) {
            usleep((int) min(1_000_000, max(1, ($deadline - microtime(true)) * 1_000_000)));
        }
    }
}
