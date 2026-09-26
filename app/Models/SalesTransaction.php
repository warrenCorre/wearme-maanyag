<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\UnpaidPayment;

class SalesTransaction extends Model
{
    protected $table = 'tbl_sales_transactions';

    protected $fillable = [
        'transaction_no',
        'sale_type',
        'online_platform',
        'total_amount',
        'payment_status',
        'cashier_id',
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
        return $this->belongsTo(User::class, 'cashier_id');
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
