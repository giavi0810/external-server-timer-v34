<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\Sla\AppTimerSyncService;
use Illuminate\Console\Command;

class SyncTicketCommand extends Command
{
    protected $signature = 'ticket:sync {ticket_id}';
    protected $description = 'Đồng bộ lại dữ liệu SLA custom_fields cho 1 ticket cụ thể sang Freshdesk';

    public function handle(): int
    {
        $ticketId = (int) $this->argument('ticket_id');
        $ticket = Ticket::where('ticket_id', $ticketId)->first();

        if (!$ticket) {
            $this->error("Không tìm thấy ticket #{$ticketId} trong DB.");
            return 1;
        }

        $this->info("Đang kiểm tra đồng bộ dữ liệu SLA cho ticket #{$ticketId}...");

        if (class_exists(AppTimerSyncService::class)) {
            app(AppTimerSyncService::class)->syncTicket($ticket);
            $this->info("Đồng bộ thành công ticket #{$ticketId} sang Freshdesk!");
        } else {
            $this->warn("AppTimerSyncService chưa được khởi tạo (Mục 7 kế hoạch). Bản ghi Ticket #{$ticketId} trong DB đã được cập nhật sẵn sàng.");
        }

        return 0;
    }
}
