<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryAdjustmentService
{
    /**
     * @param  array{direction: string, quantity: int|string, reason_kind: string, reason: string}  $data
     */
    public function adjust(Product $product, User $owner, array $data): InventoryAdjustment
    {
        if ($data['reason_kind'] === 'damaged' && $data['direction'] !== 'decrease') {
            throw ValidationException::withMessages([
                'direction' => 'Damaged items must decrease stock.',
            ]);
        }

        return DB::transaction(function () use ($product, $owner, $data): InventoryAdjustment {
            $lockedProduct = Product::query()
                ->whereKey($product->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            $quantity = (int) $data['quantity'];
            $quantityBefore = $lockedProduct->quantity;
            $quantityAfter = $data['direction'] === 'increase'
                ? $quantityBefore + $quantity
                : $quantityBefore - $quantity;

            if ($quantityAfter < 0) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$quantityBefore} units are available to remove.",
                ]);
            }

            if ($quantityAfter > 2147483647) {
                throw ValidationException::withMessages([
                    'quantity' => 'The resulting stock quantity is too large.',
                ]);
            }

            $reason = trim($data['reason']);
            if ($data['reason_kind'] === 'damaged') {
                $reason = 'Damaged item: '.$reason;
            }

            $lockedProduct->update([
                'quantity' => $quantityAfter,
                'updated_by' => $owner->id,
            ]);

            return $lockedProduct->inventoryAdjustments()->create([
                'adjusted_by' => $owner->id,
                'direction' => $data['direction'],
                'quantity' => $quantity,
                'quantity_before' => $quantityBefore,
                'quantity_after' => $quantityAfter,
                'reason' => $reason,
            ]);
        });
    }
}
