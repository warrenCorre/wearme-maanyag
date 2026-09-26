@extends('layouts.app')

@section('title', 'Product Archive - Wear Me Maanyag')
@section('page_title', 'Product Archive')
@section('page_description', 'Restore archived products or permanently remove only records that are safe to delete.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('products.index') }}">Back to Products</a>
@endsection

@section('content')
    <section class="rounded-2xl border-2 border-rose-700 bg-rose-50 p-5 shadow-[5px_5px_0_0_#9f1239] sm:p-6">
        <p class="text-sm font-black uppercase tracking-wide text-rose-700">Archive safety</p>
        <p class="mt-2 text-sm font-semibold leading-relaxed text-rose-900">Archived products are hidden from active inventory. Products referenced by sales or unpaid-payment records cannot be permanently removed.</p>
    </section>

    <section class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Archived records</p>
                <h2 class="mt-1 text-xl font-black">Archived Products</h2>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ $products->count() }} archived</span>
        </div>

        @if ($products->isEmpty())
            <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">The Archive is empty.</p>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="border-b-2 border-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Product</th>
                            <th class="px-3 py-3">Category</th>
                            <th class="px-3 py-3">Archived On</th>
                            <th class="px-3 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($products as $product)
                            <tr>
                                <td class="px-3 py-4">
                                    <p class="font-black text-slate-900">{{ $product->product_name }}</p>
                                    <p class="text-xs font-medium text-slate-500">{{ $product->product_code }}</p>
                                </td>
                                <td class="px-3 py-4 font-semibold text-slate-600">{{ $product->category->category_name }}</td>
                                <td class="px-3 py-4 text-slate-600">{{ $product->deleted_at?->format('M d, Y g:i A') }}</td>
                                <td class="px-3 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('products.restore', $product->id) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="rounded-lg bg-emerald-50 px-3 py-2 text-xs font-black text-emerald-700 transition hover:bg-emerald-100" type="submit">Restore</button>
                                        </form>
                                        <form method="POST" action="{{ route('products.permanent', $product->id) }}" onsubmit="return confirm('Permanently remove this product? This cannot be undone.');">
                                            @csrf
                                            @method('DELETE')
                                            <button class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-black text-rose-700 transition hover:bg-rose-100" type="submit">Remove Permanently</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
