@extends('layouts.app')

@section('title', 'Category Configuration - Wear Me Maanyag')
@section('page_title', 'Category Configuration')
@section('page_description', 'Supporting data used by the Category field inside Product Management.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('products.index') }}">Back to Products</a>
@endsection

@section('content')
    <div class="grid gap-6 lg:grid-cols-[minmax(0,0.8fr)_minmax(0,1.6fr)]">
        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-rose-500">Supporting data</p>
            <h2 class="mt-1 text-xl font-black">Add Category</h2>

            <form class="mt-6 space-y-4" method="POST" action="{{ route('categories.store') }}">
                @csrf
                <div>
                    <label class="text-sm font-bold text-slate-700" for="category_name">Category Name</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none transition focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="category_name" name="category_name" value="{{ old('category_name') }}" maxlength="100" required>
                </div>
                <button class="w-full rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" type="submit">Add Category</button>
            </form>
        </section>

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <p class="text-sm font-black uppercase tracking-wide text-rose-500">Product labels</p>
                    <h2 class="mt-1 text-xl font-black">Existing Categories</h2>
                </div>
                <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">{{ $categories->count() }} total</span>
            </div>

            @if ($categories->isEmpty())
                <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">No categories have been created yet.</p>
            @else
                <div class="mt-6 overflow-x-auto">
                    <table class="w-full min-w-[620px] text-left text-sm">
                        <thead class="border-b-2 border-slate-900 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-3 py-3">Name</th>
                                <th class="px-3 py-3">Status</th>
                                <th class="px-3 py-3">Products</th>
                                <th class="px-3 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($categories as $category)
                                <tr>
                                    <td class="px-3 py-4 align-top">
                                        <form class="flex flex-wrap items-center gap-2" method="POST" action="{{ route('categories.update', $category) }}">
                                            @csrf
                                            @method('PUT')
                                            <label class="sr-only" for="category_name_{{ $category->id }}">Category Name</label>
                                            <input class="w-44 rounded-lg border-2 border-slate-300 px-3 py-2 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="category_name_{{ $category->id }}" name="category_name" value="{{ $category->category_name }}" maxlength="100" required>
                                            <input type="hidden" name="status" value="{{ $category->status }}">
                                            <button class="rounded-lg border-2 border-slate-300 px-3 py-2 text-xs font-black text-slate-700 transition hover:border-rose-400 hover:text-rose-700" type="submit">Save</button>
                                        </form>
                                    </td>
                                    <td class="px-3 py-4 align-top"><span class="inline-flex rounded-full px-2.5 py-1 text-xs font-bold {{ $category->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ ucfirst($category->status) }}</span></td>
                                    <td class="px-3 py-4 align-top font-bold text-slate-700">{{ $category->products_count }}</td>
                                    <td class="px-3 py-4 align-top">
                                        <form method="POST" action="{{ route('categories.status', $category) }}">
                                            @csrf
                                            @method('PATCH')
                                            <button class="rounded-lg px-3 py-2 text-xs font-black transition {{ $category->status === 'active' ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' }}" type="submit">{{ $category->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    </div>
@endsection
