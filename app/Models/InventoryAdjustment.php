<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryAdjustment extends Model
{
    protected $table = 'tbl_inventory_adjustments';

    protected $fillable = [
        'product_id',
        'adjusted_by',
        'direction',
        'quantity',
        'quantity_before',
        'quantity_after',
        'reason',
    ];

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_before' => 'integer',
            'quantity_after' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id')->withTrashed();
    }

    public function adjustedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'adjusted_by')->withTrashed();
    }
}
