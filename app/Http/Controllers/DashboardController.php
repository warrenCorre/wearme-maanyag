<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(DashboardService $dashboardService): View
    {
        $salesStats = $dashboardService->salesStats();
        $inventoryStats = $dashboardService->inventoryStats();

        return view('dashboard', [
            'salesStats' => $salesStats,
            'inventoryStats' => $inventoryStats,
            'lowStockProducts' => $dashboardService->lowStockProducts(),
            'lowStockCount' => $dashboardService->lowStockCount(),
            'unpaidSummary' => $dashboardService->unpaidSummary(),
        ]);
    }
}
