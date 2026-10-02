<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesTransaction;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ReportService
{
    public function __construct(private DashboardService $dashboardService) {}

    public function salesReport(string $period, CarbonInterface $date): array
    {
        [$start, $end] = $this->resolveRange($period, $date);

        $summary = SalesTransaction::query()
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $end)
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('COALESCE(SUM(total_amount), 0) as total_sales')
            ->selectRaw('COALESCE(AVG(total_amount), 0) as average_sale')
            ->first();

        return [
            'period' => $period,
            'start' => $start,
            'end' => $end->subSecond(),
            'summary' => [
                'transaction_count' => (int) $summary->transaction_count,
                'total_sales' => (float) $summary->total_sales,
                'average_sale' => (float) $summary->average_sale,
            ],
            'chart' => $this->salesSeries($period, $start, $end),
            'rows' => $this->salesRows($period, $start, $end),
        ];
    }

    public function inventoryProducts(): Collection
    {
        return Product::with('category')
            ->where('status', 'active')
            ->orderBy('product_name')
            ->get();
    }

    public function inventoryStats(): array
    {
        return $this->dashboardService->inventoryStats();
    }

    public function lowStockProducts(int $limit = 10): Collection
    {
        return $this->dashboardService->lowStockProducts($limit);
    }

    public function lowStockCount(): int
    {
        return $this->dashboardService->lowStockCount();
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} inclusive start, exclusive end
     */
    private function resolveRange(string $period, CarbonInterface $date): array
    {
        $date = CarbonImmutable::instance($date);

        return match ($period) {
            'daily' => [$date->startOfDay(), $date->addDay()->startOfDay()],
            'weekly' => [$date->startOfWeek(CarbonImmutable::MONDAY), $date->addWeek()->startOfWeek(CarbonImmutable::MONDAY)],
            'monthly' => [$date->startOfMonth(), $date->addMonthNoOverflow()->startOfMonth()],
            'yearly' => [$date->startOfYear(), $date->addYear()->startOfYear()],
            default => throw ValidationException::withMessages([
                'period' => 'Unsupported report period.',
            ]),
        };
    }

    /**
     * @return array{labels: array<int, string>, totals: array<int, float>}
     */
    private function salesSeries(string $period, CarbonImmutable $start, CarbonImmutable $end): array
    {
        if ($period === 'daily') {
            $rows = SalesTransaction::query()
                ->where('transaction_date', '>=', $start)
                ->where('transaction_date', '<', $end)
                ->selectRaw('HOUR(transaction_date) as sale_hour')
                ->selectRaw('SUM(total_amount) as hour_total')
                ->groupBy('sale_hour')
                ->pluck('hour_total', 'sale_hour');

            return [
                'labels' => array_map(fn (int $hour) => sprintf('%02d:00', $hour), range(0, 23)),
                'totals' => array_map(fn (int $hour) => (float) ($rows[$hour] ?? 0), range(0, 23)),
            ];
        }

        if ($period === 'yearly') {
            $rows = SalesTransaction::query()
                ->where('transaction_date', '>=', $start)
                ->where('transaction_date', '<', $end)
                ->selectRaw('MONTH(transaction_date) as sale_month')
                ->selectRaw('SUM(total_amount) as month_total')
                ->groupBy('sale_month')
                ->pluck('month_total', 'sale_month');

            return [
                'labels' => array_map(fn (int $month) => $start->month($month)->format('M'), range(1, 12)),
                'totals' => array_map(
                    fn (int $month) => (float) ($rows[$month] ?? 0),
                    range(1, 12),
                ),
            ];
        }

        $rows = SalesTransaction::query()
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $end)
            ->selectRaw('DATE(transaction_date) as sale_day')
            ->selectRaw('SUM(total_amount) as day_total')
            ->groupBy('sale_day')
            ->pluck('day_total', 'sale_day');

        $labels = [];
        $totals = [];

        for ($day = $start; $day->lt($end); $day = $day->addDay()) {
            $labels[] = $day->format($period === 'weekly' ? 'D' : 'd');
            $totals[] = (float) ($rows[$day->toDateString()] ?? 0);
        }

        return ['labels' => $labels, 'totals' => $totals];
    }

    /**
     * Non-empty summary rows matching the chart buckets.
     *
     * @return array<int, array{label: string, transactions: int, total: float}>
     */
    private function salesRows(string $period, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $groupBy = match ($period) {
            'daily' => 'HOUR(transaction_date)',
            'yearly' => 'MONTH(transaction_date)',
            default => 'DATE(transaction_date)',
        };

        $rows = SalesTransaction::query()
            ->where('transaction_date', '>=', $start)
            ->where('transaction_date', '<', $end)
            ->selectRaw("{$groupBy} as bucket")
            ->selectRaw('COUNT(*) as transaction_count')
            ->selectRaw('SUM(total_amount) as bucket_total')
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return $rows->map(function ($row) use ($period, $start) {
            $label = match ($period) {
                'daily' => sprintf('%02d:00', (int) $row->bucket),
                'yearly' => $start->month($row->bucket)->format('M'),
                default => CarbonImmutable::parse($row->bucket)->format($period === 'weekly' ? 'D, M j' : 'M j'),
            };

            return [
                'label' => $label,
                'transactions' => (int) $row->transaction_count,
                'total' => (float) $row->bucket_total,
            ];
        })->all();
    }
}
