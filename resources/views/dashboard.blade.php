@extends('layouts.app')

@section('title', 'Dashboard - Wear Me Maanyag')
@section('page_title', 'Dashboard')
@section('page_description', 'A quick starting point for the Store Owner workspace.')

@section('content')
    <section class="grid gap-5 md:grid-cols-2">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Sales Summary</p>
            <h2 class="mt-2 text-2xl font-black">Coming next</h2>
            <p class="mt-3 text-sm font-medium leading-relaxed text-slate-600">Daily sales, weekly totals, and transaction summaries will be connected when the Sales module is implemented.</p>
        </div>

        <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Inventory Status</p>
            <h2 class="mt-2 text-2xl font-black">Manage your products</h2>
            <p class="mt-3 text-sm font-medium leading-relaxed text-slate-600">Add products, update stock details, and identify low-stock items from the Product Management screen.</p>
            <a class="mt-6 inline-flex rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('products.index') }}">Open Products Management</a>
        </div>
    </section>
@endsection
