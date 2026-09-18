<?php

namespace App\Console\Commands;

use App\Services\Notifications\ReminderService;
use Illuminate\Console\Command;

class SendOrderReminders extends Command
{
    protected $signature = 'orders:send-reminders
                            {--retry-failed : Reintenta fallos despues de verificar que el proveedor no acepto el mensaje}';

    protected $description = 'Envia recordatorios de preparacion para pedidos proximos.';

    public function handle(ReminderService $reminderService): int
    {
        if (! (bool) config('services.notifications.enabled')) {
            $this->comment('Los recordatorios estan desactivados.');

            return self::SUCCESS;
        }

        $sentCount = $reminderService->sendDueReminders((bool) $this->option('retry-failed'));

        $this->info("Notificaciones confirmadas: {$sentCount}.");

        return self::SUCCESS;
    }
}
