<?php

namespace App\Services;

use App\Models\InventoryAdjustment;

class AssistantDamagedAdjustmentService
{
    public function answer(): string
    {
        $totals = InventoryAdjustment::query()
            ->where('direction', 'decrease')
            ->where('reason', 'like', 'Damaged item: %')
            ->toBase()
            ->selectRaw('COUNT(*) as adjustment_count, COALESCE(SUM(quantity), 0) as removed_units')
            ->first();

        $adjustmentCount = (int) $totals->adjustment_count;
        $removedUnits = (int) $totals->removed_units;

        return sprintf(
            'Recorded damaged-item adjustments: %d %s, %d %s removed (all time). Reasons and product details are not shown.',
            $adjustmentCount,
            $adjustmentCount === 1 ? 'decrease' : 'decreases',
            $removedUnits,
            $removedUnits === 1 ? 'unit' : 'units',
        );
    }
}
