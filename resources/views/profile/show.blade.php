@extends('layouts.app')

@section('title', 'My Profile - Wear Me Maanyag')
@section('page_title', 'My Profile')
@section('page_description', 'View your account details.')

@section('header_actions')
    <a class="rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" href="{{ route('profile.edit') }}">Edit Profile</a>
@endsection

@section('content')
    <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-slate-900 text-3xl font-black text-white">
                {{ strtoupper(substr($user->name, 0, 1)) }}
            </div>
            <div class="min-w-0">
                <h2 class="truncate text-2xl font-black">{{ $user->name }}</h2>
                <p class="mt-1 truncate text-sm font-medium text-slate-500">{{ $user->email }}</p>
                <span class="mt-2 inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-black capitalize text-slate-700">{{ str_replace('_', ' ', $user->role) }}</span>
            </div>
        </div>

        <dl class="mt-6 grid gap-5 border-t-2 border-slate-100 pt-6 md:grid-cols-2">
            <div>
                <dt class="text-sm font-bold text-slate-700">Full Name</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->name }}</dd>
            </div>
            <div>
                <dt class="text-sm font-bold text-slate-700">Email</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->email }}</dd>
            </div>
            <div>
                <dt class="text-sm font-bold text-slate-700">Phone</dt>
                <dd class="mt-1 text-sm font-medium text-slate-900">{{ $user->phone ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-sm font-bold text-slate-700">Role</dt>
                <dd class="mt-1 text-sm font-medium capitalize text-slate-900">{{ str_replace('_', ' ', $user->role) }}</dd>
            </div>
        </dl>
    </section>
@endsection
