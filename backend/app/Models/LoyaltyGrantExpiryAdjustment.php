<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyGrantExpiryAdjustment extends Model
{
    protected $table = 'loyalty_grant_expiry_adjustments';

    protected $guarded = ['*'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'old_expires_at' => 'immutable_datetime',
            'new_expires_at' => 'immutable_datetime',
            'effective_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'context' => 'array',
        ];
    }

    public function grant(): BelongsTo
    {
        return $this->belongsTo(
            LoyaltyGrant::class,
            'grant_id'
        );
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'actor_user_id'
        );
    }
}
