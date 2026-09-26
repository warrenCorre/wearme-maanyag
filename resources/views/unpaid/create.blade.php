@extends('layouts.app')

@section('title', 'Record Unpaid Item - Wear Me Maanyag')
@section('page_title', 'Record Unpaid Item')
@section('page_description', 'Record an item taken by a customer with an outstanding balance against the shared inventory.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('unpaid.index') }}">Back to Unpaid</a>
@endsection

@section('content')
    @if ($products->isEmpty())
        <div class="rounded-xl border-2 border-amber-700 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-900" role="alert">There are no active products with available stock. Add or restore a product before recording an unpaid item.</div>
    @endif

    <form class="space-y-6" method="POST" action="{{ route('unpaid.store') }}">
        @csrf

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="border-b-2 border-slate-100 pb-4">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Transaction details</p>
                <h2 class="mt-1 text-xl font-black">Sale and Item</h2>
            </div>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <div>
                    <label class="text-sm font-bold text-slate-700" for="sale_type">Sales Type</label>
                    <select class="mt-2 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="sale_type" name="sale_type" required>
                        <option value="walk_in" @selected(old('sale_type', 'walk_in') === 'walk_in')>Walk-In</option>
                        <option value="online" @selected(old('sale_type') === 'online')>Online</option>
                    </select>
                </div>

                <div id="online-platform-field" class="{{ old('sale_type') === 'online' ? '' : 'hidden' }}">
                    <label class="text-sm font-bold text-slate-700" for="online_platform">Online Platform</label>
                    <select class="mt-2 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="online_platform" name="online_platform">
                        <option value="">Select a platform</option>
                        @foreach (['TikTok', 'Facebook', 'Messenger'] as $platform)
                            <option value="{{ $platform }}" @selected(old('online_platform') === $platform)>{{ $platform }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-2">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <label class="text-sm font-bold text-slate-700">Items</label>
                            <p class="mt-1 text-xs font-medium text-slate-500">
                                Select one or more products included in this unpaid transaction.
                            </p>
                        </div>

                        <button
                            type="button"
                            id="add-product"
                            class="inline-flex min-h-11 items-center justify-center rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-900 transition hover:bg-slate-900 hover:text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50"
                            {{ $products->isEmpty() ? 'disabled' : '' }}
                        >
                            <span class="mr-2 text-lg leading-none">+</span>
                            Add Product
                        </button>
                    </div>

                    <div
                        id="product-list"
                        class="mt-4 space-y-3"
                        data-product-count="0"
                    ></div>

                    <template id="product-row-template">
                        <div
                            class="product-row rounded-2xl border-2 border-slate-200 bg-slate-50 p-4"
                        >
                            <div class="grid gap-4 md:grid-cols-[minmax(0,1fr)_180px_auto] md:items-end">
                                <div>
                                    <label class="text-sm font-bold text-slate-700">
                                        Product
                                    </label>

                                    <select
                                        class="product-select mt-2 w-full rounded-xl border-2 border-slate-300 bg-white px-3 py-2.5 text-sm outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100"
                                        name="products[__INDEX__][product_id]"
                                        required
                                    >
                                        <option value="">Select a product</option>

                                        @foreach ($products as $product)
                                            <option
                                                value="{{ $product->id }}"
                                                data-stock="{{ $product->quantity }}"
                                                data-price="{{ number_format((float) $product->price, 2, '.', '') }}"
                                            >
                                                {{ $product->product_name }}
                                                — {{ $product->category->category_name }}
                                                ({{ $product->quantity }} available,
                                                ₱{{ number_format((float) $product->price, 2) }})
                                            </option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label class="text-sm font-bold text-slate-700">
                                        Quantity
                                    </label>

                                    <input
                                        class="product-quantity mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100"
                                        name="products[__INDEX__][quantity]"
                                        type="number"
                                        min="1"
                                        value="1"
                                        required
                                    >
                                    <p class="product-stock mt-1 text-xs font-medium text-slate-500"></p>
                                </div>

                                <button
                                    type="button"
                                    class="remove-product inline-flex min-h-11 items-center justify-center rounded-xl border-2 border-slate-300 bg-white px-4 py-2.5 text-sm font-black text-slate-600 transition hover:border-rose-500 hover:bg-rose-50 hover:text-rose-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 focus-visible:ring-offset-2"
                                    aria-label="Remove product"
                                >
                                    Remove
                                </button>
                            </div>
                        </div>
                    </template>

                    @error('products')
                        <p class="mt-2 text-sm font-bold text-rose-600">{{ $message }}</p>
                    @enderror

                    @error('products.*.product_id')
                        <p class="mt-2 text-sm font-bold text-rose-600">{{ $message }}</p>
                    @enderror

                    @error('products.*.quantity')
                        <p class="mt-2 text-sm font-bold text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label class="text-sm font-bold text-slate-700" for="amount_paid">Initial Amount Paid</label>
                    <div class="relative mt-2">
                        <span class="absolute left-3 top-2.5 text-sm font-bold text-slate-500">₱</span>
                        <input class="w-full rounded-xl border-2 border-slate-300 py-2.5 pl-8 pr-3 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="amount_paid" name="amount_paid" type="number" min="0" step="0.01" value="{{ old('amount_paid', '0.00') }}" required {{ $products->isEmpty() ? 'disabled' : '' }}>
                    </div>
                    <p class="mt-1 text-xs font-medium text-slate-500">This is cumulative payment so far; it must remain below the item total.</p>
                </div>
            </div>
        </section>

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="border-b-2 border-slate-100 pb-4">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Customer details</p>
                <h2 class="mt-1 text-xl font-black">Follow-up Information</h2>
            </div>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <div>
                    <label class="text-sm font-bold text-slate-700" for="customer_name">Customer Name</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="customer_name" name="customer_name" type="text" maxlength="150" value="{{ old('customer_name') }}" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="customer_contact">Customer Contact</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="customer_contact" name="customer_contact" type="text" maxlength="20" value="{{ old('customer_contact') }}" required>
                </div>
                <div class="md:col-span-2">
                    <label class="text-sm font-bold text-slate-700" for="item_description">Item Description</label>
                    <textarea class="mt-2 min-h-24 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" id="item_description" name="item_description" maxlength="255" required>{{ old('item_description') }}</textarea>
                </div>
            </div>
        </section>

        <div class="flex justify-end">
            <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-5 py-2.5 text-sm font-black text-white transition hover:bg-rose-500 disabled:cursor-not-allowed disabled:opacity-50" type="submit" {{ $products->isEmpty() ? 'disabled' : '' }}>Record Unpaid Item</button>
        </div>
    </form>

    <script>
        const saleType = document.getElementById('sale_type');
        const platformField = document.getElementById('online-platform-field');
        const platformSelect = document.getElementById('online_platform');

        const productList = document.getElementById('product-list');
        const productTemplate = document.getElementById('product-row-template');
        const addProductButton = document.getElementById('add-product');

        let productIndex = 0;

        saleType?.addEventListener('change', () => {
            const isOnline = saleType.value === 'online';

            platformField.classList.toggle('hidden', !isOnline);
            platformSelect.required = isOnline;

            if (!isOnline) {
                platformSelect.value = '';
            }
        });

        function updateProductStockMessage(row) {
            const select = row.querySelector('.product-select');
            const quantity = row.querySelector('.product-quantity');
            const stockMessage = row.querySelector('.product-stock');

            const selectedOption = select.options[select.selectedIndex];

            if (!selectedOption?.value) {
                quantity.removeAttribute('max');
                stockMessage.textContent = '';
                return;
            }

            const stock = Number(selectedOption.dataset.stock || 0);

            quantity.max = stock;
            stockMessage.textContent = `${stock} available in stock.`;

            if (Number(quantity.value) > stock) {
                quantity.value = stock > 0 ? stock : 1;
            }
        }

        function attachProductRowEvents(row) {
            const select = row.querySelector('.product-select');
            const removeButton = row.querySelector('.remove-product');

            select.addEventListener('change', () => {
                updateProductStockMessage(row);

                document.querySelectorAll('.product-select').forEach((otherSelect) => {
                    if (otherSelect === select) {
                        return;
                    }

                    const selectedValue = select.value;

                    [...otherSelect.options].forEach((option) => {
                        option.disabled = selectedValue !== ''
                            && option.value === selectedValue;
                    });
                });
            });

            row.querySelector('.product-quantity').addEventListener('input', () => {
                updateProductStockMessage(row);
            });

            removeButton.addEventListener('click', () => {
                const rows = productList.querySelectorAll('.product-row');

                if (rows.length === 1) {
                    return;
                }

                row.remove();
            });
        }

        function addProductRow() {
            const fragment = productTemplate.content.cloneNode(true);
            const row = fragment.querySelector('.product-row');

            row.innerHTML = row.innerHTML.replaceAll(
                '__INDEX__',
                productIndex
            );

            productList.appendChild(fragment);

            attachProductRowEvents(productList.lastElementChild);

            productIndex++;
        }

        addProductButton?.addEventListener('click', addProductRow);

        if (productList && productTemplate && !{{ $products->isEmpty() ? 'true' : 'false' }}) {
            addProductRow();
        }
    </script>
@endsection
