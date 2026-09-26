<?php

namespace App\Services;

use App\Models\SalesTransaction;
use Illuminate\Database\Eloquent\Collection;

class ReceiptService
{
    public function recentPaidTransactions(int $limit = 25): Collection
    {
        return SalesTransaction::with(['items.product', 'user'])
            ->where('payment_status', 'paid')
            ->orderByDesc('transaction_date')
            ->limit($limit)
            ->get();
    }

    public function paidTransaction(SalesTransaction $transaction): SalesTransaction
    {
        abort_if($transaction->payment_status !== 'paid', 404);

        return $transaction->load(['items.product', 'user']);
    }
}
