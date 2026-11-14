<?php

namespace App\Services;

use App\Models\InventoryAdjustment;

class AssistantStockAdjustmentService
{
    public function answer(): string
    {
        $totals = InventoryAdjustment::query()
            ->toBase()
            ->selectRaw("COUNT(CASE WHEN direction = 'increase' THEN 1 END) as increase_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'increase' THEN quantity ELSE 0 END), 0) as units_added")
            ->selectRaw("COUNT(CASE WHEN direction = 'decrease' THEN 1 END) as decrease_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'decrease' THEN quantity ELSE 0 END), 0) as units_removed")
            ->first();

        $increaseCount = (int) $totals->increase_count;
        $decreaseCount = (int) $totals->decrease_count;
        $unitsAdded = (int) $totals->units_added;
        $unitsRemoved = (int) $totals->units_removed;

        return sprintf(
            'Recorded non-sales stock adjustments (all time): %d %s (%d %s added), %d %s (%d %s removed). Reasons and product details are not shown.',
            $increaseCount,
            $increaseCount === 1 ? 'increase' : 'increases',
            $unitsAdded,
            $unitsAdded === 1 ? 'unit' : 'units',
            $decreaseCount,
            $decreaseCount === 1 ? 'decrease' : 'decreases',
            $unitsRemoved,
            $unitsRemoved === 1 ? 'unit' : 'units',
        );
    }
}
