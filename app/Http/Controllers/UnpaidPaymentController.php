<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\UnpaidPayment;
use App\Services\UnpaidPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use App\Models\SalesTransaction;

class UnpaidPaymentController extends Controller
{
    public function index(): View
    {
        $transactions = SalesTransaction::query()
            ->with([
                'user',
                'items.product',
                'unpaidPayments.product',
                'unpaidPayments.updatedBy',
            ])
            ->whereIn('payment_status', ['unpaid', 'partially_paid'])
            ->orderByDesc('transaction_date')
            ->get();

        return view('unpaid.index', compact('transactions'));
    }

    public function create(): View
    {
        $products = Product::with('category')
            ->where('status', 'active')
            ->where('quantity', '>', 0)
            ->orderBy('product_name')
            ->get();

        return view('unpaid.create', compact('products'));
    }

    public function store(Request $request, UnpaidPaymentService $unpaidPaymentService): RedirectResponse
    {
        $validated = $request->validate([
            'sale_type' => ['required', Rule::in(['walk_in', 'online'])],
            'online_platform' => [
                'nullable',
                'required_if:sale_type,online',
                'string',
                'max:50',
                Rule::in(['TikTok', 'Facebook', 'Messenger']),
            ],

            'products' => ['required', 'array', 'min:1'],
            'products.*.product_id' => [
                'required',
                'integer',
                Rule::exists('tbl_products', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],
            'products.*.quantity' => ['required', 'integer', 'min:1'],

            'customer_name' => ['required', 'string', 'max:150'],
            'customer_contact' => ['required', 'string', 'max:20'],
            'item_description' => ['required', 'string', 'max:255'],
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $transaction = $unpaidPaymentService->recordPendingItems(
            $validated,
            Auth::user()
        );

        return redirect()->route('unpaid.index')
            ->with(
                'status',
                "Unpaid transaction for {$transaction->customer_name} recorded successfully."
            );
    }

    public function updatePayment(Request $request, UnpaidPayment $unpaidPayment, UnpaidPaymentService $unpaidPaymentService): RedirectResponse
    {
        $validated = $request->validate([
            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $payment = $unpaidPaymentService->updateCumulativePayment(
            $unpaidPayment,
            (int) round(((float) $validated['amount_paid']) * 100),
            Auth::user(),
        );

        return redirect()->route('unpaid.index')
            ->with('status', $payment->status === 'settled'
                ? "Payment for {$payment->customer_name} is settled."
                : "Payment for {$payment->customer_name} was updated.");
    }

    public function updateTransaction(
        Request $request,
        SalesTransaction $transaction,
        UnpaidPaymentService $unpaidPaymentService
    ): RedirectResponse {
        $validated = $request->validate([
            'customer_name' => ['required', 'string', 'max:150'],
            'customer_contact' => ['required', 'string', 'max:20'],
            'item_description' => ['required', 'string', 'max:255'],

            'products' => ['nullable', 'array'],
            'products.*.product_id' => [
                'required',
                'integer',
                Rule::exists('tbl_products', 'id')->where(function ($query) {
                    $query->where('status', 'active');
                }),
            ],
            'products.*.quantity' => ['required', 'integer', 'min:1'],

            'amount_paid' => ['required', 'numeric', 'min:0'],
        ]);

        $updatedTransaction = $unpaidPaymentService->updateTransaction(
            $transaction,
            $validated,
            Auth::user()
        );

        return redirect()
            ->route('unpaid.index')
            ->with(
                'status',
                "Unpaid transaction for {$updatedTransaction->unpaidPayments->first()?->customer_name} updated successfully."
            );
    }
}
