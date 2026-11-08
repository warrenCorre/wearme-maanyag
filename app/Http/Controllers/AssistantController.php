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

        return view('assistant.index');
    }

    public function ask(AssistantRequest $request, AssistantAnswerService $answerService): View|JsonResponse
    {
        /** @var array{question: string} $validated */
        $validated = $request->validated();
        $visitContext = $request->session()->cache()->get('assistant.visit_context');
        $trustedVisitContext = is_array($visitContext) && ($visitContext['owner_id'] ?? null) === $request->user()->id
            ? $visitContext : null;
        $result = $answerService->answer(
            $validated['question'],
            null,
            $trustedVisitContext,
        );

        $request->session()->cache()->forget('assistant.visit_context');

        if ($result['status'] === 'answered' && isset($result['visit_context'])) {
            $request->session()->cache()->put('assistant.visit_context', [
                'owner_id' => $request->user()->id,
                'intent' => $result['visit_context']['intent'],
            ], now()->addMinutes(20));
        } elseif ($result['status'] === 'social' && in_array($trustedVisitContext['intent'] ?? null, [
            'sales_summary', 'inventory_summary', 'low_stock', 'best_sellers', 'collection_dates', 'unpaid_summary',
        ], true)) {
            $request->session()->cache()->put('assistant.visit_context', [
                'owner_id' => $request->user()->id,
                'intent' => $trustedVisitContext['intent'],
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
