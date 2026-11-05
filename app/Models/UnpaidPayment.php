<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnpaidPayment extends Model
{
    protected $table = 'tbl_unpaid_payments';

    protected $fillable = [
        'transaction_id',
        'amount_paid',
        'balance',
        'payment_date',
        'status',
        'updated_by',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'balance' => 'decimal:2',
            'payment_date' => 'datetime',
        ];
    }

    public function salesTransaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'transaction_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
