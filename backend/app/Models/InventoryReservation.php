<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryReservation extends Model
{
    protected $fillable = [
        'reservation_key',
        'inventory_stock_id',
        'reference',
        'quantity',
        'shipped_qty',
        'released_qty',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'shipped_qty' => 'integer',
            'released_qty' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(
            InventoryStock::class,
            'inventory_stock_id'
        );
    }

    public function getActiveQtyAttribute(): int
    {
        return $this->quantity
            - $this->shipped_qty
            - $this->released_qty;
    }
}
