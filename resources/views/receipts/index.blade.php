@extends('layouts.app')

@section('title', 'Receipt Generation - Wear Me Maanyag')
@section('page_title', 'Receipt Generation')
@section('page_description', 'Select a completed transaction, preview its receipt, then print or download it.')

@section('content')
    <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.9fr)]">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
            <div class="border-b-2 border-slate-100 pb-4">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Receipt history</p>
                <h2 class="mt-1 text-xl font-black">Recent Transactions</h2>
            </div>

            @if ($transactions->isEmpty())
                <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">No paid transactions are available for receipt generation yet.</p>
            @else
                <div class="mt-5 space-y-3">
                    @foreach ($transactions as $transactionItem)
                        <a class="block rounded-xl border-2 px-4 py-3 transition {{ $selectedTransaction?->id === $transactionItem->id ? 'border-rose-500 bg-rose-50' : 'border-slate-200 hover:border-slate-900 hover:bg-slate-50' }}" href="{{ route('receipts.index', ['transaction' => $transactionItem]) }}">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-sm font-black">{{ $transactionItem->transaction_no }}</p>
                                    <p class="mt-1 text-xs font-medium text-slate-500">{{ $transactionItem->transaction_date?->format('M d, Y g:i A') }} · {{ $transactionItem->items->sum('quantity') }} item(s)</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-sm font-black">₱{{ number_format((float) $transactionItem->total_amount, 2) }}</p>
                                    <span class="text-xs font-bold text-rose-600">View receipt</span>
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
            <div class="border-b-2 border-slate-100 pb-4">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Receipt preview</p>
                <h2 class="mt-1 text-xl font-black">{{ $selectedTransaction?->transaction_no ?? 'No receipt selected' }}</h2>
            </div>

            @if ($selectedTransaction)
                <div class="receipt-preview mt-5 rounded-xl border-2 border-slate-200 bg-slate-50 p-3 sm:p-5">
                    @include('receipts.partials.document', ['transaction' => $selectedTransaction])
                </div>
                <div class="mt-5 flex flex-wrap gap-3">
                    <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" data-print-receipt type="button">Print Receipt</button>
                    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('receipts.download', $selectedTransaction) }}">Download Receipt</a>
                </div>
            @else
                <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">Choose a transaction to preview its receipt.</p>
            @endif
        </div>
    </section>

    @if ($selectedTransaction)
        <script>
            document.querySelector('[data-print-receipt]')?.addEventListener('click', () => window.print());
        </script>
    @endif
@endsection
