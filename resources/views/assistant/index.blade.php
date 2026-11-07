@extends('layouts.app')

@section('title', 'Maanyag Assistant - Wear Me Maanyag')
@section('page_title', 'Maanyag Assistant')
@section('page_description', 'Ask read-only questions about verified store records. The assistant cannot change records.')

@section('content')
    @php
        $suggestedQuestions = [
            ['label' => 'Show me sales for this month.', 'question' => 'Show me sales for this month'],
            ['label' => 'Summarize inventory status.', 'question' => 'Summarize inventory status'],
            ['label' => 'List low-stock products.', 'question' => 'List low-stock products'],
            ['label' => 'List best-selling products.', 'question' => 'List best-selling products'],
            ['label' => 'List recently collected products.', 'question' => 'List recently collected products'],
            ['label' => 'How many unpaid transactions?', 'question' => 'How many unpaid transactions?'],
        ];
        $starterPrompts = [
            ['label' => 'Check Inventory', 'question' => 'Summarize inventory status'],
            ['label' => 'Sales Insights', 'question' => 'Show me sales for this month'],
            ['label' => 'Best Sellers', 'question' => 'List best-selling products'],
        ];
    @endphp

    <div class="grid gap-5 xl:grid-cols-[16rem_minmax(0,1fr)]">
        <aside class="order-2 space-y-4 xl:order-1" aria-label="Assistant shortcuts and limits">
            <div class="rounded-2xl border-2 border-slate-900 bg-white p-5 shadow-[4px_4px_0_0_#0f172a]">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-rose-500">Maanyag</p>
                <a class="mt-4 hidden items-center justify-center gap-2 rounded-xl border-2 border-slate-900 bg-slate-900 px-4 py-3 text-sm font-black text-white transition hover:bg-rose-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500 xl:flex" href="{{ route('assistant.index') }}">
                    <span aria-hidden="true">＋</span> New Chat
                </a>
                <p class="mt-3 text-xs font-medium leading-relaxed text-slate-500">Follow up during this visit. New Chat and returning here start fresh; chats are not saved.</p>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 bg-white p-5">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-rose-500">Supported questions</p>
                <div class="mt-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-1">
                    @foreach ($suggestedQuestions as $suggestion)
                        <form method="POST" action="{{ route('assistant.ask') }}" data-assistant-question-form>
                            @csrf
                            <button class="w-full rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-left text-sm font-semibold leading-snug text-slate-700 transition hover:border-rose-400 hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-rose-500" type="submit" name="question" value="{{ $suggestion['question'] }}">
                                {{ $suggestion['label'] }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>

            <div class="rounded-2xl border-2 border-slate-900 bg-amber-50 p-5">
                <p class="text-xs font-black uppercase tracking-[0.18em] text-rose-500">Read-only</p>
                <p class="mt-2 text-sm font-medium leading-relaxed text-slate-700">
                    The assistant can explain verified totals, but it cannot create, edit, delete, archive, or settle records.
                </p>
            </div>

            @if (! config('services.gemini.allow_store_data', false))
                <div class="rounded-2xl border-2 border-slate-900 bg-slate-50 p-5">
                    <p class="text-xs font-black uppercase tracking-[0.18em] text-rose-500">Live answers paused</p>
                    <p class="mt-2 text-sm font-medium leading-relaxed text-slate-700">
                        Store-data sharing with Gemini is disabled until the Owner approves the account and data-handling arrangement.
                    </p>
                </div>
            @endif
        </aside>

        <section class="order-1 flex min-w-0 flex-col overflow-hidden rounded-2xl border-2 border-slate-900 bg-white shadow-[5px_5px_0_0_#0f172a] xl:order-2" aria-label="Assistant conversation">
            <header class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 bg-amber-50/60 px-5 py-5 sm:px-7">
                <div class="flex min-w-0 items-start gap-3">
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border-2 border-slate-900 bg-rose-100 text-xl text-rose-600" aria-hidden="true">◌</span>
                    <div>
                        <p class="text-xs font-black uppercase tracking-[0.18em] text-rose-500">Owner assistant · Read-only</p>
                        <h2 class="mt-1 text-lg font-black leading-tight text-slate-900 sm:text-xl">Your smart assistant, Maanyag!</h2>
                        <p class="mt-1 text-xs font-medium text-slate-600">Grounded answers to supported store questions.</p>
                    </div>
                </div>
                <a class="rounded-xl border-2 border-slate-900 bg-white px-3 py-2 text-xs font-black text-slate-900 transition hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500 xl:hidden" href="{{ route('assistant.index') }}">New Chat</a>
            </header>

            <div id="assistant-messages" class="flex min-h-[28rem] flex-1 flex-col gap-6 bg-[#fcfcfb] px-5 py-7 sm:px-7 xl:min-h-[36rem]" aria-live="polite">
                @isset($result)
                    @php
                        $resultClasses = match ($result['status']) {
                            'answered' => 'border-emerald-700 bg-emerald-50 text-emerald-950',
                            'declined', 'refused' => 'border-amber-700 bg-amber-50 text-amber-950',
                            default => 'border-slate-400 bg-slate-50 text-slate-800',
                        };
                        $resultLabel = match ($result['status']) {
                            'answered' => 'Answer',
                            'declined' => 'Not supported',
                            'refused' => 'Not enough information',
                            'disabled' => 'Live answers paused',
                            'invalid' => 'Could not verify',
                            default => 'Temporarily unavailable',
                        };
                    @endphp

                    <div class="flex justify-end gap-3">
                        <div class="min-w-0 max-w-[85%] rounded-2xl rounded-tr-sm bg-slate-900 px-5 py-4 text-white shadow-sm sm:max-w-[75%]">
                            <p class="text-xs font-black uppercase tracking-[0.14em] text-rose-200">You</p>
                            <p class="mt-2 wrap-break-word whitespace-pre-wrap text-sm font-medium leading-relaxed">{{ $question }}</p>
                        </div>
                    </div>

                    <div class="flex items-start gap-3" role="status">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border-2 border-slate-900 bg-rose-100 text-rose-600" aria-hidden="true">◌</span>
                        <div class="min-w-0 max-w-[85%] rounded-2xl rounded-tl-sm border-2 px-5 py-4 sm:max-w-[75%] {{ $resultClasses }}">
                            <p class="text-xs font-black uppercase tracking-[0.14em]">Maanyag · {{ $resultLabel }}</p>
                            <p class="mt-3 wrap-break-word whitespace-pre-line text-sm font-medium leading-relaxed">{{ $result['message'] }}</p>
                            @if ($result['status'] === 'answered' && ! empty($result['sources']))
                                @foreach ($result['sources'] as $source)
                                    <p class="mt-4 border-t border-current/20 pt-3 text-xs font-semibold">Source: {{ $source['label'] }} · As of {{ $source['as_of'] }}</p>
                                @endforeach
                            @endif
                        </div>
                    </div>
                @else
                    <div id="assistant-welcome" class="flex flex-1 flex-col items-center justify-center text-center">
                        <span class="flex h-16 w-16 items-center justify-center rounded-3xl border-2 border-slate-900 bg-rose-100 text-3xl text-rose-600 shadow-[4px_4px_0_0_#0f172a]" aria-hidden="true">◌</span>
                        <h3 class="mt-6 text-2xl font-black tracking-tight text-slate-900">Hi! How can I help you today?</h3>
                        <p class="mt-2 max-w-md text-sm font-medium leading-relaxed text-slate-600">Ask about sales, inventory, best sellers, collection dates, or aggregate unpaid balances.</p>
                        <div class="mt-6 flex flex-wrap justify-center gap-2">
                            @foreach ($starterPrompts as $starterPrompt)
                                <form method="POST" action="{{ route('assistant.ask') }}" data-assistant-question-form>
                                    @csrf
                                    <button class="rounded-full border-2 border-rose-500 bg-white px-4 py-2 text-xs font-black text-rose-600 transition hover:bg-rose-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" type="submit" name="question" value="{{ $starterPrompt['question'] }}">{{ $starterPrompt['label'] }}</button>
                                </form>
                            @endforeach
                        </div>
                        <p class="mt-5 text-xs font-semibold text-slate-500">Choose a shortcut or type your own supported question.</p>
                    </div>
                @endisset
            </div>

            <template id="assistant-exchange-template">
                <div class="flex justify-end gap-3">
                    <div class="min-w-0 max-w-[85%] rounded-2xl rounded-tr-sm bg-slate-900 px-5 py-4 text-white shadow-sm sm:max-w-[75%]">
                        <p class="text-xs font-black uppercase tracking-[0.14em] text-rose-200">You</p>
                        <p data-assistant-question class="mt-2 wrap-break-word whitespace-pre-wrap text-sm font-medium leading-relaxed"></p>
                    </div>
                </div>
                <div class="flex items-start gap-3" role="status">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border-2 border-slate-900 bg-rose-100 text-rose-600" aria-hidden="true">◌</span>
                    <div data-assistant-result class="min-w-0 max-w-[85%] rounded-2xl rounded-tl-sm border-2 px-5 py-4 sm:max-w-[75%]">
                        <p data-assistant-label class="text-xs font-black uppercase tracking-[0.14em]"></p>
                        <p data-assistant-answer class="mt-3 wrap-break-word whitespace-pre-line text-sm font-medium leading-relaxed"></p>
                        <div data-assistant-sources></div>
                    </div>
                </div>
            </template>

            <form method="POST" action="{{ route('assistant.ask') }}" class="border-t border-slate-200 bg-white px-5 py-5 sm:px-7" data-assistant-question-form>
                @csrf
                <label class="text-xs font-black uppercase tracking-[0.18em] text-rose-500" for="question">Ask Maanyag</label>
                <div class="mt-2 flex items-end gap-2 rounded-2xl border-2 border-slate-900 bg-white p-2 focus-within:border-rose-500">
                    <textarea
                        class="min-h-12 max-h-40 min-w-0 flex-1 resize-y border-0 px-2 py-2 text-sm font-medium text-slate-900 placeholder:text-slate-400 focus:outline-none"
                        id="question"
                        name="question"
                        rows="2"
                        maxlength="500"
                        required
                        placeholder="Ask a supported store question..."
                    >{{ old('question') }}</textarea>
                    <button class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-900 text-white transition hover:bg-rose-500 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-rose-500" type="submit" aria-label="Send question">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h15m-6-6 6 6-6 6" /></svg>
                    </button>
                </div>
                <p class="mt-2 text-xs font-medium text-slate-500">Enter to send · Shift+Enter for a new line. Maximum 500 characters. Ask follow-ups about supported store topics; individual-sale questions are not yet available.</p>
            </form>
        </section>
    </div>
@endsection

@push('scripts')
    <script>
        const assistantQuestion = document.getElementById('question');

        assistantQuestion.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey || event.isComposing || event.keyCode === 229) {
                return;
            }

            event.preventDefault();

            if (!event.repeat && assistantQuestion.value.trim() !== '') {
                assistantQuestion.form.requestSubmit();
            }
        });

        const assistantMessages = document.getElementById('assistant-messages');
        const assistantTemplate = document.getElementById('assistant-exchange-template');
        const assistantForms = document.querySelectorAll('[data-assistant-question-form]');
        let assistantBusy = false;

        const appendAssistantExchange = (question, result) => {
            const exchange = assistantTemplate.content.cloneNode(true);
            const statuses = {
                answered: ['Answer', 'border-emerald-700', 'bg-emerald-50', 'text-emerald-950'],
                declined: ['Not supported', 'border-amber-700', 'bg-amber-50', 'text-amber-950'],
                refused: ['Not enough information', 'border-amber-700', 'bg-amber-50', 'text-amber-950'],
                disabled: ['Live answers paused', 'border-slate-400', 'bg-slate-50', 'text-slate-800'],
                invalid: ['Could not verify', 'border-slate-400', 'bg-slate-50', 'text-slate-800'],
                unavailable: ['Temporarily unavailable', 'border-slate-400', 'bg-slate-50', 'text-slate-800'],
            };
            const [label, ...classes] = statuses[result.status] ?? statuses.unavailable;

            exchange.querySelector('[data-assistant-question]').textContent = question;
            exchange.querySelector('[data-assistant-label]').textContent = `Maanyag · ${label}`;
            exchange.querySelector('[data-assistant-answer]').textContent = result.message;
            exchange.querySelector('[data-assistant-result]').classList.add(...classes);

            if (result.status === 'answered' && Array.isArray(result.sources)) {
                const sources = exchange.querySelector('[data-assistant-sources]');

                for (const source of result.sources) {
                    const line = document.createElement('p');
                    line.className = 'mt-4 border-t border-current/20 pt-3 text-xs font-semibold';
                    line.textContent = `Source: ${source.label} · As of ${source.as_of}`;
                    sources.appendChild(line);
                }
            }

            document.getElementById('assistant-welcome')?.remove();
            assistantMessages.appendChild(exchange);
            assistantMessages.lastElementChild.scrollIntoView({ block: 'nearest' });
        };

        for (const form of assistantForms) {
            form.addEventListener('submit', async (event) => {
                if (!window.fetch) {
                    return;
                }

                event.preventDefault();

                if (assistantBusy) {
                    return;
                }

                const data = new FormData(form);

                if (event.submitter?.name === 'question') {
                    data.set('question', event.submitter.value);
                }

                const question = String(data.get('question') ?? '').trim();

                if (!question) {
                    return;
                }

                assistantBusy = true;

                try {
                    const response = await fetch(form.action, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: data,
                    });

                    if (!response.ok) {
                        throw new Error('Assistant request failed');
                    }

                    const { result } = await response.json();
                    appendAssistantExchange(question, result);
                    assistantQuestion.value = '';
                } catch {
                    appendAssistantExchange(question, {
                        status: 'unavailable',
                        message: 'The assistant is temporarily unavailable. Please try again.',
                    });
                } finally {
                    assistantBusy = false;
                    assistantQuestion.focus();
                }
            });
        }

        window.addEventListener('pageshow', (event) => {
            if (event.persisted) {
                window.location.reload();
            }
        });
    </script>
@endpush
