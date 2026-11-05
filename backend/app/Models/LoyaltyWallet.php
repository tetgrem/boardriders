<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyWallet extends Model
{
    protected $guarded = ['*'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            LoyaltyAccount::class,
            'loyalty_account_id'
        );
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }
}
