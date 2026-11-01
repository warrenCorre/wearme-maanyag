@extends('layouts.app')

@section('title', 'Maanyag Assistant - Wear Me Maanyag')
@section('page_title', 'Maanyag Assistant')
@section('page_description', 'Ask read-only questions about verified store totals. The assistant cannot change records.')

@section('content')
    <div class="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
        <section class="rounded-2xl border-2 border-slate-900 bg-white p-6 shadow-[5px_5px_0_0_#0f172a]">
            <form method="POST" action="{{ route('assistant.ask') }}" class="space-y-4">
                @csrf

                <div>
                    <label class="text-sm font-black uppercase tracking-wide text-rose-500" for="question">Your question</label>
                    <textarea
                        class="mt-2 w-full rounded-xl border-2 border-slate-900 px-4 py-3 text-sm font-medium focus:border-rose-500 focus:outline-none"
                        id="question"
                        name="question"
                        rows="4"
                        maxlength="500"
                        required
                        placeholder="Example: Show me sales for this month"
                    >{{ old('question', $question ?? '') }}</textarea>
                    <p class="mt-2 text-xs font-medium text-slate-500">Maximum 500 characters. Customer-specific unpaid questions are not supported.</p>
                </div>

                <button class="rounded-xl border-2 border-slate-900 bg-slate-900 px-5 py-2.5 text-sm font-black text-white transition hover:bg-rose-500" type="submit">
                    Ask Assistant
                </button>
            </form>

            @isset($result)
                @php
                    $resultClasses = match ($result['status']) {
                        'answered' => 'border-emerald-700 bg-emerald-50 text-emerald-900',
                        'declined' => 'border-amber-700 bg-amber-50 text-amber-900',
                        default => 'border-slate-700 bg-slate-50 text-slate-800',
                    };
                    $resultLabel = match ($result['status']) {
                        'answered' => 'Answer',
                        'declined' => 'Not supported',
                        default => 'Unavailable',
                    };
                @endphp

                <div class="mt-6 rounded-2xl border-2 p-5 {{ $resultClasses }}" role="status">
                    <p class="text-xs font-black uppercase tracking-[0.18em]">{{ $resultLabel }}</p>
                    <p class="mt-3 whitespace-pre-line text-sm font-medium leading-relaxed">{{ $result['message'] }}</p>
                </div>
            @endisset
        </section>

        <aside class="space-y-4">
            <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-5">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Read-only</p>
                <p class="mt-2 text-sm font-medium leading-relaxed text-slate-700">
                    The assistant can explain verified totals, but it cannot create, edit, delete, archive, or settle records.
                </p>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 bg-white p-5">
                <p class="text-sm font-black uppercase tracking-wide text-rose-500">Supported questions</p>
                <ul class="mt-3 list-disc space-y-2 pl-5 text-sm font-medium leading-relaxed text-slate-700">
                    <li>Show me sales for this month.</li>
                    <li>Summarize inventory status.</li>
                    <li>List low-stock products.</li>
                    <li>List best-selling products.</li>
                    <li>How many unpaid transactions?</li>
                </ul>
            </div>

            @if (! config('services.gemini.allow_store_data', false))
                <div class="rounded-2xl border-2 border-slate-900 bg-slate-50 p-5">
                    <p class="text-sm font-black uppercase tracking-wide text-rose-500">Live answers paused</p>
                    <p class="mt-2 text-sm font-medium leading-relaxed text-slate-700">
                        Store-data sharing with Gemini is disabled until the Owner approves the account and data-handling arrangement.
                    </p>
                </div>
            @endif
        </aside>
    </div>
@endsection
