<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\UnpaidPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UnpaidPaymentService
{
    public function recordPendingItems(array $data, User $updatedBy): SalesTransaction
    {
        return DB::transaction(function () use ($data, $updatedBy) {
            $items = [];
            $totalCents = 0;

            foreach ($data['products'] as $row) {
                $product = Product::query()
                    ->whereKey($row['product_id'])
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        'products' => 'One of the selected products is no longer available.',
                    ]);
                }

                $quantity = (int) $row['quantity'];

                if ($quantity > $product->quantity) {
                    throw ValidationException::withMessages([
                        'products' => "Not enough stock for {$product->product_name}. Available quantity: {$product->quantity}.",
                    ]);
                }

                $unitPriceCents = $this->toCents($product->price);
                $subtotalCents = $unitPriceCents * $quantity;

                $totalCents += $subtotalCents;

                $items[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_price_cents' => $unitPriceCents,
                    'subtotal_cents' => $subtotalCents,
                ];
            }

            $amountPaidCents = $this->toCents($data['amount_paid'] ?? 0);

            if ($amountPaidCents < 0 || $amountPaidCents >= $totalCents) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'The initial payment must be at least ₱0.00 and less than the total amount.',
                ]);
            }

            $transaction = SalesTransaction::create([
                'transaction_no' => $this->generateTransactionNumber(),
                'sale_type' => $data['sale_type'],
                'online_platform' => $data['online_platform'] ?? '',
                'total_amount' => $this->formatCents($totalCents),
                'payment_status' => $amountPaidCents > 0
                    ? 'partially_paid'
                    : 'unpaid',
                'cashier_id' => $updatedBy->id,
                'transaction_date' => now(),
            ]);

            $remainingPaymentCents = $amountPaidCents;

            foreach ($items as $item) {
                $product = $item['product'];
                $quantity = $item['quantity'];
                $itemTotalCents = $item['subtotal_cents'];

                $itemPaidCents = min($remainingPaymentCents, $itemTotalCents);
                $itemBalanceCents = $itemTotalCents - $itemPaidCents;

                $remainingPaymentCents -= $itemPaidCents;

                $product->update([
                    'quantity' => $product->quantity - $quantity,
                    'updated_by' => $updatedBy->id,
                ]);

                $transaction->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $this->formatCents($item['unit_price_cents']),
                    'subtotal' => $this->formatCents($itemTotalCents),
                ]);

                UnpaidPayment::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'customer_name' => $data['customer_name'],
                    'customer_contact' => $data['customer_contact'],
                    'item_description' => $data['item_description'],
                    'amount_paid' => $this->formatCents($itemPaidCents),
                    'balance' => $this->formatCents($itemBalanceCents),
                    'payment_date' => null,
                    'status' => 'pending',
                    'updated_by' => $updatedBy->id,
                ]);
            }

            return $transaction->load([
                'items.product',
                'user',
                'unpaidPayments.product',
                'unpaidPayments.updatedBy',
            ]);
        });
    }

    public function updateTransaction(
        SalesTransaction $transaction,
        array $data,
        User $updatedBy
    ): SalesTransaction {
        return DB::transaction(function () use ($transaction, $data, $updatedBy) {
            $transaction = SalesTransaction::query()
                ->with(['items.product', 'unpaidPayments'])
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if ($transaction->payment_status === 'paid') {
                throw ValidationException::withMessages([
                    'transaction' => 'This unpaid transaction is already settled.',
                ]);
            }

            /*
            * Step 1:
            * Add any newly selected products to the same transaction.
            */
            $newProductRows = $data['products'] ?? [];

            foreach ($newProductRows as $row) {
                $product = Product::query()
                    ->whereKey($row['product_id'])
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first();

                if (!$product) {
                    throw ValidationException::withMessages([
                        'products' => 'One of the selected products is no longer available.',
                    ]);
                }

                $quantity = (int) $row['quantity'];

                if ($quantity > $product->quantity) {
                    throw ValidationException::withMessages([
                        'products' => "Not enough stock for {$product->product_name}. Available quantity: {$product->quantity}.",
                    ]);
                }

                $unitPriceCents = $this->toCents($product->price);
                $subtotalCents = $unitPriceCents * $quantity;

                $product->update([
                    'quantity' => $product->quantity - $quantity,
                    'updated_by' => $updatedBy->id,
                ]);

                $transaction->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $this->formatCents($unitPriceCents),
                    'subtotal' => $this->formatCents($subtotalCents),
                ]);

                UnpaidPayment::create([
                    'transaction_id' => $transaction->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'customer_name' => $data['customer_name'],
                    'customer_contact' => $data['customer_contact'],
                    'item_description' => $data['item_description'],
                    'amount_paid' => '0.00',
                    'balance' => $this->formatCents($subtotalCents),
                    'payment_date' => null,
                    'status' => 'pending',
                    'updated_by' => $updatedBy->id,
                ]);
            }

            /*
            * Step 2:
            * Refresh the transaction after new items were added.
            */
            $transaction->load([
                'items.product',
                'unpaidPayments',
            ]);

            /*
            * Step 3:
            * Update shared customer information on all unpaid records.
            */
            foreach ($transaction->unpaidPayments as $payment) {
                $payment->update([
                    'customer_name' => $data['customer_name'],
                    'customer_contact' => $data['customer_contact'],
                    'item_description' => $data['item_description'],
                    'updated_by' => $updatedBy->id,
                ]);
            }

            /*
            * Step 4:
            * Recalculate the transaction total from its transaction items.
            */
            $totalCents = $transaction->items->sum(
                fn ($item) => $this->toCents($item->subtotal)
            );

            /*
            * Step 5:
            * Treat amount_paid as the customer's cumulative payment
            * for the whole transaction.
            */
            $amountPaidCents = $this->toCents($data['amount_paid']);

            if ($amountPaidCents < 0 || $amountPaidCents > $totalCents) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'The cumulative payment cannot be less than ₱0.00 or greater than the transaction total.',
                ]);
            }

            /*
            * Step 6:
            * Distribute the cumulative payment across the transaction's
            * unpaid-payment rows without creating a payment-history table.
            */
            $remainingPaidCents = $amountPaidCents;

            foreach ($transaction->unpaidPayments as $payment) {
                $itemTotalCents = $this->toCents($payment->amount_paid)
                    + $this->toCents($payment->balance);

                $itemPaidCents = min($remainingPaidCents, $itemTotalCents);
                $itemBalanceCents = $itemTotalCents - $itemPaidCents;
                $settled = $itemBalanceCents === 0;

                $payment->update([
                    'amount_paid' => $this->formatCents($itemPaidCents),
                    'balance' => $this->formatCents($itemBalanceCents),
                    'payment_date' => $settled ? now() : null,
                    'status' => $settled ? 'settled' : 'pending',
                    'updated_by' => $updatedBy->id,
                ]);

                $remainingPaidCents -= $itemPaidCents;
            }

            $transaction->update([
                'total_amount' => $this->formatCents($totalCents),
                'payment_status' => $amountPaidCents === $totalCents
                    ? 'paid'
                    : ($amountPaidCents > 0 ? 'partially_paid' : 'unpaid'),
            ]);

            return $transaction->fresh([
                'items.product',
                'user',
                'unpaidPayments.product',
                'unpaidPayments.updatedBy',
            ]);
        });
    }

    public function updateCumulativePayment(UnpaidPayment $unpaidPayment, int $amountPaidCents, User $updatedBy): UnpaidPayment
    {
        return DB::transaction(function () use ($unpaidPayment, $amountPaidCents, $updatedBy) {
            $payment = UnpaidPayment::query()
                ->whereKey($unpaidPayment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentPaidCents = $this->toCents($payment->amount_paid);
            $totalCents = $currentPaidCents + $this->toCents($payment->balance);

            if ($amountPaidCents < $currentPaidCents || $amountPaidCents > $totalCents) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'Enter a cumulative payment from the current amount up to the original total.',
                ]);
            }

            $balanceCents = $totalCents - $amountPaidCents;
            $settled = $balanceCents === 0;

            $payment->update([
                'amount_paid' => $this->formatCents($amountPaidCents),
                'balance' => $this->formatCents($balanceCents),
                'payment_date' => $settled ? now() : null,
                'status' => $settled ? 'settled' : 'pending',
                'updated_by' => $updatedBy->id,
            ]);

            if ($payment->transaction_id) {
                $transaction = SalesTransaction::query()
                    ->lockForUpdate()
                    ->find($payment->transaction_id);

                $transaction?->update([
                    'payment_status' => $settled
                        ? 'paid'
                        : ($amountPaidCents > 0 ? 'partially_paid' : 'unpaid'),
                ]);
            }

            return $payment->load(['product', 'salesTransaction', 'updatedBy']);
        });
    }

    private function generateTransactionNumber(): string
    {
        do {
            $transactionNumber = 'WM' . now()->format('ymdHis') . Str::upper(Str::random(3));
        } while (SalesTransaction::where('transaction_no', $transactionNumber)->exists());

        return $transactionNumber;
    }

    private function toCents(string|int|float|null $amount): int
    {
        return (int) round(((float) ($amount ?? 0)) * 100);
    }

    private function formatCents(int $amountCents): string
    {
        return number_format($amountCents / 100, 2, '.', '');
    }
}
