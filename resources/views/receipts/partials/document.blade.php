<article class="receipt-print-area mx-auto w-full max-w-sm bg-white p-6 text-slate-900" style="font-family: Arial, sans-serif;">
    <div class="text-center" style="text-align: center;">
        <p class="text-xl font-black" style="font-size: 1.25rem; font-weight: 900; margin: 0;">Wear Me Maanyag</p>
        <p class="text-xs font-bold uppercase tracking-[0.2em] text-slate-500" style="color: #64748b; font-size: 0.7rem; font-weight: 700; letter-spacing: 0.2em; margin: 0.25rem 0 0; text-transform: uppercase;">Thrift Store</p>
        <p class="mt-3 text-xs font-bold text-slate-500" style="color: #64748b; font-size: 0.75rem; font-weight: 700; margin: 0.75rem 0 0;">Official Sales Receipt</p>
    </div>

    <div class="mt-5 space-y-1 border-y-2 border-slate-900 py-3 text-xs" style="border-bottom: 2px solid #0f172a; border-top: 2px solid #0f172a; font-size: 0.75rem; padding: 0.75rem 0;">
        <div class="flex justify-between gap-4" style="display: flex; justify-content: space-between; gap: 1rem;"><span class="font-bold text-slate-500" style="color: #64748b; font-weight: 700;">Receipt No.</span><span class="font-black" style="font-weight: 900;">{{ $transaction->transaction_no }}</span></div>
        <div class="flex justify-between gap-4" style="display: flex; justify-content: space-between; gap: 1rem;"><span class="font-bold text-slate-500" style="color: #64748b; font-weight: 700;">Date / Time</span><span class="font-bold" style="font-weight: 700;">{{ $transaction->transaction_date?->format('M d, Y g:i A') }}</span></div>
        <div class="flex justify-between gap-4" style="display: flex; justify-content: space-between; gap: 1rem;"><span class="font-bold text-slate-500" style="color: #64748b; font-weight: 700;">Recorded By</span><span class="font-bold" style="font-weight: 700;">{{ $transaction->user?->name }}</span></div>
        <div class="flex justify-between gap-4" style="display: flex; justify-content: space-between; gap: 1rem;"><span class="font-bold text-slate-500" style="color: #64748b; font-weight: 700;">Sale Type</span><span class="font-bold" style="font-weight: 700;">{{ $transaction->sale_type === 'walk_in' ? 'Walk-In' : 'Online' }}{{ $transaction->online_platform ? ' / ' . $transaction->online_platform : '' }}</span></div>
    </div>

    <table class="mt-5 w-full text-xs" style="border-collapse: collapse; font-size: 0.75rem; width: 100%;">
        <thead>
            <tr class="border-b border-slate-300 text-left text-slate-500" style="border-bottom: 1px solid #cbd5e1; color: #64748b; text-align: left;">
                <th class="pb-2 font-black" style="font-weight: 900; padding-bottom: 0.5rem;">Item</th>
                <th class="pb-2 text-center font-black" style="font-weight: 900; padding-bottom: 0.5rem; text-align: center;">Qty</th>
                <th class="pb-2 text-right font-black" style="font-weight: 900; padding-bottom: 0.5rem; text-align: right;">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($transaction->items as $item)
                <tr class="border-b border-slate-100" style="border-bottom: 1px solid #f1f5f9;">
                    <td class="py-2 pr-2 font-bold" style="font-weight: 700; padding: 0.5rem 0.5rem 0.5rem 0;">{{ $item->product?->product_name ?? 'Product unavailable' }}<span class="block text-[10px] font-medium text-slate-400" style="color: #94a3b8; display: block; font-size: 0.625rem; font-weight: 500;">₱{{ number_format((float) $item->unit_price, 2) }} each</span></td>
                    <td class="py-2 text-center font-bold" style="font-weight: 700; padding: 0.5rem 0; text-align: center;">{{ $item->quantity }}</td>
                    <td class="py-2 text-right font-black" style="font-weight: 900; padding: 0.5rem 0; text-align: right;">₱{{ number_format((float) $item->subtotal, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="mt-5 flex justify-between border-t-2 border-slate-900 pt-3 text-base" style="border-top: 2px solid #0f172a; display: flex; justify-content: space-between; padding-top: 0.75rem;">
        <span class="font-black" style="font-weight: 900;">TOTAL</span>
        <span class="font-black" style="font-weight: 900;">₱{{ number_format((float) $transaction->total_amount, 2) }}</span>
    </div>

    <div class="mt-6 text-center text-xs font-bold text-slate-500" style="color: #64748b; font-size: 0.75rem; font-weight: 700; margin-top: 1.5rem; text-align: center;">Thank you for shopping with us!</div>
</article>
