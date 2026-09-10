<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesTransactionService
{
    public function createPaidSale(array $data, User $cashier): SalesTransaction
    {
        if (($data['payment_status'] ?? null) !== 'paid') {
            throw ValidationException::withMessages([
                'payment_status' => 'Only paid sales are available in this Sales Transactions slice.',
            ]);
        }

        return DB::transaction(function () use ($data, $cashier) {
            $items = collect($data['items'] ?? [])
                ->filter(fn (array $item) => filled($item['product_id'] ?? null))
                ->values();

            if ($items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Add at least one product to the sale.',
                ]);
            }

            $productIds = $items->pluck('product_id')->map(fn ($id) => (int) $id);

            if ($productIds->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'A product can only be added once to the same sale.',
                ]);
            }

            $products = Product::query()
                ->whereIn('id', $productIds->all())
                ->where('status', 'active')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($products->count() !== $productIds->count()) {
                throw ValidationException::withMessages([
                    'items' => 'One or more selected products are no longer available.',
                ]);
            }

            $preparedItems = [];
            $totalCents = 0;

            foreach ($items as $index => $item) {
                $productId = (int) $item['product_id'];
                $quantity = (int) $item['quantity'];
                $product = $products->get($productId);

                if ($quantity > $product->quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => "Not enough stock for {$product->product_name}. Available quantity: {$product->quantity}.",
                    ]);
                }

                $unitPriceCents = (int) round(((float) $product->price) * 100);
                $subtotalCents = $unitPriceCents * $quantity;
                $totalCents += $subtotalCents;

                $preparedItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price' => number_format($unitPriceCents / 100, 2, '.', ''),
                    'subtotal' => number_format($subtotalCents / 100, 2, '.', ''),
                ];
            }

            $transaction = SalesTransaction::create([
                'transaction_no' => $this->generateTransactionNumber(),
                'sale_type' => $data['sale_type'],
                'online_platform' => $data['online_platform'] ?? '',
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                'payment_status' => 'paid',
                'cashier_id' => $cashier->id,
                'transaction_date' => now(),
            ]);

            foreach ($preparedItems as $preparedItem) {
                $product = $preparedItem['product'];

                $product->update([
                    'quantity' => $product->quantity - $preparedItem['quantity'],
                    'updated_by' => $cashier->id,
                ]);

                $transaction->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $preparedItem['quantity'],
                    'unit_price' => $preparedItem['unit_price'],
                    'subtotal' => $preparedItem['subtotal'],
                ]);
            }

            return $transaction->load('items.product');
        });
    }

    private function generateTransactionNumber(): string
    {
        do {
            $transactionNumber = 'WM' . now()->format('ymdHis') . Str::upper(Str::random(3));
        } while (SalesTransaction::where('transaction_no', $transactionNumber)->exists());

        return $transactionNumber;
    }
}
