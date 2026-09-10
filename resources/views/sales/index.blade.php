@extends('layouts.app')

@section('title', 'Sales Transactions - Wear Me Maanyag')
@section('page_title', 'Sales Transactions')
@section('page_description', 'Record and review walk-in and manually entered online sales.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('sales.create') }}">New Sale +</a>
@endsection

@section('content')
    <section class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-slate-500">Recent records</p>
            <p class="mt-2 text-3xl font-black">{{ $transactions->count() }}</p>
        </div>
        <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Workflow</p>
            <p class="mt-2 text-lg font-black">Paid sales</p>
            <p class="mt-1 text-xs font-bold text-slate-600">Unpaid handling comes next</p>
        </div>
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-slate-500">Inventory</p>
            <p class="mt-2 text-lg font-black">Shared stock</p>
            <p class="mt-1 text-xs font-bold text-slate-500">Walk-in + online</p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
        <div>
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Transaction history</p>
            <h2 class="mt-1 text-xl font-black">Recent Sales</h2>
        </div>

        @if ($transactions->isEmpty())
            <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">No sales have been recorded yet. Create the first sale to begin the transaction history.</p>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="border-b-2 border-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Transaction No.</th>
                            <th class="px-3 py-3">Date / Time</th>
                            <th class="px-3 py-3">Items</th>
                            <th class="px-3 py-3">Recorded By</th>
                            <th class="px-3 py-3">Total</th>
                            <th class="px-3 py-3">Sale Type</th>
                            <th class="px-3 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($transactions as $transaction)
                            <tr class="transition hover:bg-slate-50">
                                <td class="px-3 py-4 font-black text-slate-900">{{ $transaction->transaction_no }}</td>
                                <td class="px-3 py-4 text-slate-600">{{ $transaction->transaction_date?->format('M d, Y g:i A') }}</td>
                                <td class="px-3 py-4 font-bold text-slate-700">{{ $transaction->items->sum('quantity') }}</td>
                                <td class="px-3 py-4 text-slate-600">{{ $transaction->user->name }}</td>
                                <td class="px-3 py-4 font-black text-slate-700">₱{{ number_format((float) $transaction->total_amount, 2) }}</td>
                                <td class="px-3 py-4 text-slate-600">
                                    {{ $transaction->sale_type === 'walk_in' ? 'Walk-In' : 'Online' }}
                                    @if ($transaction->online_platform)
                                        <span class="block text-xs font-medium text-slate-400">{{ $transaction->online_platform }}</span>
                                    @endif
                                </td>
                                <td class="px-3 py-4"><span class="inline-flex rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-black text-emerald-700">Paid</span></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
