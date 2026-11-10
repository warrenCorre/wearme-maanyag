<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantRequest;
use App\Services\AssistantAnswerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AssistantController extends Controller
{
    public function index(Request $request): View
    {
        $request->session()->cache()->forget('assistant.visit_context');
        $request->session()->cache()->forget('assistant.public_chat_history');

        return view('assistant.index');
    }

    public function ask(AssistantRequest $request, AssistantAnswerService $answerService): View|JsonResponse
    {
        /** @var array{question: string} $validated */
        $validated = $request->validated();
        $visitContext = $request->session()->cache()->get('assistant.visit_context');
        $trustedVisitContext = is_array($visitContext) && ($visitContext['owner_id'] ?? null) === $request->user()->id
            ? $visitContext : null;
        $publicChat = $request->session()->cache()->get('assistant.public_chat_history');
        $trustedPublicChat = config('services.gemini.allow_conversation', false)
            && is_array($publicChat) && ($publicChat['owner_id'] ?? null) === $request->user()->id
            ? $publicChat : null;
        $context = $trustedVisitContext ?? [];

        if ($trustedPublicChat !== null) {
            $context['chat_history'] = $trustedPublicChat['chat_history'] ?? [];
            $context['store_topic'] = $trustedPublicChat['store_topic'] ?? null;
        }

        $result = $answerService->answer(
            $validated['question'],
            null,
            $context === [] ? null : $context,
        );

        $request->session()->cache()->forget('assistant.visit_context');

        if (! config('services.gemini.allow_conversation', false)) {
            $request->session()->cache()->forget('assistant.public_chat_history');
        } elseif ($result['status'] === 'general_chat' && is_array($result['visit_context']['chat_history'] ?? null)) {
            $request->session()->cache()->put('assistant.public_chat_history', [
                'owner_id' => $request->user()->id,
                'chat_history' => $result['visit_context']['chat_history'],
                'store_topic' => null,
            ], now()->addMinutes(20));
        } elseif ($trustedPublicChat !== null || in_array($result['status'], ['answered', 'clarification'], true)) {
            $intent = $result['visit_context']['intent'] ?? null;
            $pending = $result['visit_context']['pending'] ?? null;
            $storeTopic = match (true) {
                ($result['visit_context']['store_topic'] ?? null) === 'unpaid', $intent === 'unpaid_summary', in_array($pending, ['unpaid_goal', 'latest_pending_sale_confirmation'], true) => 'unpaid',
                in_array($intent, ['sales_summary', 'best_sellers'], true), in_array($pending, ['sales_goal', 'sales_period'], true) => 'sales',
                in_array($intent, ['inventory_summary', 'low_stock'], true) => 'inventory',
                $intent === 'collection_dates' => 'collections',
                default => $trustedPublicChat !== null ? 'other' : null,
            };

            $request->session()->cache()->put('assistant.public_chat_history', [
                'owner_id' => $request->user()->id,
                'chat_history' => $trustedPublicChat['chat_history'] ?? [],
                'store_topic' => $storeTopic,
            ], now()->addMinutes(20));
        }

        if ($result['status'] === 'answered' && isset($result['visit_context']['intent'])) {
            $request->session()->cache()->put('assistant.visit_context', [
                'owner_id' => $request->user()->id,
                'intent' => $result['visit_context']['intent'],
            ], now()->addMinutes(20));
        } elseif ($result['status'] === 'clarification' && in_array($result['visit_context']['pending'] ?? null, [
            'sales_goal', 'sales_period', 'unpaid_goal', 'latest_pending_sale_confirmation',
        ], true)) {
            $request->session()->cache()->put('assistant.visit_context', [
                'owner_id' => $request->user()->id,
                'pending' => $result['visit_context']['pending'],
            ], now()->addMinutes(20));
        } elseif ($result['status'] === 'general_chat' && ($result['visit_context']['chat_topic'] ?? null) === 'thrift') {
            $request->session()->cache()->put('assistant.visit_context', [
                'owner_id' => $request->user()->id,
                'chat_topic' => 'thrift',
            ], now()->addMinutes(20));
        } elseif ($result['status'] === 'social' && in_array($trustedVisitContext['intent'] ?? null, [
            'sales_summary', 'inventory_summary', 'low_stock', 'best_sellers', 'collection_dates', 'unpaid_summary',
        ], true)) {
            $request->session()->cache()->put('assistant.visit_context', [
                'owner_id' => $request->user()->id,
                'intent' => $trustedVisitContext['intent'],
            ], now()->addMinutes(20));
        } elseif ($result['status'] === 'social' && in_array($trustedVisitContext['pending'] ?? null, [
            'sales_goal', 'sales_period', 'unpaid_goal',
        ], true)) {
            $request->session()->cache()->put('assistant.visit_context', [
                'owner_id' => $request->user()->id,
                'pending' => $trustedVisitContext['pending'],
            ], now()->addMinutes(20));
        }

        unset($result['visit_context']);

        if ($request->expectsJson()) {
            return response()->json(['result' => $result]);
        }

        return view('assistant.index', [
            'question' => $validated['question'],
            'result' => $result,
        ]);
    }
}
