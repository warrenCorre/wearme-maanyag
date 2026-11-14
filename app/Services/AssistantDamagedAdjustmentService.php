<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AssistantDamagedAdjustmentService
{
    public function answer(?string $period = null, ?CarbonInterface $referenceDate = null): string
    {
        $query = InventoryAdjustment::query()
            ->where('direction', 'decrease')
            ->where('reason', 'like', 'Damaged item: %');

        $scope = 'all time';

        if ($period === 'monthly') {
            $date = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::today());
            $start = $date->startOfMonth();
            $query->where('created_at', '>=', $start)
                ->where('created_at', '<', $start->addMonth());
            $scope = 'during '.$date->format('F Y');
        }

        $totals = $query
            ->toBase()
            ->selectRaw('COUNT(*) as adjustment_count, COALESCE(SUM(quantity), 0) as removed_units')
            ->first();

        $adjustmentCount = (int) $totals->adjustment_count;
        $removedUnits = (int) $totals->removed_units;

        return sprintf(
            'Recorded damaged-item adjustments: %d %s, %d %s removed ('.$scope.'). Reasons and product details are not shown.',
            $adjustmentCount,
            $adjustmentCount === 1 ? 'decrease' : 'decreases',
            $removedUnits,
            $removedUnits === 1 ? 'unit' : 'units',
        );
    }
}
