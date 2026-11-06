<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesTransactionItem;
use App\Models\UnpaidPayment;
use Carbon\CarbonInterface;

class AssistantContextService
{
    private const COLLECTION_DATE_LIMIT = 10;

    public function __construct(
        private DashboardService $dashboardService,
        private ReportService $reportService,
    ) {}

    /**
     * @return array{
     *     sales: array{period: string, date: string, summary: array{transaction_count: int, total_sales: float, average_sale: float}},
     *     inventory: array{stats: array{product_count: int, total_units: int, stock_value: float}, low_stock_products: array<int, array{product_name: string, quantity: int, low_stock_threshold: int}>},
     *     best_sellers: array<int, array{product_name: string, units_sold: int, sales_total: float}>,
     *     collection_dates: array<int, array{product_name: string, collection_date: string}>,
     *     unpaid_summary: array{pending_transaction_count: int, outstanding_balance: float},
     *     as_of: array{sales: string, inventory: string, best_sellers: string, collection_dates: string, unpaid_summary: string},
     *     scopes: array{sales: string, best_sellers: string, collection_dates: string, unpaid_summary: string}
     * }
     */
    public function build(string $period, CarbonInterface $date): array
    {
        $asOf = now()->toDateString();
        $salesReport = $this->reportService->salesReport($period, $date);
        $lowStockProducts = $this->reportService->lowStockProducts(10);
        $unpaidSummary = UnpaidPayment::query()
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) as pending_transaction_count')
            ->selectRaw('COALESCE(SUM(balance), 0) as outstanding_balance')
            ->first();

        return [
            'sales' => [
                'period' => $period,
                'date' => $date->toDateString(),
                'summary' => $salesReport['summary'],
            ],
            'inventory' => [
                'stats' => $this->dashboardService->inventoryStats(),
                'low_stock_products' => $lowStockProducts
                    ->map(fn (Product $product): array => [
                        'product_name' => $product->product_name,
                        'quantity' => $product->quantity,
                        'low_stock_threshold' => $product->low_stock_threshold,
                    ])
                    ->all(),
            ],
            'best_sellers' => $this->bestSellers(),
            'collection_dates' => $this->recentCollectionDates(),
            'unpaid_summary' => [
                'pending_transaction_count' => (int) $unpaidSummary->pending_transaction_count,
                'outstanding_balance' => (float) $unpaidSummary->outstanding_balance,
            ],
            'as_of' => [
                'sales' => $date->toDateString(),
                'inventory' => $asOf,
                'best_sellers' => $asOf,
                'collection_dates' => $asOf,
                'unpaid_summary' => $asOf,
            ],
            'scopes' => [
                'sales' => 'All recorded sales transactions, including paid, unpaid, and partially paid.',
                'best_sellers' => 'Paid sales transactions only, all-time; not limited to the selected sales period.',
                'collection_dates' => 'Up to 10 active products with recorded collection dates, newest first; excludes archived products and unknown dates.',
                'unpaid_summary' => 'One pending balance per sale; excludes settled balances.',
            ],
        ];
    }

    /**
     * @return array<int, array{product_name: string, collection_date: string}>
     */
    private function recentCollectionDates(): array
    {
        return Product::query()
            ->where('status', 'active')
            ->whereNotNull('collection_date')
            ->orderByDesc('collection_date')
            ->orderByDesc('id')
            ->limit(self::COLLECTION_DATE_LIMIT)
            ->get(['id', 'product_name', 'collection_date'])
            ->map(fn (Product $product): array => [
                'product_name' => $product->product_name,
                'collection_date' => $product->collection_date->toDateString(),
            ])
            ->all();
    }

    /**
     * @return array<int, array{product_name: string, units_sold: int, sales_total: float}>
     */
    private function bestSellers(): array
    {
        return SalesTransactionItem::query()
            ->join('tbl_sales_transactions as transactions', 'transactions.id', '=', 'tbl_sales_transaction_items.transaction_id')
            ->join('tbl_products as products', 'products.id', '=', 'tbl_sales_transaction_items.product_id')
            ->where('transactions.payment_status', 'paid')
            ->select('products.product_name')
            ->selectRaw('SUM(tbl_sales_transaction_items.quantity) as units_sold')
            ->selectRaw('SUM(tbl_sales_transaction_items.subtotal) as sales_total')
            ->groupBy('products.id', 'products.product_name')
            ->orderByDesc('units_sold')
            ->orderBy('products.product_name')
            ->limit(5)
            ->get()
            ->map(fn (SalesTransactionItem $item): array => [
                'product_name' => $item->product_name,
                'units_sold' => (int) $item->units_sold,
                'sales_total' => (float) $item->sales_total,
            ])
            ->all();
    }
}
