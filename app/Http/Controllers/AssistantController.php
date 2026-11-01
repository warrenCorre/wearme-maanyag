<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssistantRequest;
use App\Services\AssistantAnswerService;
use Illuminate\View\View;

class AssistantController extends Controller
{
    public function index(): View
    {
        return view('assistant.index');
    }

    public function ask(AssistantRequest $request, AssistantAnswerService $answerService): View
    {
        /** @var array{question: string} $validated */
        $validated = $request->validated();

        return view('assistant.index', [
            'question' => $validated['question'],
            'result' => $answerService->answer($validated['question']),
        ]);
    }
}
