@extends('layouts.app')

@section('title', 'New Sale - Wear Me Maanyag')
@section('page_title', 'New Sale')
@section('page_description', 'Create a paid sale while the system validates and deducts shared inventory atomically.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('sales') }}">Back to Sales</a>
@endsection

@section('content')
    @if ($products->isEmpty())
        <div class="rounded-xl border-2 border-amber-700 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900" role="alert">There are no active products with available stock. Add or restore a product before recording a sale.</div>
    @endif

    <form class="space-y-6" method="POST" action="{{ route('sales.store') }}">
        @csrf
        <input type="hidden" name="payment_status" value="paid">

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex items-center gap-3 border-b-2 border-slate-100 pb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-xl">▤</div>
                <div>
                    <h2 class="text-lg font-black">Sale details</h2>
                    <p class="text-xs font-medium text-slate-500">External online sales are entered manually; no platform integration is used.</p>
                </div>
            </div>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <fieldset>
                    <legend class="text-sm font-bold text-slate-700">Sale Type</legend>
                    <div class="mt-2 flex gap-3">
                        <label class="flex min-h-11 flex-1 cursor-pointer items-center gap-2 rounded-xl border-2 border-slate-300 px-3 text-sm font-bold has-[:checked]:border-rose-500 has-[:checked]:bg-rose-50">
                            <input class="accent-rose-600" type="radio" name="sale_type" value="walk_in" checked>
                            Walk-In
                        </label>
                        <label class="flex min-h-11 flex-1 cursor-pointer items-center gap-2 rounded-xl border-2 border-slate-300 px-3 text-sm font-bold has-[:checked]:border-rose-500 has-[:checked]:bg-rose-50">
                            <input class="accent-rose-600" type="radio" name="sale_type" value="online">
                            Online
                        </label>
                    </div>
                </fieldset>

                <div id="online-platform-field" class="hidden">
                    <label class="text-sm font-bold text-slate-700" for="online_platform">Online Platform</label>
                    <select class="mt-2 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="online_platform" name="online_platform">
                        <option value="">Select a platform</option>
                        <option value="TikTok">TikTok</option>
                        <option value="Facebook">Facebook</option>
                        <option value="Messenger">Messenger</option>
                    </select>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex flex-wrap items-end justify-between gap-3 border-b-2 border-slate-100 pb-4">
                <div>
                    <p class="text-sm font-black uppercase tracking-wide text-rose-500">Shared inventory</p>
                    <h2 class="mt-1 text-lg font-black">Products in this sale</h2>
                </div>
                <button class="rounded-xl border-2 border-slate-900 px-3 py-2 text-xs font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" id="add-item" type="button" {{ $products->isEmpty() ? 'disabled' : '' }}>Add Item +</button>
            </div>

            <div class="mt-5 space-y-3" id="sale-items">
                <div class="grid gap-3 rounded-xl border-2 border-slate-200 bg-slate-50 p-4 md:grid-cols-[minmax(0,1fr)_180px_auto]" data-sale-item>
                    <div>
                        <label class="text-xs font-black uppercase tracking-wide text-slate-500" for="product_0">Product</label>
                        <select class="mt-2 w-full rounded-lg border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="product_0" name="items[0][product_id]" required {{ $products->isEmpty() ? 'disabled' : '' }}>
                            <option value="">Select a product</option>
                            @foreach ($products as $product)
                                <option value="{{ $product->id }}">{{ $product->product_name }} — {{ $product->category->category_name }} ({{ $product->quantity }} available)</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-black uppercase tracking-wide text-slate-500" for="quantity_0">Quantity</label>
                        <input class="mt-2 w-full rounded-lg border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="quantity_0" name="items[0][quantity]" type="number" min="1" value="1" required {{ $products->isEmpty() ? 'disabled' : '' }}>
                    </div>
                    <button class="self-end rounded-lg bg-rose-50 px-3 py-2.5 text-xs font-black text-rose-700 transition hover:bg-rose-100" data-remove-item type="button" hidden>Remove</button>
                </div>
            </div>

            <p class="mt-4 text-xs font-medium text-slate-500">The server recalculates each product price and total from the database at submission time.</p>
        </section>

        <div class="flex justify-end">
            <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-5 py-2.5 text-sm font-black text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50" type="submit" {{ $products->isEmpty() ? 'disabled' : '' }}>Record Paid Sale</button>
        </div>
    </form>

    <script>
        const saleItems = document.getElementById('sale-items');
        const addItemButton = document.getElementById('add-item');
        let itemIndex = 1;

        function updateRemoveButtons() {
            const rows = saleItems.querySelectorAll('[data-sale-item]');
            rows.forEach((row) => {
                row.querySelector('[data-remove-item]').hidden = rows.length === 1;
            });
        }

        addItemButton?.addEventListener('click', () => {
            const firstRow = saleItems.querySelector('[data-sale-item]');
            const newRow = firstRow.cloneNode(true);

            newRow.innerHTML = newRow.innerHTML.replaceAll('[0]', `[${itemIndex}]`);
            newRow.querySelector('select').value = '';
            newRow.querySelector('input').value = 1;
            newRow.querySelector('[data-remove-item]').hidden = false;
            saleItems.appendChild(newRow);
            itemIndex += 1;
            updateRemoveButtons();
        });

        saleItems.addEventListener('click', (event) => {
            const removeButton = event.target.closest('[data-remove-item]');
            if (removeButton) {
                removeButton.closest('[data-sale-item]').remove();
                updateRemoveButtons();
            }
        });

        const platformField = document.getElementById('online-platform-field');
        const platformSelect = document.getElementById('online_platform');

        document.querySelectorAll('input[name="sale_type"]').forEach((radio) => {
            radio.addEventListener('change', () => {
                const isOnline = radio.value === 'online' && radio.checked;
                platformField.classList.toggle('hidden', !isOnline);
                platformSelect.required = isOnline;
                if (!isOnline) platformSelect.value = '';
            });
        });
    </script>
@endsection
