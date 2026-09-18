<?php

namespace App\Providers;

use App\Contracts\Notifications\NotificationChannel as NotificationChannelContract;
use App\Enums\NotificationChannel;
use App\Services\Notifications\TelegramReminderChannel;
use App\Services\Notifications\WhatsAppNotificationChannel;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NotificationChannelContract::class, function (): NotificationChannelContract {
            $channel = NotificationChannel::tryFrom((string) config('services.notifications.channel'));

            if ($channel === null && (bool) config('services.notifications.enabled')) {
                throw new RuntimeException('El canal de recordatorios no es valido.');
            }

            return match ($channel ?? NotificationChannel::Telegram) {
                NotificationChannel::Telegram => new TelegramReminderChannel,
                NotificationChannel::Whatsapp => new WhatsAppNotificationChannel,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
