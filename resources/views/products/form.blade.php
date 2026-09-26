@extends('layouts.app')

@section('title', ($product->exists ? 'Edit Product' : 'Add Product') . ' - Wear Me Maanyag')
@section('page_title', $product->exists ? 'Edit Product' : 'Add Product')
@section('page_description', 'Record the product details used by the shared store inventory.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('products.index') }}">Back to Products</a>
@endsection

@section('content')
    @if ($categories->isEmpty())
        <div class="mb-6 rounded-xl border-2 border-amber-700 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900" role="alert">No active product categories are available. Product creation is unavailable until categories are configured.</div>
    @endif

    <form class="space-y-6" method="POST" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}" enctype="multipart/form-data">
        @csrf
        @if ($product->exists)
            @method('PUT')
        @endif

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex items-center gap-3 border-b-2 border-slate-100 pb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-xl">▣</div>
                <div>
                    <h2 class="text-lg font-black">Product details</h2>
                    <p class="text-xs font-medium text-slate-500">Category belongs inside this Product Management workflow.</p>
                </div>
            </div>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <div>
                    <label class="text-sm font-bold text-slate-700" for="product_code">Product Code / SKU</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="product_code" name="product_code" value="{{ old('product_code', $product->product_code) }}" maxlength="50" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="product_name">Product Name</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="product_name" name="product_name" value="{{ old('product_name', $product->product_name) }}" maxlength="150" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="category_id">Category</label>
                    <select class="mt-2 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="category_id" name="category_id" required {{ $categories->isEmpty() ? 'disabled' : '' }}>
                        <option value="">Select a category</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('category_id', $product->category_id) === (string) $category->id)>{{ $category->category_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="price">Price</label>
                    <div class="mt-2 flex rounded-xl border-2 border-slate-300 bg-white focus-within:border-rose-500 focus-within:ring-2 focus-within:ring-rose-100">
                        <span class="flex items-center px-3 text-sm font-bold text-slate-500">₱</span>
                        <input class="w-full rounded-r-xl border-0 px-2 py-2.5 text-sm outline-none" type="number" id="price" name="price" value="{{ old('price', $product->price) }}" min="0" step="0.01" required>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="quantity">Current Quantity</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="number" id="quantity" name="quantity" value="{{ old('quantity', $product->quantity) }}" min="0" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="low_stock_threshold">Low-stock Threshold</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="number" id="low_stock_threshold" name="low_stock_threshold" value="{{ old('low_stock_threshold', $product->low_stock_threshold) }}" min="0" required>
                </div>
                <div class="md:col-span-2">
                    <label class="text-sm font-bold text-slate-700" for="description">Description</label>
                    <textarea class="mt-2 min-h-28 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="description" name="description" required>{{ old('description', $product->description) }}</textarea>
                </div>
                <div class="md:col-span-2">
                    <label class="text-sm font-bold text-slate-700" for="image">Product Image {{ $product->exists ? '(optional)' : '' }}</label>
                    <input class="mt-2 block w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2 text-sm file:mr-4 file:rounded-lg file:border-0 file:bg-rose-100 file:px-3 file:py-2 file:text-xs file:font-black file:text-rose-700" type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" {{ $product->exists ? '' : 'required' }}>
                    <p class="mt-2 text-xs font-medium text-slate-500">JPG, PNG, or WEBP. Maximum size: 2 MB.</p>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-700 transition hover:bg-slate-900 hover:text-white" href="{{ route('products.index') }}">Cancel</a>
            <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-5 py-2.5 text-sm font-black text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50" type="submit" {{ $categories->isEmpty() ? 'disabled' : '' }}>{{ $product->exists ? 'Save Product' : 'Create Product' }}</button>
        </div>
    </form>
@endsection
