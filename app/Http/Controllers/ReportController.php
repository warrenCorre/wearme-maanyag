<?php

namespace App\Http\Controllers;

use App\Services\ReportExportService;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reportService): View
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'date' => ['nullable', 'date'],
        ]);

        $period = $validated['period'] ?? 'daily';
        $date = CarbonImmutable::parse($validated['date'] ?? now());

        return view('reports.index', [
            'period' => $period,
            'date' => $date->toDateString(),
            'report' => $reportService->salesReport($period, $date),
            'products' => $reportService->inventoryProducts(),
            'inventoryStats' => $reportService->inventoryStats(),
            'lowStockCount' => $reportService->lowStockCount(),
        ]);
    }

    public function exportSales(Request $request, ReportExportService $exportService): Response
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['daily', 'weekly', 'monthly', 'yearly'])],
            'date' => ['nullable', 'date'],
            'format' => ['required', Rule::in(['pdf', 'excel'])],
        ]);

        $period = $validated['period'] ?? 'daily';
        $date = CarbonImmutable::parse($validated['date'] ?? now());

        return $exportService->salesReport($period, $date, $validated['format']);
    }

    public function exportInventory(Request $request, ReportExportService $exportService): Response
    {
        $validated = $request->validate([
            'format' => ['required', Rule::in(['pdf', 'excel'])],
        ]);

        return $exportService->inventoryReport($validated['format']);
    }
}
