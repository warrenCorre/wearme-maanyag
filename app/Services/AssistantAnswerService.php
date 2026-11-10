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
        private AssistantConversationService $conversationService,
        private AssistantLatestUnpaidSaleService $latestUnpaidSaleService,
        private AssistantSafeChatService $safeChatService,
        private AssistantStoreTurnScreen $storeTurnScreen,
        private AssistantModelIntentService $modelIntentService,
    ) {}

    /**
     * @return array{
     *     status: 'declined'|'disabled'|'answered'|'social'|'general_chat'|'clarification'|'refused'|'invalid'|'unavailable',
     *     message: string,
     *     sources?: array<int, array{type: string, label: string, as_of: string}>,
     *     answer_type?: string,
     *     confidence?: string,
     *     visit_context?: array{intent?: string, pending?: string, chat_topic?: string, store_topic?: string, chat_history?: list<array{turn: string, reply: string}>}
     * }
     */
    public function answer(string $question, ?CarbonInterface $referenceDate = null, ?array $visitContext = null): array
    {
        $decision = $this->questionPolicy->evaluate($question, $referenceDate, $visitContext);

        if (! $decision['allowed'] && ($decision['model_candidate'] ?? false)) {
            $screened = $this->storeTurnScreen->screen($question);

            if ($screened !== null) {
                if ($screened['corrected']) {
                    $decision = $this->questionPolicy->evaluate($screened['turn'], $referenceDate, $visitContext);
                }

                if (! $decision['allowed']) {
                    $canonicalQuestion = $this->modelIntentService->suggest($question);

                    if ($canonicalQuestion !== null) {
                        $decision = $this->questionPolicy->evaluate($canonicalQuestion, $referenceDate);
                    }
                }
            }
        }

        if (! $decision['allowed']) {
            return [
                'status' => 'declined',
                'message' => $decision['reason'] ?? 'That question is not supported.',
            ];
        }

        if ($decision['intent'] === 'local_social') {
            if ($decision['social_reply'] === 'cancel_latest_pending_sale') {
                return ['status' => 'social', 'message' => "Okay, I won't look up a sale."];
            }

            return [
                'status' => 'social',
                'message' => $this->conversationService->reply('social', $decision['social_reply']) ?? match ($decision['social_reply']) {
                    'hi' => 'Hi! I am Maanyag. Happy to chat briefly or help with a supported store question.',
                    'hello' => 'Hello! Nice to hear from you. Want to chat briefly or ask a supported store question?',
                    'hey' => 'Hey! I am here for a quick chat or a supported store question.',
                    'morning' => 'Good morning! I am here for a quick chat or a supported store question.',
                    'afternoon' => 'Good afternoon! I am here for a quick chat or a supported store question.',
                    'evening' => 'Good evening! I am here for a quick chat or a supported store question.',
                    'thanks' => 'You are welcome! I am here if you would like to ask a supported store question.',
                    'check_in' => 'Thanks for checking in! I am here and ready to help with supported store questions.',
                    'help' => 'I am Maanyag. I can chat briefly and help with supported store questions using verified records. I cannot change records or answer unrelated factual questions.',
                    'goodbye' => 'Take care! Come back whenever you have a supported store question.',
                    'joke' => 'A little store-themed joke: Why did the hanger stay calm? It knew how to hang in there.',
                    default => 'Hi! I am Maanyag. How can I help with the store today?',
                },
            ];
        }

        if ($decision['intent'] === 'general_chat') {
            $conversationEnabled = config('services.gemini.allow_conversation', false);
            $history = $conversationEnabled
                ? $this->safeChatService->screenedHistory($visitContext['chat_history'] ?? []) : [];

            if (count($history) >= AssistantSafeChatService::MAX_CHAT_EXCHANGES) {
                return [
                    'status' => 'general_chat',
                    'message' => 'This chat has reached its 40-exchange public memory limit. Start a New Chat to keep chatting.',
                    'visit_context' => ['chat_topic' => 'thrift', 'chat_history' => $history],
                ];
            }

            $turn = $decision['chat_turn'];
            $storeTopic = is_string($visitContext['store_topic'] ?? null) ? $visitContext['store_topic'] : null;
            $reply = $this->safeChatService->reply($turn, $history, $storeTopic);

            if ($conversationEnabled) {
                $history[] = ['turn' => $turn, 'reply' => $reply];
            }

            return [
                'status' => 'general_chat',
                'message' => $reply,
                'visit_context' => [
                    'chat_topic' => 'thrift',
                    ...($conversationEnabled ? ['chat_history' => $history] : []),
                ],
            ];
        }

        if ($decision['intent'] === 'clarification') {
            $topic = $decision['clarification'];

            return [
                'status' => 'clarification',
                'message' => $this->conversationService->reply('clarification', $topic) ?? match ($topic) {
                    'sales_goal' => 'For sales, would you like totals, transaction counts, best sellers, or unpaid balances?',
                    'sales_period' => 'Which period for sales totals: today, yesterday, this week, this month, or this year?',
                    'unpaid_goal' => 'Do you mean the aggregate unpaid balance or the latest pending sale? I can show either one.',
                    'latest_pending_sale_confirmation' => 'Do you mean the latest pending unpaid sale? Reply yes to look it up, or no to cancel.',
                },
                'visit_context' => ['pending' => $topic],
            ];
        }

        if ($decision['intent'] === 'latest_unpaid_sale') {
            return [
                'status' => 'answered',
                'message' => $this->latestUnpaidSaleService->answer(),
                'visit_context' => ['store_topic' => 'unpaid'],
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
            ...(! $response['refusal'] ? ['visit_context' => ['intent' => $intent]] : []),
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
            'collection_dates' => $this->withScope(
                ['collection_dates' => $context['collection_dates'] ?? []],
                $context,
                'collection_dates',
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
            'collection_dates' => 'collection_dates',
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
