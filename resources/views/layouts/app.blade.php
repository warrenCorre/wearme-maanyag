@php
    $isOwner = auth()->user()->role === 'store_owner';
    $navigation = [
        ['label' => 'Dashboard', 'icon' => '⌂', 'route' => 'dashboard', 'pattern' => 'dashboard', 'owner' => true],
        ['label' => 'Products Management', 'icon' => '▣', 'route' => 'products.index', 'pattern' => 'products.*', 'owner' => true],
        ['label' => 'Sales Transaction', 'icon' => '▤', 'route' => 'sales', 'pattern' => 'sales', 'owner' => false],
        ['label' => 'Unpaid Transactions', 'icon' => '₱', 'route' => 'unpaid.index', 'pattern' => 'unpaid.*', 'owner' => true],
        ['label' => 'Reports', 'icon' => '▥', 'route' => null, 'pattern' => null, 'owner' => true],
        ['label' => 'Cashier Management', 'icon' => '♙', 'route' => null, 'pattern' => null, 'owner' => true],
    ];
@endphp

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Wear Me Maanyag')</title>
    @vite('resources/css/app.css')
    @vite('resources/js/app.js')
</head>

<body class="min-h-screen bg-[#f8f8f6] text-slate-900">
    <div class="min-h-screen lg:flex">
        <aside class="border-b-2 border-slate-900 bg-white lg:sticky lg:top-0 lg:h-screen lg:w-72 lg:shrink-0 lg:border-b-0 lg:border-r-2">
            <div class="flex h-full flex-col">
                <div class="border-b-2 border-slate-900 px-5 py-6">
                    <div class="flex items-center gap-3">
                        <div class="flex h-14 w-14 items-center justify-center rounded-full border-2 border-pink-400 bg-amber-50 text-center text-xs font-black leading-tight text-pink-500">
                            WM<br>MM
                        </div>
                        <div>
                            <p class="text-lg font-black leading-none tracking-tight">Wear Me</p>
                            <p class="text-lg font-black leading-none tracking-tight">Maanyag</p>
                            <p class="mt-1 text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">Thrift Store</p>
                        </div>
                    </div>
                </div>

                <div class="border-b border-slate-200 px-5 py-5">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-full bg-slate-900 text-lg font-bold text-white">
                            {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold text-slate-900">{{ auth()->user()->name }}</p>
                            <p class="text-xs font-medium capitalize text-slate-500">{{ str_replace('_', ' ', auth()->user()->role) }}</p>
                        </div>
                    </div>
                </div>

                <nav class="flex-1 space-y-1 px-3 py-5" aria-label="Main navigation">
                    @foreach ($navigation as $item)
                        @if (!$item['owner'] || $isOwner)
                            @php
                                $isActive = $item['pattern'] && request()->routeIs($item['pattern']);
                                if ($item['pattern'] === 'products.*' && request()->routeIs('categories.*')) {
                                    $isActive = true;
                                }
                            @endphp

                            @if ($item['route'])
                                <a class="group flex items-center gap-3 rounded-xl border-l-4 px-4 py-3 text-sm font-bold transition {{ $isActive ? 'border-rose-500 bg-rose-50 text-rose-600' : 'border-transparent text-slate-700 hover:border-slate-300 hover:bg-slate-50' }}" href="{{ route($item['route']) }}" @if ($isActive) aria-current="page" @endif>
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg text-lg {{ $isActive ? 'bg-rose-100' : 'bg-slate-100 group-hover:bg-white' }}">{{ $item['icon'] }}</span>
                                    <span>{{ $item['label'] }}</span>
                                </a>
                            @else
                                <div class="flex cursor-not-allowed items-center gap-3 rounded-xl border-l-4 border-transparent px-4 py-3 text-sm font-bold text-slate-400" title="This module is coming soon">
                                    <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 text-lg">{{ $item['icon'] }}</span>
                                    <span>{{ $item['label'] }}</span>
                                </div>
                            @endif
                        @endif
                    @endforeach

                    @if (!$isOwner)
                        <div class="flex cursor-not-allowed items-center gap-3 rounded-xl border-l-4 border-transparent px-4 py-3 text-sm font-bold text-slate-400" title="This module is currently available for Store Owners first">
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-slate-100 text-lg">₱</span>
                            <span>Unpaid Transactions</span>
                        </div>
                        @php($receiptActive = request()->routeIs('receipts.*'))
                        <a class="group flex items-center gap-3 rounded-xl border-l-4 px-4 py-3 text-sm font-bold transition {{ $receiptActive ? 'border-rose-500 bg-rose-50 text-rose-600' : 'border-transparent text-slate-700 hover:border-slate-300 hover:bg-slate-50' }}" href="{{ route('receipts.index') }}" @if ($receiptActive) aria-current="page" @endif>
                            <span class="flex h-7 w-7 items-center justify-center rounded-lg text-lg {{ $receiptActive ? 'bg-rose-100' : 'bg-slate-100 group-hover:bg-white' }}">R</span>
                            <span>Receipt Generation</span>
                        </a>
                    @endif
                </nav>

                <div class="border-t border-slate-200 px-4 py-4">
                    <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-4">
                        <div class="flex items-center gap-2 text-slate-900">
                            <span class="text-2xl">◌</span>
                            <span class="text-sm font-black">Maanyag Assistant</span>
                        </div>
                        <p class="mt-2 text-xs font-medium leading-relaxed text-slate-600">Store Owner AI assistance will appear here when its documented module is implemented.</p>
                    </div>

                    <form class="mt-4" method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="w-full rounded-xl border-2 border-slate-900 px-4 py-2.5 text-sm font-bold text-slate-800 transition hover:bg-slate-900 hover:text-white" type="submit">Logout</button>
                    </form>
                </div>
            </div>
        </aside>

        <main class="min-w-0 flex-1">
            <div class="mx-auto max-w-7xl px-5 py-6 sm:px-8 lg:px-10 lg:py-8">
                <header class="flex flex-col gap-5 border-b-2 border-slate-900 pb-6 sm:flex-row sm:items-end sm:justify-between">
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.2em] text-rose-500">Wear Me Maanyag</p>
                        <h1 class="mt-2 text-3xl font-black tracking-tight sm:text-4xl">@yield('page_title')</h1>
                        @hasSection('page_description')
                            <p class="mt-2 max-w-2xl text-sm font-medium text-slate-500">@yield('page_description')</p>
                        @endif
                    </div>

                    @yield('header_actions')
                </header>

                @if (session('status'))
                    <div class="mt-6 rounded-xl border-2 border-emerald-700 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-800" role="status">{{ session('status') }}</div>
                @endif

                @if ($errors->any())
                    <div class="mt-6 rounded-xl border-2 border-rose-700 bg-rose-50 px-4 py-3 text-sm font-semibold text-rose-800" role="alert">
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <div class="mt-8">
                    @yield('content')
                </div>
            </div>
        </main>
    </div>

    @stack('scripts')
</body>
</html>
