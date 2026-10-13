<?php

namespace App\Providers;

use App\Contracts\Notifications\NotificationChannel as NotificationChannelContract;
use App\Enums\NotificationChannel;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Order;
use App\Models\User;
use App\Policies\CustomerPolicy;
use App\Policies\OrderPolicy;
use App\Services\Notifications\TelegramReminderChannel;
use App\Services\Notifications\WhatsAppNotificationChannel;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);

        Gate::define('view-dashboard', fn (User $user): bool => in_array($user->role, [UserRole::Owner, UserRole::Assistant], true));
        Gate::define('view-catalogs', fn (User $user): bool => in_array($user->role, [UserRole::Owner, UserRole::Assistant], true));
        Gate::define('modify-catalogs', fn (User $user): bool => $user->isOwner());
        Gate::define('manage-accounts', fn (User $user): bool => $user->isOwner());
    }
}
