<?php

namespace App\Console\Commands;

use App\Services\Notifications\ReminderService;
use Illuminate\Console\Command;

class SendOrderReminders extends Command
{
    protected $signature = 'orders:send-reminders';

    protected $description = 'Envia recordatorios de preparacion para pedidos proximos.';

    public function handle(ReminderService $reminderService): int
    {
        if (! (bool) config('services.notifications.enabled')) {
            $this->comment('Los recordatorios estan desactivados.');

            return self::SUCCESS;
        }

        $sentCount = $reminderService->sendDueReminders();

        $this->info("Recordatorios confirmados: {$sentCount}.");

        return self::SUCCESS;
    }
}
