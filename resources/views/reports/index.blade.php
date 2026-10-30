@extends('layouts.app')

@section('title', 'Reports - Wear Me Maanyag')
@section('page_title', 'Reports')
@section('page_description', 'Sales and inventory reports with daily, weekly, monthly, and yearly views.')

@section('content')
    <script>
        window.maanyagReportData = {!! Js::from([
            'labels' => $report['chart']['labels'],
            'totals' => $report['chart']['totals'],
        ]) !!};
    </script>

    <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Report Period</p>
                <p class="mt-1 text-lg font-black text-slate-900">
                    {{ $report['start']->format('M j, Y') }} @if ($period !== 'daily') &ndash; {{ $report['end']->format('M j, Y') }} @endif
                </p>
            </div>

            <form class="flex flex-wrap items-end gap-3" method="GET" action="{{ route('reports.index') }}">
                <div>
                    <label class="block text-xs font-black uppercase tracking-wide text-slate-500" for="period">Period</label>
                    <select class="mt-1 rounded-xl border-2 border-slate-900 bg-white px-3 py-2 text-sm font-bold text-slate-900 focus:outline-none focus:ring-4 focus:ring-rose-200" id="period" name="period">
                        @foreach (['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'yearly' => 'Yearly'] as $value => $label)
                            <option value="{{ $value }}" @selected(old('period', $period) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-black uppercase tracking-wide text-slate-500" for="date">Date</label>
                    <input class="mt-1 rounded-xl border-2 border-slate-900 bg-white px-3 py-2 text-sm font-bold text-slate-900 focus:outline-none focus:ring-4 focus:ring-rose-200" id="date" name="date" type="date" value="{{ old('date', $date) }}">
                </div>

                <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2 text-sm font-black text-white transition hover:bg-rose-500" type="submit">Apply</button>
            </form>
        </div>

        @if ($errors->any())
            <p class="mt-4 rounded-xl border-2 border-rose-700 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-700">
                {{ $errors->first() }}
            </p>
        @endif
    </section>

    <div class="mt-6 flex gap-3" role="tablist" aria-label="Report type">
        <button class="report-tab rounded-xl border-2 border-slate-900 px-5 py-2 text-sm font-black transition" id="sales-tab-button" type="button" role="tab" aria-selected="true" aria-controls="sales-report-panel">Sales Reports</button>
        <button class="report-tab rounded-xl border-2 border-slate-900 px-5 py-2 text-sm font-black transition" id="inventory-tab-button" type="button" role="tab" aria-selected="false" aria-controls="inventory-report-panel">Inventory Reports</button>
    </div>

    <div class="mt-5" id="sales-report-panel" role="tabpanel" aria-labelledby="sales-tab-button">
        <section class="grid gap-5 sm:grid-cols-3">
            <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Total Sales</p>
                <p class="mt-3 text-3xl font-black tabular-nums">{{ Number::currency($report['summary']['total_sales'], 'PHP') }}</p>
                <p class="mt-2 text-sm font-medium text-slate-500">for the selected period</p>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Transactions</p>
                <p class="mt-3 text-3xl font-black tabular-nums">{{ $report['summary']['transaction_count'] }}</p>
                <p class="mt-2 text-sm font-medium text-slate-500">completed sale{{ $report['summary']['transaction_count'] === 1 ? '' : 's' }}</p>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Average Sale</p>
                <p class="mt-3 text-3xl font-black tabular-nums">{{ Number::currency($report['summary']['average_sale'], 'PHP') }}</p>
                <p class="mt-2 text-sm font-medium text-slate-500">per transaction</p>
            </div>
        </section>

        <section class="mt-5 rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">
                Sales @switch($period)
                    @case('daily') Hourly Sales @break
                    @case('weekly') Daily Sales - Week @break
                    @case('monthly') Daily Sales - Month @break
                    @default Monthly Sales - Year
                @endswitch
            </p>
            <div class="mt-4 h-72">
                <canvas id="reportSalesChart" aria-label="Sales chart for the selected period" role="img"></canvas>
            </div>
        </section>

        <section class="mt-5 rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Sales Breakdown</p>
                <div class="flex gap-2">
                    <a class="rounded-xl border-2 border-slate-900 bg-white px-3 py-2 text-xs font-black text-slate-900 transition hover:bg-rose-500 hover:text-white" href="{{ route('reports.sales.export', ['period' => $period, 'date' => $date, 'format' => 'pdf']) }}">Export PDF</a>
                    <a class="rounded-xl border-2 border-slate-900 bg-white px-3 py-2 text-xs font-black text-slate-900 transition hover:bg-rose-500 hover:text-white" href="{{ route('reports.sales.export', ['period' => $period, 'date' => $date, 'format' => 'excel']) }}">Export Excel</a>
                </div>
            </div>

            @if (empty($report['rows']))
                <p class="mt-6 rounded-xl border-2 border-emerald-700 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">No sales recorded for this period.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b-2 border-slate-900 text-xs font-black uppercase tracking-wide text-slate-500">
                                <th class="py-3 pr-4">Date</th>
                                <th class="py-3 pr-4 text-right">Transactions</th>
                                <th class="py-3 text-right">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @foreach ($report['rows'] as $row)
                                <tr>
                                    <td class="py-3 pr-4 font-bold text-slate-900">{{ $row['label'] }}</td>
                                    <td class="py-3 pr-4 text-right font-semibold tabular-nums text-slate-700">{{ $row['transactions'] }}</td>
                                    <td class="py-3 text-right font-black tabular-nums text-slate-900">{{ Number::currency($row['total'], 'PHP') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>

    <div class="mt-5 hidden" id="inventory-report-panel" role="tabpanel" aria-labelledby="inventory-tab-button">
        <section class="grid gap-5 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Active Products</p>
                <p class="mt-3 text-3xl font-black tabular-nums">{{ number_format($inventoryStats['product_count']) }}</p>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Units in Stock</p>
                <p class="mt-3 text-3xl font-black tabular-nums">{{ number_format($inventoryStats['total_units']) }}</p>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Stock Value</p>
                <p class="mt-3 text-3xl font-black tabular-nums">{{ Number::currency($inventoryStats['stock_value'], 'PHP') }}</p>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 {{ $lowStockCount > 0 ? 'bg-amber-50' : 'bg-white' }} p-6 shadow-[5px_5px_0_0_#0f172a]">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Low Stock</p>
                <p class="mt-3 text-3xl font-black tabular-nums">{{ $lowStockCount }}</p>
                <p class="mt-2 text-sm font-medium text-slate-500">product{{ $lowStockCount === 1 ? '' : 's' }} at or below reorder level</p>
            </div>
        </section>

        <section class="mt-5 rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-black uppercase tracking-wide text-rose-500">All Active Products</p>
                    <p class="mt-1 text-sm font-medium text-slate-500">Full inventory list with reorder levels.</p>
                </div>
                <div class="flex gap-2">
                    <a class="rounded-xl border-2 border-slate-900 bg-white px-3 py-2 text-xs font-black text-slate-900 transition hover:bg-rose-500 hover:text-white" href="{{ route('reports.inventory.export', ['format' => 'pdf']) }}">Export PDF</a>
                    <a class="rounded-xl border-2 border-slate-900 bg-white px-3 py-2 text-xs font-black text-slate-900 transition hover:bg-rose-500 hover:text-white" href="{{ route('reports.inventory.export', ['format' => 'excel']) }}">Export Excel</a>
                    <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('products.index') }}">Manage Products</a>
                </div>
            </div>

            @if ($products->isEmpty())
                <p class="mt-6 rounded-xl border-2 border-emerald-700 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800">No active products yet.</p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b-2 border-slate-900 text-xs font-black uppercase tracking-wide text-slate-500">
                                <th class="py-3 pr-4">Code</th>
                                <th class="py-3 pr-4">Product</th>
                                <th class="py-3 pr-4">Category</th>
                                <th class="py-3 pr-4 text-right">Price</th>
                                <th class="py-3 pr-4 text-right">Qty</th>
                                <th class="py-3 pr-4 text-right">Reorder At</th>
                                <th class="py-3 text-right">Stock Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-200">
                            @foreach ($products as $product)
                                @php($isLowStock = $product->quantity <= $product->low_stock_threshold)
                                <tr class="{{ $isLowStock ? 'bg-amber-50' : '' }}">
                                    <td class="py-3 pr-4 font-bold tabular-nums text-slate-900">{{ $product->product_code }}</td>
                                    <td class="py-3 pr-4 font-bold text-slate-900">{{ $product->product_name }}</td>
                                    <td class="py-3 pr-4 font-medium text-slate-600">{{ $product->category?->name ?? 'Uncategorized' }}</td>
                                    <td class="py-3 pr-4 text-right font-semibold tabular-nums text-slate-700">{{ Number::currency($product->price, 'PHP') }}</td>
                                    <td class="py-3 pr-4 text-right font-black tabular-nums text-slate-900">{{ $product->quantity }}</td>
                                    <td class="py-3 pr-4 text-right font-semibold tabular-nums text-slate-600">{{ $product->low_stock_threshold }}</td>
                                    <td class="py-3 text-right">
                                        @if ($isLowStock)
                                            <span class="rounded-xl border-2 border-rose-700 bg-rose-50 px-3 py-1 text-xs font-black text-rose-700">Low Stock</span>
                                        @else
                                            <span class="rounded-xl border-2 border-emerald-700 bg-emerald-50 px-3 py-1 text-xs font-black text-emerald-700">OK</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const canvas = document.getElementById('reportSalesChart');

            if (canvas && window.Chart && window.maanyagReportData) {
                new window.Chart(canvas, {
                    type: 'bar',
                    data: {
                        labels: window.maanyagReportData.labels,
                        datasets: [{
                            label: 'Sales (PHP)',
                            data: window.maanyagReportData.totals,
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
                            x: {
                                ticks: { autoSkip: true, maxTicksLimit: 24 },
                            },
                        },
                    },
                });
            }

            const tabs = [
                { button: document.getElementById('sales-tab-button'), panel: document.getElementById('sales-report-panel') },
                { button: document.getElementById('inventory-tab-button'), panel: document.getElementById('inventory-report-panel') },
            ];

            tabs.forEach(function (tab) {
                tab.button.addEventListener('click', function () {
                    tabs.forEach(function (other) {
                        const isActive = other === tab;
                        other.panel.classList.toggle('hidden', !isActive);
                        other.button.setAttribute('aria-selected', String(isActive));
                        other.button.classList.toggle('bg-slate-900', isActive);
                        other.button.classList.toggle('text-white', isActive);
                        other.button.classList.toggle('bg-white', !isActive);
                        other.button.classList.toggle('text-slate-900', !isActive);
                    });
                });
            });

            tabs.forEach(function (tab, index) {
                const isActive = index === 0;
                tab.button.classList.toggle('bg-slate-900', isActive);
                tab.button.classList.toggle('text-white', isActive);
                tab.button.classList.toggle('bg-white', !isActive);
                tab.button.classList.toggle('text-slate-900', !isActive);
            });
        });
    </script>
@endpush
