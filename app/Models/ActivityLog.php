<?php

namespace App\Models;

use App\Enums\ActivityEventType;
use App\Enums\NotificationChannel;
use App\Enums\ReminderWindow;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'payment_id',
        'actor_user_id',
        'event_type',
        'notification_channel',
        'reminder_window',
        'notification_key',
        'provider_message_id',
        'details',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ActivityEventType::class,
            'notification_channel' => NotificationChannel::class,
            'reminder_window' => ReminderWindow::class,
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
