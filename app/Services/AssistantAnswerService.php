<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AssistantAnswerService
{
    public function __construct(
        private AssistantQuestionPolicy $questionPolicy,
        private AssistantContextService $contextService,
        private AssistantPromptBuilder $promptBuilder,
        private GeminiClient $geminiClient,
    ) {}

    /**
     * @return array{status: 'declined'|'disabled'|'answered', message: string}
     */
    public function answer(string $question, ?CarbonInterface $referenceDate = null): array
    {
        $decision = $this->questionPolicy->evaluate($question, $referenceDate);

        if (! $decision['allowed']) {
            return [
                'status' => 'declined',
                'message' => $decision['reason'] ?? 'That question is not supported.',
            ];
        }

        if (! config('services.gemini.allow_store_data', false)) {
            return [
                'status' => 'disabled',
                'message' => 'Assistant answers are disabled until sharing store data with Gemini is explicitly approved and enabled.',
            ];
        }

        $date = CarbonImmutable::parse($decision['date']);
        $context = $this->contextService->build($decision['period'] ?? 'monthly', $date);
        $relevantContext = $this->contextForIntent($context, $decision['intent'] ?? '');
        $prompt = $this->promptBuilder->build($decision['safe_question'] ?? '', $relevantContext);
        $answer = $this->geminiClient->generateText($prompt['input'], $prompt['system_instruction']);

        return [
            'status' => 'answered',
            'message' => $answer,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function contextForIntent(array $context, string $intent): array
    {
        return match ($intent) {
            'sales_summary' => $this->withScope(
                ['sales' => $context['sales'] ?? []],
                $context,
                'sales',
            ),
            'inventory_summary' => [
                'inventory' => [
                    'stats' => $context['inventory']['stats'] ?? [],
                ],
            ],
            'low_stock' => [
                'inventory' => [
                    'low_stock_products' => $context['inventory']['low_stock_products'] ?? [],
                ],
            ],
            'best_sellers' => $this->withScope(
                ['best_sellers' => $context['best_sellers'] ?? []],
                $context,
                'best_sellers',
            ),
            'unpaid_summary' => $this->withScope(
                ['unpaid_summary' => $context['unpaid_summary'] ?? []],
                $context,
                'unpaid_summary',
            ),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withScope(array $payload, array $context, string $key): array
    {
        $scopes = is_array($context['scopes'] ?? null) ? $context['scopes'] : [];
        $scope = $scopes[$key] ?? null;

        if (is_string($scope) && $scope !== '') {
            $payload['scopes'] = [$key => $scope];
        }

        return $payload;
    }
}
