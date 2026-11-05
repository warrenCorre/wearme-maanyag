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
            $this->rejectDuplicateProducts($data['products']);
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
                'customer_name' => $data['customer_name'],
                'customer_contact' => $data['customer_contact'],
                'delivery_address' => $data['delivery_address'] ?? null,
                'total_amount' => $this->formatCents($totalCents),
                'payment_status' => $amountPaidCents > 0
                    ? 'partially_paid'
                    : 'unpaid',
                'recorded_by' => $updatedBy->id,
                'transaction_date' => now(),
            ]);

            foreach ($items as $item) {
                $product = $item['product'];
                $quantity = $item['quantity'];
                $itemTotalCents = $item['subtotal_cents'];

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
            }

            $transaction->unpaidPayment()->create([
                'amount_paid' => $this->formatCents($amountPaidCents),
                'balance' => $this->formatCents($totalCents - $amountPaidCents),
                'payment_date' => null,
                'status' => 'pending',
                'updated_by' => $updatedBy->id,
            ]);

            return $transaction->load([
                'items.product',
                'user',
                'unpaidPayment.updatedBy',
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
                ->lockForUpdate()
                ->findOrFail($transaction->id);

            if ($transaction->payment_status === 'paid') {
                throw ValidationException::withMessages([
                    'transaction' => 'This unpaid transaction is already settled.',
                ]);
            }

            $payment = $transaction->unpaidPayment()->lockForUpdate()->firstOrFail();

            if ($payment->status !== 'pending' || $this->toCents($payment->amount_paid) + $this->toCents($payment->balance) !== $this->toCents($transaction->total_amount)) {
                throw ValidationException::withMessages([
                    'transaction' => 'The sale balance does not match its unpaid record. Please review this sale.',
                ]);
            }

            $newProductRows = $data['products'] ?? [];
            $this->rejectDuplicateProducts($newProductRows);

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
            }

            $totalCents = $this->toCents($transaction->items()->sum('subtotal'));
            $amountPaidCents = $this->toCents($data['amount_paid']);

            if ($amountPaidCents < $this->toCents($payment->amount_paid) || $amountPaidCents > $totalCents) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'The cumulative payment cannot decrease or exceed the transaction total.',
                ]);
            }

            $settled = $amountPaidCents === $totalCents;
            $payment->update([
                'amount_paid' => $this->formatCents($amountPaidCents),
                'balance' => $this->formatCents($totalCents - $amountPaidCents),
                'payment_date' => $settled ? now() : null,
                'status' => $settled ? 'settled' : 'pending',
                'updated_by' => $updatedBy->id,
            ]);

            $transaction->update([
                'customer_name' => $data['customer_name'],
                'customer_contact' => $data['customer_contact'],
                'delivery_address' => $data['delivery_address'] ?? null,
                'total_amount' => $this->formatCents($totalCents),
                'payment_status' => $settled
                    ? 'paid'
                    : ($amountPaidCents > 0 ? 'partially_paid' : 'unpaid'),
            ]);

            return $transaction->fresh([
                'items.product',
                'user',
                'unpaidPayment.updatedBy',
            ]);
        });
    }

    public function updateCumulativePayment(UnpaidPayment $unpaidPayment, int $amountPaidCents, User $updatedBy): UnpaidPayment
    {
        return DB::transaction(function () use ($unpaidPayment, $amountPaidCents, $updatedBy) {
            $transaction = SalesTransaction::query()->lockForUpdate()->findOrFail($unpaidPayment->transaction_id);

            $payment = UnpaidPayment::query()
                ->whereKey($unpaidPayment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $payment->transaction_id !== $transaction->id) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'The linked sale has changed. Please reload and try again.',
                ]);
            }

            if ($transaction->payment_status === 'paid') {
                throw ValidationException::withMessages([
                    'amount_paid' => 'This unpaid transaction is already settled.',
                ]);
            }

            $currentPaidCents = $this->toCents($payment->amount_paid);
            $totalCents = $this->toCents($transaction->total_amount);

            if ($currentPaidCents + $this->toCents($payment->balance) !== $totalCents) {
                throw ValidationException::withMessages([
                    'amount_paid' => 'The sale balance does not match its total. Please review this sale.',
                ]);
            }

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

            $transaction->update([
                'payment_status' => $settled
                    ? 'paid'
                    : ($amountPaidCents > 0 ? 'partially_paid' : 'unpaid'),
            ]);

            return $payment->load(['salesTransaction', 'updatedBy']);
        });
    }

    private function rejectDuplicateProducts(array $rows): void
    {
        $productIds = array_map(fn (array $row): int => (int) $row['product_id'], $rows);

        if (count($productIds) !== count(array_unique($productIds))) {
            throw ValidationException::withMessages([
                'products' => 'A product can only be selected once per update.',
            ]);
        }
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
