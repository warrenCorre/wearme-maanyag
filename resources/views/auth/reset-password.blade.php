@extends('layouts.auth')

@section('title', 'Reset Password - Wear Me Maanyag')

@section('content')
    <div>
        <p class="text-xs font-black uppercase tracking-[0.2em] text-rose-500">Wear Me Maanyag</p>
        <h1 class="mt-2 text-3xl font-black tracking-tight">Set a new password</h1>
        <p class="mt-2 text-sm font-medium leading-relaxed text-slate-500">Choose a strong password to secure your account.</p>
    </div>

    @if ($errors->any())
        <div class="mt-6 rounded-xl border-2 border-rose-700 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">
            <ul class="list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form class="mt-6 rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]" method="POST" action="{{ route('password.update') }}">
        @csrf

        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label class="block text-sm font-black" for="email">Email</label>
            <input
                class="mt-2 w-full rounded-xl border-2 border-slate-900 bg-[#f8f8f6] px-4 py-3 text-sm font-medium outline-none transition focus:bg-white focus:ring-4 focus:ring-rose-200"
                type="email"
                id="email"
                name="email"
                value="{{ old('email', $email) }}"
                autocomplete="email"
                required
            >
        </div>

        <div class="mt-5">
            <label class="block text-sm font-black" for="password">New Password</label>
            <input
                class="mt-2 w-full rounded-xl border-2 border-slate-900 bg-[#f8f8f6] px-4 py-3 text-sm font-medium outline-none transition focus:bg-white focus:ring-4 focus:ring-rose-200"
                type="password"
                id="password"
                name="password"
                placeholder="••••••••"
                autocomplete="new-password"
                required
            >
        </div>

        <div class="mt-5">
            <label class="block text-sm font-black" for="password_confirmation">Confirm New Password</label>
            <input
                class="mt-2 w-full rounded-xl border-2 border-slate-900 bg-[#f8f8f6] px-4 py-3 text-sm font-medium outline-none transition focus:bg-white focus:ring-4 focus:ring-rose-200"
                type="password"
                id="password_confirmation"
                name="password_confirmation"
                placeholder="••••••••"
                autocomplete="new-password"
                required
            >
        </div>

        <button class="mt-6 w-full rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-3 text-sm font-black text-white shadow-[4px_4px_0_0_#f43f5e] transition hover:-translate-y-0.5 hover:shadow-[5px_5px_0_0_#f43f5e] active:translate-y-0 active:shadow-[3px_3px_0_0_#f43f5e]" type="submit">
            Reset Password
        </button>

        <p class="mt-5 text-center text-sm font-medium text-slate-500">
            <a class="font-bold text-rose-600 transition hover:text-rose-500 hover:underline" href="{{ route('login') }}">Back to Login</a>
        </p>
    </form>
@endsection
