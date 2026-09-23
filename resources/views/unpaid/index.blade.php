@extends('layouts.app')

@section('title', 'Unpaid Transactions - Wear Me Maanyag')
@section('page_title', 'Unpaid Transactions')
@section('page_description', 'Track outstanding customer balances and settle them when payment is completed.')

@section('header_actions')
    <a
        class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500"
        href="{{ route('unpaid.create') }}"
    >
        Record Unpaid Item +
    </a>
@endsection

@section('content')
    @php
        $pendingTransactions = $transactions->whereIn('payment_status', ['unpaid', 'partially_paid']);

        $pendingBalance = $pendingTransactions->sum(function ($transaction) {
            return $transaction->unpaidPayments->sum(fn ($payment) => (float) $payment->balance);
        });
    @endphp

    <section class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-slate-500">
                Unpaid transactions
            </p>

            <p class="mt-2 text-3xl font-black">
                {{ $pendingTransactions->count() }}
            </p>
        </div>

        <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">
                Outstanding balance
            </p>

            <p class="mt-2 text-2xl font-black">
                ₱{{ number_format($pendingBalance, 2) }}
            </p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
        <div class="border-b-2 border-slate-100 pb-4">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">
                Payment tracking
            </p>

            <h2 class="mt-1 text-xl font-black">
                Customer Balances
            </h2>
        </div>

        @if ($pendingTransactions->isEmpty())
            <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">
                No unpaid transactions have been recorded yet.
            </p>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[1100px] text-left text-sm">
                    <thead class="border-b-2 border-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Customer</th>
                            <th class="px-3 py-3">Item(s)</th>
                            <th class="px-3 py-3">Sales Type</th>
                            <th class="px-3 py-3">Total / Balance</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3">Date / Time</th>
                            <th class="px-3 py-3">Actions</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">
                        @foreach ($pendingTransactions as $transaction)
                            @php
                                $payments = $transaction->unpaidPayments;

                                $customerName = $payments->first()?->customer_name ?? 'Unknown Customer';
                                $customerContact = $payments->first()?->customer_contact ?? 'No contact';

                                $itemDescriptions = $payments
                                    ->pluck('item_description')
                                    ->filter()
                                    ->unique()
                                    ->values();

                                $totalBalance = $payments->sum(fn ($payment) => (float) $payment->balance);

                                $date = $transaction->transaction_date;

                                $paymentStatus = $transaction->payment_status === 'partially_paid'
                                    ? 'Partially Paid'
                                    : 'Unpaid';
                            @endphp

                            <tr class="transition hover:bg-slate-50">
                                <td class="px-3 py-4">
                                    <p class="font-black text-slate-900">
                                        {{ $customerName }}
                                    </p>

                                    <p class="mt-1 text-xs font-medium text-slate-500">
                                        {{ $customerContact }}
                                    </p>
                                </td>

                                <td class="px-3 py-4">
                                    @if ($transaction->items->isNotEmpty())
                                        <div class="space-y-1">
                                            @foreach ($transaction->items as $item)
                                                <p class="font-bold text-slate-700">
                                                    {{ $item->product?->product_name ?? 'Product unavailable' }}
                                                    <span class="text-xs font-medium text-slate-400">
                                                        × {{ $item->quantity }}
                                                    </span>
                                                </p>
                                            @endforeach
                                        </div>
                                    @else
                                        <p class="font-bold text-slate-700">
                                            Product unavailable
                                        </p>
                                    @endif

                                    @if ($itemDescriptions->isNotEmpty())
                                        <p class="mt-2 text-xs font-medium text-slate-500">
                                            {{ $itemDescriptions->join(', ') }}
                                        </p>
                                    @endif
                                </td>

                                <td class="px-3 py-4 text-slate-600">
                                    {{ $transaction->sale_type === 'online' ? 'Online' : 'Walk-In' }}

                                    @if ($transaction->online_platform)
                                        <span class="block text-xs font-medium text-slate-400">
                                            {{ $transaction->online_platform }}
                                        </span>
                                    @endif
                                </td>

                                <td class="px-3 py-4">
                                    <p class="font-black text-slate-700">
                                        ₱{{ number_format((float) $transaction->total_amount, 2) }}
                                    </p>

                                    <p class="mt-1 text-xs font-bold text-rose-600">
                                        Balance: ₱{{ number_format($totalBalance, 2) }}
                                    </p>
                                </td>

                                <td class="px-3 py-4">
                                    <span
                                        class="inline-flex rounded-full px-2.5 py-1 text-xs font-black
                                        {{ $transaction->payment_status === 'partially_paid'
                                            ? 'bg-blue-100 text-blue-700'
                                            : 'bg-amber-100 text-amber-800' }}"
                                    >
                                        {{ $paymentStatus }}
                                    </span>
                                </td>

                                <td class="px-3 py-4 text-slate-600">
                                    {{ $date?->format('M d, Y g:i A') ?? '—' }}
                                </td>

                                <td class="px-3 py-4">
                                    <button
                                        type="button"
                                        class="rounded-lg border-2 border-slate-900 bg-white px-3 py-1.5 text-xs font-black text-slate-800 transition hover:bg-slate-900 hover:text-white focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2"
                                        data-transaction-open
                                        data-transaction-url="{{ route('unpaid.transaction.update', $transaction) }}"
                                        data-customer-name="{{ $customerName }}"
                                        data-customer-contact="{{ $customerContact }}"
                                        data-item-description="{{ $itemDescriptions->join(', ') }}"
                                        data-amount-paid="{{ number_format($payments->sum(fn ($payment) => (float) $payment->amount_paid), 2, '.', '') }}"
                                        data-total-amount="{{ number_format((float) $transaction->total_amount, 2, '.', '') }}"
                                        data-balance="{{ number_format($totalBalance, 2, '.', '') }}"
                                    >
                                        Update
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($pendingTransactions->isNotEmpty())
        <dialog
            class="w-full max-w-lg rounded-2xl border-2 border-slate-900 bg-white p-0 shadow-[7px_7px_0_0_#0f172a] backdrop:bg-slate-950/70"
            data-transaction-modal
            aria-labelledby="transaction-modal-title"
        >
            <div class="p-5 sm:p-6">
                <div class="flex items-start justify-between gap-4 border-b-2 border-slate-100 pb-4">
                    <div>
                        <p class="text-sm font-black uppercase tracking-wide text-rose-500">
                            Transaction update
                        </p>

                        <h2
                            class="mt-1 text-xl font-black"
                            id="transaction-modal-title"
                        >
                            Update Unpaid Transaction
                        </h2>
                    </div>

                    <button
                        type="button"
                        class="rounded-lg border-2 border-slate-900 px-3 py-1.5 text-sm font-black transition hover:bg-slate-900 hover:text-white focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2"
                        data-transaction-close
                    >
                        Close
                    </button>
                </div>

                <div class="mt-5 rounded-xl bg-slate-50 p-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div>
                            <p class="text-xs font-black uppercase tracking-wide text-slate-400">
                                Total
                            </p>

                            <p class="mt-1 text-lg font-black text-slate-900">
                                ₱<span data-transaction-total>0.00</span>
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-black uppercase tracking-wide text-slate-400">
                                Paid
                            </p>

                            <p class="mt-1 text-lg font-black text-emerald-600">
                                ₱<span data-transaction-paid>0.00</span>
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-black uppercase tracking-wide text-slate-400">
                                Balance
                            </p>

                            <p class="mt-1 text-lg font-black text-rose-600">
                                ₱<span data-transaction-balance>0.00</span>
                            </p>
                        </div>
                    </div>
                </div>

                <form
                    class="mt-5 space-y-4"
                    method="POST"
                    data-transaction-form
                >
                    @csrf
                    @method('PATCH')

                    <div>
                        <label
                            class="text-sm font-bold text-slate-700"
                            for="transaction_customer_name"
                        >
                            Customer Name
                        </label>

                        <input
                            class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100"
                            id="transaction_customer_name"
                            name="customer_name"
                            type="text"
                            maxlength="150"
                            required
                        >
                    </div>

                    <div>
                        <label
                            class="text-sm font-bold text-slate-700"
                            for="transaction_customer_contact"
                        >
                            Customer Contact
                        </label>

                        <input
                            class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100"
                            id="transaction_customer_contact"
                            name="customer_contact"
                            type="text"
                            maxlength="20"
                            required
                        >
                    </div>

                    <div>
                        <label
                            class="text-sm font-bold text-slate-700"
                            for="transaction_item_description"
                        >
                            Item Description
                        </label>

                        <textarea
                            class="mt-2 min-h-24 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100"
                            id="transaction_item_description"
                            name="item_description"
                            maxlength="255"
                            required
                        ></textarea>
                    </div>

                    <div>
                        <label
                            class="text-sm font-bold text-slate-700"
                            for="transaction_amount_paid"
                        >
                            Total Amount Paid So Far
                        </label>

                        <div class="relative mt-2">
                            <span class="absolute left-3 top-2.5 text-sm font-bold text-slate-500">
                                ₱
                            </span>

                            <input
                                class="w-full rounded-xl border-2 border-slate-300 py-2.5 pl-8 pr-3 text-sm outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100"
                                id="transaction_amount_paid"
                                name="amount_paid"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                            >
                        </div>

                        <p class="mt-1 text-xs font-medium text-slate-500">
                            Enter the customer's cumulative total payment, not only the new payment.
                        </p>
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button
                            type="button"
                            class="rounded-xl border-2 border-slate-900 px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2"
                            data-transaction-cancel
                        >
                            Cancel
                        </button>

                        <button
                            type="submit"
                            class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500 focus:outline-none focus:ring-2 focus:ring-rose-400 focus:ring-offset-2"
                        >
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </dialog>

        <script>
            const transactionModal = document.querySelector('[data-transaction-modal]');
            const transactionForm = transactionModal?.querySelector('[data-transaction-form]');

            const customerNameInput = transactionModal?.querySelector('#transaction_customer_name');
            const customerContactInput = transactionModal?.querySelector('#transaction_customer_contact');
            const itemDescriptionInput = transactionModal?.querySelector('#transaction_item_description');
            const amountPaidInput = transactionModal?.querySelector('#transaction_amount_paid');

            const transactionTotal = transactionModal?.querySelector('[data-transaction-total]');
            const transactionPaid = transactionModal?.querySelector('[data-transaction-paid]');
            const transactionBalance = transactionModal?.querySelector('[data-transaction-balance]');

            function closeTransactionModal() {
                if (!transactionModal) {
                    return;
                }

                transactionModal.close();
                document.body.classList.remove('overflow-hidden');
            }

            document.querySelectorAll('[data-transaction-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!transactionModal || !transactionForm) {
                        return;
                    }

                    transactionForm.action = button.dataset.transactionUrl;

                    customerNameInput.value = button.dataset.customerName ?? '';
                    customerContactInput.value = button.dataset.customerContact ?? '';
                    itemDescriptionInput.value = button.dataset.itemDescription ?? '';
                    amountPaidInput.value = button.dataset.amountPaid ?? '0';

                    transactionTotal.textContent = button.dataset.totalAmount ?? '0.00';
                    transactionPaid.textContent = button.dataset.amountPaid ?? '0.00';
                    transactionBalance.textContent = button.dataset.balance ?? '0.00';

                    transactionModal.showModal();
                    document.body.classList.add('overflow-hidden');

                    customerNameInput.focus();
                });
            });

            transactionModal?.querySelector('[data-transaction-close]')?.addEventListener(
                'click',
                closeTransactionModal
            );

            transactionModal?.querySelector('[data-transaction-cancel]')?.addEventListener(
                'click',
                closeTransactionModal
            );

            transactionModal?.addEventListener('click', (event) => {
                const rect = transactionModal.getBoundingClientRect();

                const clickedInside =
                    event.clientX >= rect.left &&
                    event.clientX <= rect.right &&
                    event.clientY >= rect.top &&
                    event.clientY <= rect.bottom;

                if (!clickedInside) {
                    closeTransactionModal();
                }
            });

            transactionModal?.addEventListener('close', () => {
                document.body.classList.remove('overflow-hidden');
            });
        </script>
    @endif
@endsection