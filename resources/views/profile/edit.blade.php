@extends('layouts.app')

@section('title', 'My Profile - Wear Me Maanyag')
@section('page_title', 'My Profile')
@section('page_description', 'Update your own name, email, and password.')

@section('content')
    <form class="space-y-6" method="POST" action="{{ route('profile.update') }}">
        @csrf
        @method('PATCH')

        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <div class="flex items-center gap-3 border-b-2 border-slate-100 pb-4">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-rose-50 text-xl">👤</div>
                <div>
                    <h2 class="text-lg font-black">Account details</h2>
                    <p class="text-xs font-medium text-slate-500">Signed in as {{ str_replace('_', ' ', $user->role) }}. Changes apply to your account only.</p>
                </div>
            </div>

            <div class="mt-6 grid gap-5 md:grid-cols-2">
                <div>
                    <label class="text-sm font-bold text-slate-700" for="name">Full Name</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="text" id="name" name="name" value="{{ old('name', $user->name) }}" maxlength="150" required>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="email">Email</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="email" id="email" name="email" value="{{ old('email', $user->email) }}" maxlength="150" required>
                </div>
                <div class="md:col-span-2 border-t-2 border-slate-100 pt-5">
                    <p class="text-xs font-black uppercase tracking-wide text-slate-500">Leave blank to keep the current password</p>
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="password">New Password (optional)</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="password" id="password" name="password" minlength="8">
                </div>
                <div>
                    <label class="text-sm font-bold text-slate-700" for="password_confirmation">Confirm New Password</label>
                    <input class="mt-2 w-full rounded-xl border-2 border-slate-300 px-3 py-2.5 text-sm outline-none focus:border-rose-500 focus:ring-2 focus:ring-rose-100" type="password" id="password_confirmation" name="password_confirmation" minlength="8">
                </div>
            </div>
        </section>

        <div class="flex flex-wrap items-center justify-end gap-3">
            <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-5 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" type="submit">Save Profile</button>
        </div>
    </form>
@endsection
