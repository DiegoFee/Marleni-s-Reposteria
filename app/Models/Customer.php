<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'full_name',
        'phone',
    ];

    public function phoneFormatted(): string
    {
        if (preg_match('/\A[0-9]{8}\z/', $this->phone) !== 1) {
            return $this->phone;
        }

        return substr($this->phone, 0, 4).'-'.substr($this->phone, 4);
    }

    public function phoneInternational(): ?string
    {
        return preg_match('/\A[0-9]{8}\z/', $this->phone) === 1
            ? '+502'.$this->phone
            : null;
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }
}
