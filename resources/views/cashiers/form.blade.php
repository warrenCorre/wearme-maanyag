@extends('layouts.app')

@section('title', ($cashier->exists ? 'Edit Cashier' : 'Add Cashier') . ' - Wear Me Maanyag')
@section('page_title', $cashier->exists ? 'Edit Cashier Account' : 'Add Cashier Account')
@section('page_description', 'Set up the login details used by a cashier in the store system.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-800 transition hover:bg-slate-900 hover:text-white" href="{{ route('cashiers.index') }}">Back to Cashiers</a>
@endsection

@section('content')
    <form class="space-y-6" method="POST" action="{{ $cashier->exists ? route('cashiers.update', $cashier) : route('cashiers.store') }}">
        @csrf
        @if ($cashier->exists)
            @method('PUT')
        @endif

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex items-center gap-3 border-b-2 border-slate-100 pb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-xl">👤</div>
                <div>
                    <h2 class="text-lg font-black">Cashier details</h2>
                    <p class="text-xs font-medium text-slate-500">Cashier Management creates and updates login accounts only.</p>
                </div>
            </div>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <div>
                    <label class="text-sm font-bold text-slate-700" for="name">Full Name</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="name" name="name" value="{{ old('name', $cashier->name) }}" maxlength="150" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="email">Email</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="email" id="email" name="email" value="{{ old('email', $cashier->email) }}" maxlength="150" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="phone">Phone</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="phone" name="phone" value="{{ old('phone', $cashier->phone) }}" maxlength="20" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="address">Address</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="address" name="address" value="{{ old('address', $cashier->address ?? 'Wear Me Maanyag Thrift Store') }}" maxlength="255" required>
                </div>
                <div class="md:col-span-2 border-t-2 border-slate-100 pt-5">
                    <p class="text-xs font-black uppercase tracking-wide text-slate-500">{{ $cashier->exists ? 'Leave blank to keep the current password' : 'Login password' }}</p>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="password">Password {{ $cashier->exists ? '(optional)' : '' }}</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="password" id="password" name="password" minlength="8" {{ $cashier->exists ? '' : 'required' }}>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="password_confirmation">Confirm Password</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="password" id="password_confirmation" name="password_confirmation" minlength="8" {{ $cashier->exists ? '' : 'required' }}>
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <a class="rounded-xl border-2 border-slate-900 bg-white px-4 py-2.5 text-sm font-black text-slate-700 transition hover:bg-slate-900 hover:text-white" href="{{ route('cashiers.index') }}">Cancel</a>
            <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-5 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" type="submit">{{ $cashier->exists ? 'Save Cashier' : 'Create Cashier' }}</button>
        </div>
    </form>
@endsection
