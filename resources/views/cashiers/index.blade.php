@extends('layouts.app')

@section('title', 'Cashier Management - Wear Me Maanyag')
@section('page_title', 'Cashier Management')
@section('page_description', 'Create, update, activate, deactivate, and delete cashier accounts.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('cashiers.create') }}">Add Cashier +</a>
@endsection

@section('content')
    <section class="grid gap-4 sm:grid-cols-2">
        <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-slate-500">Total cashiers</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $cashiers->count() }}</p>
            <p class="mt-1 text-xs font-bold text-slate-500">All cashier accounts</p>
        </div>
        <div class="rounded-2xl border-2 border-slate-900 bg-emerald-50 p-5 shadow-[4px_4px_0_0_#0f172a]">
            <p class="text-sm font-black uppercase tracking-wide text-emerald-700">Active</p>
            <p class="mt-2 text-3xl font-black text-slate-900">{{ $activeCount }}</p>
            <p class="mt-1 text-xs font-bold text-slate-600">Cashiers able to log in</p>
        </div>
    </section>

    <section class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[5px_5px_0_0_#0f172a] sm:p-6">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">User management</p>
                <h2 class="mt-1 text-xl font-black">All Cashier Accounts</h2>
            </div>
            <p class="text-xs font-bold text-slate-500">Deactivation blocks login without deleting history.</p>
        </div>

        @if ($cashiers->isEmpty())
            <p class="mt-6 rounded-xl bg-slate-50 p-4 text-sm font-medium text-slate-500">No cashier accounts have been created yet.</p>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="w-full min-w-[760px] text-left text-sm">
                    <thead class="border-b-2 border-slate-900 text-xs uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-3 py-3">Name</th>
                            <th class="px-3 py-3">Email</th>
                            <th class="px-3 py-3">Phone</th>
                            <th class="px-3 py-3">Status</th>
                            <th class="px-3 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($cashiers as $cashier)
                            <tr class="transition hover:bg-slate-50">
                                <td class="px-3 py-4"><p class="font-black text-slate-900">{{ $cashier->name }}</p></td>
                                <td class="px-3 py-4 font-semibold text-slate-600">{{ $cashier->email }}</td>
                                <td class="px-3 py-4 font-semibold text-slate-600">{{ $cashier->phone ?: '—' }}</td>
                                <td class="px-3 py-4">
                                    <span class="inline-flex rounded-full px-2.5 py-1 text-xs font-black {{ $cashier->status === 'active' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-200 text-slate-600' }}">{{ $cashier->status === 'active' ? 'Active' : 'Inactive' }}</span>
                                </td>
                                <td class="px-3 py-4">
                                    <div class="flex flex-wrap gap-2">
                                        <a class="rounded-lg border-2 border-slate-300 px-3 py-2 text-xs font-black text-slate-700 transition hover:border-rose-400 hover:text-rose-700" href="{{ route('cashiers.edit', $cashier) }}">Edit</a>
                                        @if ($cashier->id !== auth()->id())
                                            <form method="POST" action="{{ route('cashiers.status', $cashier) }}" onsubmit="return confirm('{{ $cashier->status === 'active' ? 'Deactivate' : 'Reactivate' }} this cashier account?');">
                                                @csrf
                                                @method('PATCH')
                                                <button class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-black text-rose-700 transition hover:bg-rose-100" type="submit">{{ $cashier->status === 'active' ? 'Deactivate' : 'Reactivate' }}</button>
                                            </form>
                                            @if ($cashier->status === 'inactive')
                                                <form method="POST" action="{{ route('cashiers.destroy', $cashier) }}" onsubmit="return confirm('Permanently delete this cashier account? Past sales keep the cashier name, but the account cannot be restored.');">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="rounded-lg bg-rose-600 px-3 py-2 text-xs font-black text-white transition hover:bg-rose-700" type="submit">Delete</button>
                                                </form>
                                            @endif
                                        @endif
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
