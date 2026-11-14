<?php

namespace App\Services;

use App\Exports\InventoryReportExport;
use App\Exports\SalesReportExport;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class ReportExportService
{
    public function __construct(private ReportService $reportService, private ReportChartImage $chartImage) {}

    public function salesReport(CarbonInterface $date, string $format): Response
    {
        $reports = [];
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $reports[$period] = $this->reportService->salesReport($period, $date);
        }

        $filename = 'sales-report-all-periods-'.$date->toDateString();

        if ($format === 'pdf') {
            $chartImages = [];
            foreach ($reports as $period => $report) {
                $chartImages[$period] = $this->chartImage->verticalBars($report['chart']['labels'], $report['chart']['totals'], 'PHP');
            }

            return Pdf::loadView('reports.exports.sales', [
                'reports' => $reports,
                'date' => $date->toDateString(),
                'chartImages' => $chartImages,
            ])->download($filename.'.pdf');
        }

        return Excel::download(new SalesReportExport($reports, $date->toDateString()), $filename.'.xlsx');
    }

    public function inventoryReport(string $format): Response
    {
        $products = $this->reportService->inventoryProducts();
        $lowStockProducts = $products->filter(fn (Product $product): bool => $product->quantity <= $product->low_stock_threshold)->values();
        $stats = $this->reportService->inventoryStats();
        $filename = 'inventory-report-'.now()->toDateString();

        if ($format === 'pdf') {
            return Pdf::loadView('reports.exports.inventory', [
                'products' => $products,
                'lowStockProducts' => $lowStockProducts,
                'adjustments' => $this->reportService->inventoryAdjustments(),
                'stats' => $stats,
                'lowStockCount' => $lowStockProducts->count(),
            ])->download($filename.'.pdf');
        }

        return Excel::download(new InventoryReportExport($products, $this->reportService->lazyInventoryAdjustments(), $stats), $filename.'.xlsx');
    }
}
