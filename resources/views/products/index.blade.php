@extends('layouts.app')

@section('title', 'Products - Wear Me Maanyag')
@section('page_title', 'Products Management')
@section('page_description', 'Maintain the shared store inventory and monitor low-stock products.')

@section('header_actions')
    <div class="flex flex-wrap gap-3">
        <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('products.archive.index') }}">Archive</a>
        <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('products.create') }}">Add Product +</a>
    </div>
@endsection

@section('content')
    <section class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-slate-500">Available</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $products->count() }}</p>
            <p class="mt-1 text-xs font-bold text-slate-500">Active products in the catalog</p>
        </div>
        <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Low-stock</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $lowStockCount }}</p>
            <p class="mt-1 text-xs font-bold text-slate-600">Products needing attention</p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Inventory catalog</p>
                <h2 class="mt-1 text-xl font-black">All Products</h2>
            </div>
            <p class="text-xs font-bold text-slate-500">Category is shown as part of each product record.</p>
        </div>

        @if ($products->isEmpty())
            <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">No active products have been created yet.</p>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[900px] text-left text-sm">
                    <thead class="border-b-2 border-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Photo</th>
                            <th class="px-3 py-3">Product Name</th>
                            <th class="px-3 py-3">Category</th>
                            <th class="px-3 py-3">Price</th>
                            <th class="px-3 py-3">Stock</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($products as $product)
                            @php($isLowStock = $product->quantity <= $product->low_stock_threshold)
                            <tr class="transition hover:bg-slate-50">
                                <td class="px-3 py-4">
                                    @if ($product->image)
                                        <img class="h-11 w-11 rounded-xl border-2 border-slate-200 object-cover" src="{{ asset('storage/' . $product->image) }}" alt="{{ $product->product_name }}">
                                    @else
                                        <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-slate-100 text-xs font-bold text-slate-400">N/A</div>
                                    @endif
                                </td>
                                <td class="px-3 py-4">
                                    <p class="font-black text-slate-900">{{ $product->product_name }}</p>
                                    <p class="text-xs font-medium text-slate-500">{{ $product->product_code }}</p>
                                </td>
                                <td class="px-3 py-4 font-semibold text-slate-600">{{ $product->category->category_name }}</td>
                                <td class="px-3 py-4 font-black text-slate-700">₱{{ number_format((float) $product->price, 2) }}</td>
                                <td class="px-3 py-4"><span class="font-black {{ $isLowStock ? 'text-rose-600' : 'text-slate-700' }}">{{ $product->quantity }}</span><span class="block text-xs font-medium text-slate-500">Alert at {{ $product->low_stock_threshold }}</span></td>
                                <td class="px-3 py-4"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-black {{ $isLowStock ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-700' }}">{{ $isLowStock ? 'Low-Stock' : 'Available' }}</span></td>
                                <td class="px-3 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <a class="rounded-lg border-2 border-slate-300 px-3 py-2 text-xs font-black text-slate-700 transition hover:border-rose-400 hover:text-rose-700" href="{{ route('products.edit', $product) }}">Edit</a>
                                        <form method="POST" action="{{ route('products.archive', $product) }}" onsubmit="return confirm('Move this product to the Archive?');">
                                            @csrf
                                            @method('PATCH')
                                            <button class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-black text-rose-700 transition hover:bg-rose-100" type="submit">Archive</button>
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
