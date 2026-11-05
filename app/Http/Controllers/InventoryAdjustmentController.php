<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\InventoryAdjustmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InventoryAdjustmentController extends Controller
{
    public function create(Product $product): View
    {
        abort_unless($product->status === 'active', 404);

        return view('products.adjust', [
            'product' => $product,
            'adjustments' => $product->inventoryAdjustments()
                ->with('adjustedBy')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->paginate(20),
        ]);
    }

    public function store(Request $request, Product $product, InventoryAdjustmentService $adjustmentService): RedirectResponse
    {
        $validated = $request->validate([
            'direction' => ['required', Rule::in(['increase', 'decrease'])],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reason_kind' => ['required', Rule::in(['damaged', 'other'])],
            'reason' => ['required', 'string', 'max:240'],
        ]);

        $adjustmentService->adjust($product, Auth::user(), $validated);

        return redirect()->route('products.adjustments.create', $product)
            ->with('status', 'Stock adjustment recorded.');
    }
}
