<?php

namespace App\Http\Controllers;

use App\Models\SalesTransaction;
use App\Services\ReceiptService;
use Illuminate\Http\Response;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    public function index(?SalesTransaction $transaction, ReceiptService $receiptService): View
    {
        $transactions = $receiptService->recentPaidTransactions();
        $selectedTransaction = $transaction
            ? $receiptService->paidTransaction($transaction)
            : $transactions->first();

        return view('receipts.index', compact('transactions', 'selectedTransaction'));
    }

    public function download(SalesTransaction $transaction, ReceiptService $receiptService): Response
    {
        $transaction = $receiptService->paidTransaction($transaction);
        $html = view('receipts.download', compact('transaction'))->render();

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="receipt-' . $transaction->transaction_no . '.html"');
    }
}
