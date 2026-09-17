<?php

namespace App\Providers;

use App\Contracts\Notifications\NotificationChannel as NotificationChannelContract;
use App\Enums\NotificationChannel;
use App\Services\Notifications\TelegramNotificationChannel;
use App\Services\Notifications\WhatsAppNotificationChannel;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(NotificationChannelContract::class, function (): NotificationChannelContract {
            return match (NotificationChannel::from((string) config('services.notifications.channel'))) {
                NotificationChannel::Telegram => new TelegramNotificationChannel,
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
