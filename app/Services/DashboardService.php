<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesTransaction;
use App\Models\UnpaidPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class DashboardService
{
    public function salesStats(): array
    {
        $today = CarbonImmutable::today('Asia/Manila');
        $days = collect(range(6, 0))
            ->map(fn (int $daysAgo): CarbonImmutable => $today->subDays($daysAgo));
        $query = SalesTransaction::query()->toBase();

        foreach ($days as $index => $day) {
            $start = $day->utc()->format('Y-m-d H:i:s');
            $end = $day->addDay()->utc()->format('Y-m-d H:i:s');

            $query->selectRaw(
                "COALESCE(SUM(CASE WHEN transaction_date >= ? AND transaction_date < ? THEN total_amount ELSE 0 END), 0) as day_{$index}_total",
                [$start, $end],
            );
        }

        $todayStart = $today->utc()->format('Y-m-d H:i:s');
        $todayEnd = $today->addDay()->utc()->format('Y-m-d H:i:s');
        $stats = $query
            ->selectRaw('COUNT(CASE WHEN transaction_date >= ? AND transaction_date < ? THEN 1 END) as today_count', [$todayStart, $todayEnd])
            ->where('transaction_date', '>=', $days->first()->utc()->format('Y-m-d H:i:s'))
            ->where('transaction_date', '<', $todayEnd)
            ->first();
        $totals = $days
            ->keys()
            ->map(fn (int $index): float => (float) $stats->{"day_{$index}_total"})
            ->all();

        return [
            'today_total' => $totals[6],
            'today_count' => (int) $stats->today_count,
            'last_seven_days' => [
                'labels' => $days->map(fn (CarbonImmutable $day): string => $day->format('M d'))->all(),
                'totals' => $totals,
            ],
        ];
    }

    public function inventoryStats(): array
    {
        $stats = Product::query()
            ->where('status', 'active')
            ->selectRaw('COUNT(*) as product_count')
            ->selectRaw('COALESCE(SUM(quantity), 0) as total_units')
            ->selectRaw('COALESCE(SUM(quantity * price), 0) as stock_value')
            ->first();

        return [
            'product_count' => (int) $stats->product_count,
            'total_units' => (int) $stats->total_units,
            'stock_value' => (float) $stats->stock_value,
        ];
    }

    public function lowStockProducts(int $limit = 6): Collection
    {
        return Product::with('category')
            ->where('status', 'active')
            ->whereColumn('quantity', '<=', 'low_stock_threshold')
            ->orderByRaw('ABS(quantity - low_stock_threshold)')
            ->orderBy('product_name')
            ->limit($limit)
            ->get();
    }

    public function lowStockCount(): int
    {
        return Product::query()
            ->where('status', 'active')
            ->whereColumn('quantity', '<=', 'low_stock_threshold')
            ->count();
    }

    public function unpaidSummary(): array
    {
        $stats = UnpaidPayment::query()
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) as pending_count')
            ->selectRaw('COALESCE(SUM(balance), 0) as outstanding_balance')
            ->first();

        $recent = UnpaidPayment::with('salesTransaction.items.product')
            ->where('status', 'pending')
            ->orderByDesc('id')
            ->limit(6)
            ->get();

        return [
            'pending_count' => (int) $stats->pending_count,
            'outstanding_balance' => (float) $stats->outstanding_balance,
            'recent' => $recent,
        ];
    }
}
