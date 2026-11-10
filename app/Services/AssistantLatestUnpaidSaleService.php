<?php

namespace App\Services;

use App\Models\SalesTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssistantLatestUnpaidSaleService
{
    public function answer(): string
    {
        $sale = SalesTransaction::query()
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->whereHas('unpaidPayment', fn (Builder $query): Builder => $query
                ->where('status', 'pending')
                ->where('balance', '>', 0))
            ->with([
                'unpaidPayment:id,transaction_id,amount_paid,balance',
                'items:id,transaction_id,product_id,quantity',
                'items.product' => fn (BelongsTo $query): BelongsTo => $query
                    ->withTrashed()
                    ->select(['id', 'product_name']),
            ])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->first([
                'id', 'transaction_no', 'transaction_date', 'sale_type', 'online_platform',
                'customer_name', 'customer_contact', 'delivery_address', 'total_amount', 'payment_status',
            ]);

        if ($sale === null || $sale->unpaidPayment === null) {
            return 'No pending unpaid sale with a positive balance was found.';
        }

        $items = $sale->items->map(fn ($item): string => '- '.$this->recorded($item->product?->product_name).' × '.$item->quantity)
            ->implode("\n");

        $saleType = $sale->sale_type === 'online' ? 'Online' : 'Walk-in';
        $platform = $sale->sale_type === 'online' ? $this->recorded($sale->online_platform) : 'Not applicable';

        return implode("\n", [
            'Latest unpaid sale',
            'Transaction: '.$this->recorded($sale->transaction_no),
            'Date/time: '.$sale->transaction_date->format('Y-m-d H:i'),
            'Items:'.($items === '' ? ' Not recorded' : "\n".$items),
            'Sale type: '.$saleType,
            'Platform: '.$platform,
            'Total: '.$this->money($sale->total_amount),
            'Paid: '.$this->money($sale->unpaidPayment->amount_paid),
            'Balance: '.$this->money($sale->unpaidPayment->balance),
            'Status: '.($sale->payment_status === 'partially_paid' ? 'Partially paid' : 'Unpaid'),
            'Customer: '.$this->recorded($sale->customer_name),
            'Contact: '.$this->recorded($sale->customer_contact),
            'Address: '.$this->recorded($sale->delivery_address),
        ]);
    }

    private function recorded(?string $value): string
    {
        return $value === null || trim($value) === '' ? 'Not recorded' : $value;
    }

    private function money(string $value): string
    {
        return '₱'.number_format((float) $value, 2);
    }
}
