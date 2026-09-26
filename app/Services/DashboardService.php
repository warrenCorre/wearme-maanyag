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
        $today = CarbonImmutable::today();

        $todayTotal = (float) SalesTransaction::query()
            ->whereDate('transaction_date', $today)
            ->sum('total_amount');

        $todayCount = SalesTransaction::query()
            ->whereDate('transaction_date', $today)
            ->count();

        return [
            'today_total' => $todayTotal,
            'today_count' => $todayCount,
            'last_seven_days' => [
                'labels' => $this->lastSevenDaysLabels(),
                'totals' => $this->lastSevenDaysTotals(),
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

        $recent = UnpaidPayment::with('product')
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

    private function lastSevenDaysLabels(): array
    {
        return collect(range(6, 0))->map(function (int $daysAgo) {
            return CarbonImmutable::today()->subDays($daysAgo)->format('M d');
        })->all();
    }

    private function lastSevenDaysTotals(): array
    {
        $rows = SalesTransaction::query()
            ->whereDate('transaction_date', '>=', CarbonImmutable::today()->subDays(6))
            ->selectRaw('DATE(transaction_date) as sale_day')
            ->selectRaw('SUM(total_amount) as day_total')
            ->groupBy('sale_day')
            ->pluck('day_total', 'sale_day');

        return collect(range(6, 0))->map(function (int $daysAgo) use ($rows) {
            $day = CarbonImmutable::today()->subDays($daysAgo)->toDateString();

            return (float) ($rows[$day] ?? 0);
        })->all();
    }
}
