<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesTransaction extends Model
{
    protected $table = 'tbl_sales_transactions';

    protected $fillable = [
        'transaction_no',
        'sale_type',
        'online_platform',
        'customer_name',
        'customer_contact',
        'delivery_address',
        'total_amount',
        'payment_status',
        'recorded_by',
        'transaction_date',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'transaction_date' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesTransactionItem::class, 'transaction_id');
    }

    public function unpaidPayments(): HasMany
    {
        return $this->hasMany(UnpaidPayment::class, 'transaction_id');
    }
}
