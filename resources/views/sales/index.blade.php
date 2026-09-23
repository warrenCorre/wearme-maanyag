@extends('layouts.app')

@section('title', 'Sales Transactions - Wear Me Maanyag')
@section('page_title', 'Sales Transactions')
@section('page_description', 'Record and review walk-in and manually entered online sales.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('sales.create') }}">New Sale +</a>
@endsection

@section('content')
    @php($isOwner = auth()->user()->role === 'store_owner')

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
                            @if ($isOwner)
                                <th class="px-3 py-3">Receipt</th>
                            @endif
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
                                <td class="px-3 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-black {{ $transaction->payment_status === 'paid' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-800' }}">{{ ucfirst(str_replace('_', ' ', $transaction->payment_status)) }}</span>
                                </td>
                                @if ($isOwner)
                                    <td class="px-3 py-4">
                                        @if ($transaction->payment_status === 'paid')
                                            <div class="flex flex-wrap gap-2">
                                                <button class="rounded-lg border-2 border-slate-900 bg-white px-2.5 py-1.5 text-xs font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" data-receipt-open="{{ $transaction->id }}" type="button">Print</button>
                                                <a class="rounded-lg border-2 border-slate-900 bg-white px-2.5 py-1.5 text-xs font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('receipts.download', $transaction) }}">Download</a>
                                            </div>
                                            <template id="receipt-template-{{ $transaction->id }}">
                                                @include('receipts.partials.document', ['transaction' => $transaction])
                                            </template>
                                        @else
                                            <span class="text-xs font-bold text-slate-400">Available after settlement</span>
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($isOwner && $transactions->isNotEmpty())
        <div class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/70 p-4" data-receipt-modal role="dialog" aria-labelledby="receipt-modal-title" aria-modal="true">
            <div class="max-h-[92vh] w-full max-w-xl overflow-y-auto rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[7px_7px_0_0_#0f172a] sm:p-6">
                <div class="flex items-start justify-between gap-4 border-b-2 border-slate-100 pb-4">
                    <div>
                        <p class="text-sm font-black uppercase tracking-wide text-rose-500">Receipt preview</p>
                        <h2 class="mt-1 text-xl font-black" id="receipt-modal-title">Sales Receipt</h2>
                    </div>
                    <button class="rounded-lg border-2 border-slate-900 px-3 py-1.5 text-sm font-black transition hover:bg-slate-900 hover:text-white" data-receipt-close type="button" aria-label="Close receipt preview">Close</button>
                </div>
                <div class="mt-5 rounded-xl border-2 border-slate-200 bg-slate-50 p-3 sm:p-5" data-receipt-modal-content></div>
                <div class="mt-5 flex flex-wrap justify-end gap-3">
                    <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" data-receipt-print type="button">Print Receipt</button>
                    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" data-receipt-download href="#">Download Receipt</a>
                </div>
            </div>
        </div>

        <script>
            const receiptModal = document.querySelector('[data-receipt-modal]');
            const receiptModalContent = receiptModal?.querySelector('[data-receipt-modal-content]');
            const receiptDownload = receiptModal?.querySelector('[data-receipt-download]');

            function closeReceiptModal() {
                receiptModal?.classList.add('hidden');
                receiptModal?.classList.remove('flex');
                document.body.classList.remove('overflow-hidden');
            }

            document.querySelectorAll('[data-receipt-open]').forEach((button) => {
                button.addEventListener('click', () => {
                    const template = document.getElementById(`receipt-template-${button.dataset.receiptOpen}`);
                    receiptModalContent.replaceChildren(template.content.cloneNode(true));
                    receiptDownload.href = button.closest('td').querySelector('a').href;
                    receiptModal.classList.remove('hidden');
                    receiptModal.classList.add('flex');
                    document.body.classList.add('overflow-hidden');
                });
            });

            receiptModal?.querySelector('[data-receipt-close]')?.addEventListener('click', closeReceiptModal);
            receiptModal?.addEventListener('click', (event) => {
                if (event.target === receiptModal) closeReceiptModal();
            });
            receiptModal?.querySelector('[data-receipt-print]')?.addEventListener('click', () => window.print());
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') closeReceiptModal();
            });
        </script>
    @endif
@endsection
