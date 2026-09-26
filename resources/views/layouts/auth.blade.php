<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Wear Me Maanyag')</title>
    @vite('resources/css/app.css')
</head>

<body class="min-h-screen bg-[#f8f8f6] text-slate-900">
    <div class="min-h-screen lg:grid lg:grid-cols-2">
        <aside class="relative hidden overflow-hidden bg-slate-900 text-white lg:flex lg:flex-col lg:justify-between lg:p-12">
            <div class="flex items-center gap-4">
                <div class="flex h-16 w-16 items-center justify-center rounded-full border-2 border-pink-400 bg-slate-800 text-center text-sm font-black leading-tight text-pink-500">
                    WM<br>MM
                </div>
                <div>
                    <p class="text-xl font-black leading-none tracking-tight">Wear Me</p>
                    <p class="text-xl font-black leading-none tracking-tight">Maanyag</p>
                    <p class="mt-1.5 text-[10px] font-bold uppercase tracking-[0.22em] text-slate-400">Thrift Store</p>
                </div>
            </div>

            <div class="max-w-md">
                <p class="text-xs font-black uppercase tracking-[0.25em] text-rose-500">Inventory &amp; Sales</p>
                <h1 class="mt-4 text-4xl font-black leading-tight tracking-tight">Manage your store in one place.</h1>
                <p class="mt-4 text-sm font-medium leading-relaxed text-slate-400">Products, walk-in and online sales, unpaid balances, receipts, and reports — built for the Wear Me Maanyag team.</p>
            </div>

            <p class="text-xs font-bold text-slate-500">&copy; {{ date('Y') }} Wear Me Maanyag Thrift Store</p>

            <div class="pointer-events-none absolute -right-24 -top-24 h-72 w-72 rounded-full bg-rose-500/20"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-20 h-80 w-80 rounded-full bg-rose-500/10"></div>
        </aside>

        <main class="flex min-h-screen flex-col px-5 py-8 sm:px-10 lg:justify-center lg:px-16">
            <div class="mb-8 flex items-center gap-3 lg:hidden">
                <div class="flex h-12 w-12 items-center justify-center rounded-full border-2 border-pink-400 bg-white text-center text-[10px] font-black leading-tight text-pink-500">
                    WM<br>MM
                </div>
                <div>
                    <p class="text-lg font-black leading-none tracking-tight">Wear Me Maanyag</p>
                    <p class="mt-1 text-[10px] font-bold uppercase tracking-[0.18em] text-slate-500">Thrift Store</p>
                </div>
            </div>

            <div class="mx-auto w-full max-w-md">
                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
