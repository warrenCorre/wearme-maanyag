@extends('layouts.app')

@section('title', 'Dashboard - Wear Me Maanyag')
@section('page_title', 'Dashboard')
@section('page_description', 'A live overview of daily sales, inventory health, and outstanding balances.')

@section('content')
    <script>
        window.maanyagChartData = {!! Js::from([
            'labels' => $salesStats['last_seven_days']['labels'],
            'totals' => $salesStats['last_seven_days']['totals'],
        ]) !!};
    </script>

    <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Today's Sales</p>
            <p class="mt-3 text-3xl font-black tabular-nums">{{ Number::currency($salesStats['today_total'], 'PHP') }}</p>
            <p class="mt-2 text-sm font-medium text-slate-500">{{ $salesStats['today_count'] }} transaction{{ $salesStats['today_count'] === 1 ? '' : 's' }} today</p>
        </div>

        <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Inventory Level</p>
            <p class="mt-3 text-3xl font-black tabular-nums">{{ number_format($inventoryStats['total_units']) }}</p>
            <p class="mt-2 text-sm font-medium text-slate-500">{{ $inventoryStats['product_count'] }} active product{{ $inventoryStats['product_count'] === 1 ? '' : 's' }}</p>
            <p class="mt-1 text-sm font-semibold text-slate-600">{{ Number::currency($inventoryStats['stock_value'], 'PHP') }} estimated value</p>
        </div>

        <div class="rounded-2xl border-2 border-slate-900 {{ $lowStockCount > 0 ? 'bg-amber-50' : 'bg-white' }} p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Low Stock Alerts</p>
            <p class="mt-3 text-3xl font-black tabular-nums">{{ $lowStockCount }}</p>
            <p class="mt-2 text-sm font-medium text-slate-500">product{{ $lowStockCount === 1 ? '' : 's' }} at or below reorder level</p>
        </div>

        <div class="rounded-2xl border-2 border-slate-900 {{ $unpaidSummary['pending_count'] > 0 ? 'bg-amber-50' : 'bg-white' }} p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Unpaid Balance</p>
            <p class="mt-3 text-3xl font-black tabular-nums">{{ Number::currency($unpaidSummary['outstanding_balance'], 'PHP') }}</p>
            <p class="mt-2 text-sm font-medium text-slate-500">{{ $unpaidSummary['pending_count'] }} pending record{{ $unpaidSummary['pending_count'] === 1 ? '' : 's' }}</p>
        </div>
    </section>

    <section class="mt-8 grid gap-5 lg:grid-cols-2">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Sales - Last 7 Days</p>
            <div class="mt-4 h-72">
                <canvas id="salesTrendChart" aria-label="Sales trend for the last seven days" role="img"></canvas>
            </div>
        </div>

        <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-black uppercase tracking-wide text-rose-500">Low Stock Products</p>
                    <p class="mt-1 text-sm font-medium text-slate-500">Reorder soon to avoid running out.</p>
                </div>
                <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('products.index') }}">Manage Products</a>
            </div>

            @if ($lowStockProducts->isEmpty())
                <p class="mt-6 rounded-xl border-2 border-emerald-700 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">All products are above their reorder level.</p>
            @else
                <ul class="mt-4 divide-y divide-slate-200 border-t border-slate-200">
                    @foreach ($lowStockProducts as $product)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-900">{{ $product->product_name }}</p>
                                <p class="text-xs font-medium text-slate-500">{{ $product->category?->name ?? 'Uncategorized' }}</p>
                            </div>
                            <span class="shrink-0 rounded-xl border-2 border-rose-700 bg-rose-50 px-3 py-1 text-xs font-black text-rose-700">{{ $product->quantity }} left</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>

    <section class="mt-8 grid gap-5 lg:grid-cols-2">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex items-center justify-between">
                <div>
                    <p class="text-sm font-black uppercase tracking-wide text-rose-500">Recent Unpaid Records</p>
                    <p class="mt-1 text-sm font-medium text-slate-500">Customers settling their balance soon.</p>
                </div>
                <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('unpaid.index') }}">Manage Unpaid</a>
            </div>

            @if ($unpaidSummary['recent']->isEmpty())
                <p class="mt-6 rounded-xl border-2 border-emerald-700 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">No pending unpaid records.</p>
            @else
                <ul class="mt-4 divide-y divide-slate-200 border-t border-slate-200">
                    @foreach ($unpaidSummary['recent'] as $unpaid)
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-bold text-slate-900">{{ $unpaid->customer_name }}</p>
                                <p class="truncate text-xs font-medium text-slate-500">{{ $unpaid->item_description }}</p>
                            </div>
                            <span class="shrink-0 rounded-xl border-2 border-slate-900 bg-slate-100 px-3 py-1 text-xs font-black text-slate-900">{{ Number::currency($unpaid->balance, 'PHP') }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </section>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('salesTrendChart');

            if (canvas && window.Chart && window.maanyagChartData) {
                new window.Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: window.maanyagChartData.labels,
                        datasets: [{
                            label: 'Sales (PHP)',
                            data: window.maanyagChartData.totals,
                            backgroundColor: '#f43f5e',
                            borderRadius: 8,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: { callback: (value) => '₱' + value.toLocaleString() },
                            },
                        },
                    },
                });
            }
        });
    </script>
@endpush