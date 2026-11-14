<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AssistantStockAdjustmentService
{
    public function answer(?string $period = null, ?CarbonInterface $referenceDate = null): string
    {
        $query = InventoryAdjustment::query();
        $scope = 'all time';

        if (in_array($period, ['daily', 'monthly', 'weekly', 'yearly'], true)) {
            $date = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::today());
            $start = match ($period) {
                'daily' => $date->startOfDay(),
                'weekly' => $date->startOfWeek(CarbonImmutable::MONDAY),
                'yearly' => $date->startOfYear(),
                default => $date->startOfMonth(),
            };
            $end = match ($period) {
                'daily' => $start->addDay(),
                'weekly' => $start->addWeek(),
                'yearly' => $start->addYear(),
                default => $start->addMonth(),
            };
            $query->where('created_at', '>=', $start)
                ->where('created_at', '<', $end);
            $scope = match ($period) {
                'daily' => 'during '.$start->format('F j, Y'),
                'weekly' => 'during week of '.$start->format('F j, Y'),
                'yearly' => 'during '.$start->year,
                default => 'during '.$date->format('F Y'),
            };
        }

        $totals = $query
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
            'Recorded non-sales stock adjustments ('.$scope.'): %d %s (%d %s added), %d %s (%d %s removed). Reasons and product details are not shown.',
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
