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

    public function salesReport(string $period, CarbonInterface $date, string $format): Response
    {
        $report = $this->reportService->salesReport($period, $date);
        $filename = sprintf('sales-report-%s-%s', $period, $date->toDateString());

        if ($format === 'pdf') {
            return Pdf::loadView('reports.exports.sales', [
                'report' => $report,
                'period' => $period,
                'rangeLabel' => $report['start']->format('M j, Y').($period === 'daily' ? '' : ' - '.$report['end']->format('M j, Y')),
            ])->download($filename.'.pdf');
        }

        $rows = collect($report['rows'])->map(fn (array $row) => [
            $row['label'],
            $row['transactions'],
            $row['total'],
        ]);

        $rows->push([
            'TOTAL',
            $report['summary']['transaction_count'],
            $report['summary']['total_sales'],
        ]);

        return Excel::download(new SalesReportExport($rows->all()), $filename.'.xlsx');
    }

    public function inventoryReport(string $format): Response
    {
        $products = $this->reportService->inventoryProducts();
        $filename = 'inventory-report-'.now()->toDateString();

        if ($format === 'pdf') {
            return Pdf::loadView('reports.exports.inventory', [
                'products' => $products,
                'stats' => $this->reportService->inventoryStats(),
                'lowStockCount' => $this->reportService->lowStockCount(),
            ])->download($filename.'.pdf');
        }

        return Excel::download(new InventoryReportExport($products), $filename.'.xlsx');
    }
}
