<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class AssistantAnswerService
{
    public function __construct(
        private AssistantQuestionPolicy $questionPolicy,
        private AssistantContextService $contextService,
        private AssistantPromptBuilder $promptBuilder,
        private GeminiClient $geminiClient,
        private AssistantResponseValidator $responseValidator,
    ) {}

    /**
     * @return array{
     *     status: 'declined'|'disabled'|'answered'|'refused'|'invalid'|'unavailable',
     *     message: string,
     *     sources?: array<int, array{type: string, label: string, as_of: string}>,
     *     answer_type?: string,
     *     confidence?: string
     * }
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
        $intent = $decision['intent'] ?? '';
        $context = $this->contextService->build($decision['period'] ?? 'monthly', $date);
        $relevantContext = $this->contextForIntent($context, $intent);
        $allowedSources = $this->responseValidator->allowedSourcesFor($intent, $context);
        $relevantContext['allowed_sources'] = $allowedSources;
        $prompt = $this->promptBuilder->build($decision['safe_question'] ?? '', $relevantContext);

        try {
            $rawResponse = $this->geminiClient->generateText(
                $prompt['input'],
                $prompt['system_instruction'],
                AssistantResponseValidator::RESPONSE_SCHEMA,
            );
        } catch (ConnectionException|RequestException|RuntimeException) {
            return [
                'status' => 'unavailable',
                'message' => 'The assistant is temporarily unavailable. Please try again.',
            ];
        }

        $validation = $this->responseValidator->validate($rawResponse, $relevantContext, $allowedSources);

        if (! $validation['valid'] || $validation['response'] === null) {
            return [
                'status' => 'invalid',
                'message' => 'The assistant could not produce a verified answer. Please try again.',
            ];
        }

        $response = $validation['response'];

        return [
            'status' => $response['refusal'] ? 'refused' : 'answered',
            'message' => $response['answer'],
            'sources' => $response['sources'],
            'answer_type' => $response['answer_type'],
            'confidence' => $response['confidence'],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function contextForIntent(array $context, string $intent): array
    {
        $payload = match ($intent) {
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

        return $this->withAsOf($payload, $context, $intent);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withAsOf(array $payload, array $context, string $intent): array
    {
        $key = match ($intent) {
            'sales_summary' => 'sales',
            'inventory_summary', 'low_stock' => 'inventory',
            'best_sellers' => 'best_sellers',
            'unpaid_summary' => 'unpaid_summary',
            default => null,
        };

        if ($key === null) {
            return $payload;
        }

        $asOf = is_array($context['as_of'] ?? null) ? $context['as_of'] : [];

        if (is_string($asOf[$key] ?? null)) {
            $payload['as_of'] = [$key => $asOf[$key]];
        }

        return $payload;
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
