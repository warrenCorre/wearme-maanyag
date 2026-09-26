<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SalesTransaction;
use App\Services\SalesTransactionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesTransactionController extends Controller
{
    public function index(): View
    {
        $transactions = SalesTransaction::with(['items.product', 'user'])
            ->orderByDesc('transaction_date')
            ->limit(25)
            ->get();

        return view('sales.index', compact('transactions'));
    }

    public function create(): View
    {
        $products = Product::with('category')
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->orderBy('product_name')
            ->get();

        return view('sales.create', compact('products'));
    }

    public function store(Request $request, SalesTransactionService $salesTransactionService): RedirectResponse
    {
        $validated = $request->validate([
            'sale_type' => ['required', Rule::in(['walk_in', 'online'])],
            'online_platform' => ['nullable', 'required_if:sale_type,online', 'string', 'max:50', Rule::in(['TikTok', 'Facebook', 'Messenger'])],
            'payment_status' => ['required', Rule::in(['paid'])],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => [
                'nullable',
                'integer',
                Rule::exists('tbl_products', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],
            'items.*.quantity' => ['nullable', 'required_with:items.*.product_id', 'integer', 'min:1'],
        ]);

        $transaction = $salesTransactionService->createPaidSale($validated, Auth::user());

        return redirect()->route('sales')
            ->with('status', "Sale {$transaction->transaction_no} recorded successfully.");
    }
}
