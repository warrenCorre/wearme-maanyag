@extends('layouts.app')

@section('title', 'Adjust Stock - Wear Me Maanyag')
@section('page_title', 'Adjust Stock')
@section('page_description', 'Record a non-sales stock change and keep its history.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('products.index') }}">Back to Products</a>
@endsection

@section('content')
    <section class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Product</p>
            <h2 class="mt-2 text-xl font-black text-slate-900">{{ $product->product_name }}</h2>
            <p class="text-xs font-bold text-slate-500">{{ $product->product_code }}</p>
        </div>
        <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Current stock</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $product->quantity }}</p>
            <p class="mt-1 text-xs font-bold text-slate-600">Sales deduct stock through the sales workflow.</p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
        <h2 class="text-xl font-black text-slate-900">New adjustment</h2>
        <p class="mt-1 text-sm font-medium text-slate-600">Choose the direction, enter the number of units, and explain why the stock is changing.</p>

        <form class="mt-6 grid gap-5 md:grid-cols-2" method="POST" action="{{ route('products.adjustments.store', $product) }}">
            @csrf
            <div>
                <label class="text-sm font-bold text-slate-700" for="direction">Direction</label>
                <select class="mt-2 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="direction" name="direction" required>
                    <option value="">Select direction</option>
                    <option value="increase" @selected(old('direction') === 'increase')>Increase stock</option>
                    <option value="decrease" @selected(old('direction') === 'decrease')>Decrease stock</option>
                </select>
            </div>
            <div>
                <label class="text-sm font-bold text-slate-700" for="adjustment_quantity">Quantity</label>
                <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="number" id="adjustment_quantity" name="quantity" value="{{ old('quantity') }}" min="1" max="2147483647" required>
            </div>
            <div class="md:col-span-2">
                <label class="text-sm font-bold text-slate-700" for="reason_kind">Reason type</label>
                <select class="mt-2 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="reason_kind" name="reason_kind" required>
                    <option value="">Select reason type</option>
                    <option value="damaged" @selected(old('reason_kind') === 'damaged')>Damaged item (decrease only)</option>
                    <option value="other" @selected(old('reason_kind') === 'other')>Other non-sales reason</option>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="text-sm font-bold text-slate-700" for="reason">Explanation</label>
                <textarea class="mt-2 min-h-28 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="reason" name="reason" maxlength="240" required>{{ old('reason') }}</textarea>
            </div>
            <div class="flex flex-wrap justify-end gap-3 md:col-span-2">
                <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-700 transition hover:bg-slate-900 hover:text-white" href="{{ route('products.index') }}">Cancel</a>
                <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-5 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" type="submit">Record Adjustment</button>
            </div>
        </form>
    </section>

    <section class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
        <h2 class="text-xl font-black text-slate-900">Adjustment history</h2>
        <p class="mt-1 text-sm font-medium text-slate-600">Past adjustments are read-only.</p>

        @if ($adjustments->isEmpty())
            <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">No stock adjustments have been recorded for this product.</p>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[800px] text-left text-sm">
                    <thead class="border-b-2 border-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Recorded</th>
                            <th class="px-3 py-3">Change</th>
                            <th class="px-3 py-3">Before</th>
                            <th class="px-3 py-3">After</th>
                            <th class="px-3 py-3">Reason</th>
                            <th class="px-3 py-3">Owner</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($adjustments as $adjustment)
                            <tr>
                                <td class="px-3 py-4 text-slate-600">{{ $adjustment->created_at->format('M d, Y g:i A') }}</td>
                                <td class="px-3 py-4 font-black {{ $adjustment->direction === 'increase' ? 'text-emerald-700' : 'text-rose-700' }}">{{ $adjustment->direction === 'increase' ? '+' : '-' }}{{ $adjustment->quantity }}</td>
                                <td class="px-3 py-4 text-slate-600">{{ $adjustment->quantity_before }}</td>
                                <td class="px-3 py-4 text-slate-600">{{ $adjustment->quantity_after }}</td>
                                <td class="px-3 py-4 font-medium text-slate-700">{{ $adjustment->reason }}</td>
                                <td class="px-3 py-4 text-slate-600">{{ $adjustment->adjustedBy->name }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-6">{{ $adjustments->links() }}</div>
        @endif
    </section>
@endsection
