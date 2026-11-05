<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Market extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function loyaltyWallets(): HasMany
    {
        return $this->hasMany(LoyaltyWallet::class);
    }
}
