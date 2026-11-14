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

        if (in_array($period, ['monthly', 'weekly', 'yearly'], true)) {
            $date = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::today());
            $start = match ($period) {
                'weekly' => $date->startOfWeek(CarbonImmutable::MONDAY),
                'yearly' => $date->startOfYear(),
                default => $date->startOfMonth(),
            };
            $end = match ($period) {
                'weekly' => $start->addWeek(),
                'yearly' => $start->addYear(),
                default => $start->addMonth(),
            };
            $query->where('created_at', '>=', $start)
                ->where('created_at', '<', $end);
            $scope = match ($period) {
                'weekly' => 'during week of '.$start->format('F j, Y'),
                'yearly' => 'during '.$start->year,
                default => 'during '.$date->format('F Y'),
            };
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
