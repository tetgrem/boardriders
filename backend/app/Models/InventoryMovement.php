<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'inventory_stock_id',
        'type',
        'quantity_delta',
        'reference',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(
            InventoryStock::class,
            'inventory_stock_id'
        );
    }
}
