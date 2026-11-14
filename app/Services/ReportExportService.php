<?php

namespace App\Services;

use App\Exports\InventoryReportExport;
use App\Exports\SalesReportExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonInterface;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\Response;

class ReportExportService
{
    public function __construct(private ReportService $reportService) {}

    public function salesReport(CarbonInterface $date, string $format): Response
    {
        $reports = [];
        foreach (['daily', 'weekly', 'monthly', 'yearly'] as $period) {
            $reports[$period] = $this->reportService->salesReport($period, $date);
        }

        $filename = 'sales-report-all-periods-'.$date->toDateString();

        if ($format === 'pdf') {
            return Pdf::loadView('reports.exports.sales', [
                'reports' => $reports,
                'date' => $date->toDateString(),
            ])->download($filename.'.pdf');
        }

        return Excel::download(new SalesReportExport($reports, $date->toDateString()), $filename.'.xlsx');
    }

    public function inventoryReport(string $format): Response
    {
        $products = $this->reportService->inventoryProducts();
        $filename = 'inventory-report-'.now()->toDateString();

        if ($format === 'pdf') {
            return Pdf::loadView('reports.exports.inventory', [
                'products' => $products,
                'adjustments' => $this->reportService->inventoryAdjustments(),
                'stats' => $this->reportService->inventoryStats(),
                'lowStockCount' => $this->reportService->lowStockCount(),
            ])->download($filename.'.pdf');
        }

        return Excel::download(new InventoryReportExport($products, $this->reportService->lazyInventoryAdjustments()), $filename.'.xlsx');
    }
}
