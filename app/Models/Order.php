<?php

namespace App\Models;

use App\Enums\CaptureMode;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'order_number',
        'customer_id',
        'created_by',
        'capture_mode',
        'cake_category_id',
        'base_price_id',
        'cake_description',
        'agreed_price',
        'delivery_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'capture_mode' => CaptureMode::class,
            'agreed_price' => 'decimal:2',
            'delivery_at' => 'datetime',
            'status' => OrderStatus::class,
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cakeCategory(): BelongsTo
    {
        return $this->belongsTo(CakeCategory::class);
    }

    public function basePrice(): BelongsTo
    {
        return $this->belongsTo(BasePrice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
