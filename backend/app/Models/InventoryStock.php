<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryStock extends Model
{
    protected $fillable = [
        'warehouse_id',
        'variant_id',
        'on_hand_qty',
        'reserved_qty',
    ];

    protected function casts(): array
    {
        return [
            'on_hand_qty' => 'integer',
            'reserved_qty' => 'integer',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(
            Warehouse::class,
            'warehouse_id'
        );
    }

    public function movements(): HasMany
    {
        return $this->hasMany(
            InventoryMovement::class,
            'inventory_stock_id'
        );
    }

    public function getAvailableQtyAttribute(): int
    {
        return $this->on_hand_qty - $this->reserved_qty;
    }
}
