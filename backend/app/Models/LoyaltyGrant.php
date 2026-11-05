<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyGrant extends Model
{
    protected $table = 'loyalty_grants';

    // Записується окремим транзакційним сервісом.
    protected $guarded = ['*'];

    // Таблиця не має updated_at.
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'units_total' => 'integer',
            'granted_at' => 'immutable_datetime',
            'activation_not_before_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'activation_requirements' => 'array',
            'rule_snapshot' => 'array',
            'context' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(
            LoyaltyWallet::class,
            'wallet_id'
        );
    }

    public function expiryAdjustments(): HasMany
    {
        return $this->hasMany(
            LoyaltyGrantExpiryAdjustment::class,
            'grant_id'
        );
    }
}
