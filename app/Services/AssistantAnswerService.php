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
        private AssistantDamagedAdjustmentService $damagedAdjustmentService,
        private AssistantStockAdjustmentService $stockAdjustmentService,
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
     *     visit_context?: array{intent?: string, pending?: string, period?: ?string, chat_topic?: string, store_topic?: string, chat_history?: list<array{turn: string, reply: string}>, social?: bool}
     * }
     */
    public function answer(string $question, ?CarbonInterface $referenceDate = null, ?array $visitContext = null): array
    {
        $decision = $this->questionPolicy->evaluate($question, $referenceDate, $visitContext);
        $screened = $this->storeTurnScreen->screen($question);
        $knownBestSellerQuestion = $decision['allowed'] && $decision['intent'] === 'best_sellers';

        if (! $decision['allowed'] && $screened !== null && $screened['corrected']) {
            $decision = $this->questionPolicy->evaluate($screened['turn'], $referenceDate, $visitContext);
        }

        if (! $decision['allowed'] && ($visitContext['intent'] ?? null) === 'sales_summary'
            && ($visitContext['pending'] ?? null) === null
            && ($followUp = $this->storeTurnScreen->screenSalesFollowUp($question)) !== null) {
            $canonicalQuestion = 'Show me sales for '.$followUp['period'];
            $modelQuestion = $this->modelIntentService->suggestSalesFollowUp($question);
            $decision = $this->questionPolicy->evaluate($modelQuestion ?? $canonicalQuestion, $referenceDate);
        }

        if ($screened !== null && (($decision['model_candidate'] ?? false)
            || ($decision['allowed'] && ! $screened['corrected'] && in_array($decision['intent'], [
                'sales_summary', 'inventory_summary', 'low_stock', 'collection_dates', 'unpaid_summary',
            ], true)))) {
            $canonicalQuestion = $this->modelIntentService->suggest($question);

            if ($canonicalQuestion !== null) {
                $modelDecision = $this->questionPolicy->evaluate($canonicalQuestion, $referenceDate, $visitContext);

                if ($modelDecision['allowed'] && (! $decision['allowed']
                    || ($modelDecision['intent'] === $decision['intent']
                        && $modelDecision['period'] === $decision['period']
                        && $modelDecision['date'] === $decision['date']))) {
                    $decision = $modelDecision;
                }
            }
        }

        $pendingStoreGoal = ($visitContext['pending'] ?? null) === 'store_goal';
        $pendingPeriod = $pendingStoreGoal && is_string($visitContext['period'] ?? null)
            ? ($this->storeTurnScreen->screenSalesFollowUp('what about '.$visitContext['period'])['period'] ?? null)
            : null;
        $pendingGoal = in_array($visitContext['pending'] ?? null, ['sales_goal', 'sales_period', 'unpaid_goal'], true)
            ? $visitContext['pending'] : null;

        if ((($decision['model_candidate'] ?? false) && $screened === null)
            || ($pendingStoreGoal && $decision['intent'] === 'clarification')) {
            $natural = $this->modelIntentService->interpret($question, $pendingPeriod, $pendingGoal);

            if ($natural['unavailable'] ?? false) {
                if (! $decision['allowed']) {
                    return ['status' => 'unavailable', 'message' => "I couldn't interpret that message just now. Please try again."];
                }

                $natural = null;
            }

            if (isset($natural['chat_turn'])) {
                $decision = ['allowed' => true, 'intent' => 'general_chat', 'chat_turn' => $natural['chat_turn']];
            } elseif ($natural !== null && $natural['question'] === null) {
                $period = $natural['period'];

                return [
                    'status' => 'clarification',
                    'message' => $period !== null
                        ? 'For '.$period.', would you like sales totals or transaction counts?'
                        : 'Which store information would you like: sales, inventory, low stock, best sellers, collections, or aggregate unpaid balances?',
                    'visit_context' => ['pending' => 'store_goal', 'period' => $period],
                ];
            } elseif ($natural !== null) {
                $decision = $this->questionPolicy->evaluate($natural['question'], $referenceDate);
            } elseif ($pendingPeriod !== null && ($decision['clarification'] ?? null) === 'sales_period') {
                $decision = $this->questionPolicy->evaluate('Show me sales for '.$pendingPeriod, $referenceDate);
            }
        }

        if (! $decision['allowed']) {
            return [
                'status' => 'declined',
                'message' => $decision['reason'] ?? 'That question is not supported.',
            ];
        }

        if ($decision['intent'] === 'local_social'
            && $decision['social_reply'] !== 'cancel_latest_pending_sale'
            && config('services.gemini.allow_conversation', false)
            && ($chatTurn = $this->safeChatService->screenedTurn($question)) !== null) {
            $decision = ['allowed' => true, 'intent' => 'general_chat', 'chat_turn' => $chatTurn, 'social_reply' => $decision['social_reply']];
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
                    ...(isset($decision['social_reply']) ? ['social' => true] : []),
                ],
            ];
        }

        if ($decision['intent'] === 'clarification') {
            $topic = $decision['clarification'];

            return [
                'status' => 'clarification',
                'message' => $this->conversationService->reply('clarification', $topic) ?? match ($topic) {
                    'sales_goal' => 'For sales, would you like totals, transaction counts, best sellers, or unpaid balances?',
                    'sales_period' => 'Which period for sales totals: today, yesterday, this week, last week, this month, last month, this year, or last year?',
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

        $adjustmentSourceDate = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::today())->toDateString();

        if ($decision['intent'] === 'damaged_adjustments') {
            return [
                'status' => 'answered',
                'message' => $this->damagedAdjustmentService->answer($decision['period'], CarbonImmutable::parse($decision['date'])),
                'sources' => [[
                    'type' => 'inventory_adjustments',
                    'label' => 'Recorded damaged-item adjustments',
                    'as_of' => $adjustmentSourceDate,
                ]],
                'answer_type' => 'fact',
                'confidence' => 'high',
                'visit_context' => ['intent' => 'damaged_adjustments'],
            ];
        }

        if ($decision['intent'] === 'stock_adjustments') {
            return [
                'status' => 'answered',
                'message' => $this->stockAdjustmentService->answer($decision['period'], CarbonImmutable::parse($decision['date'])),
                'sources' => [[
                    'type' => 'inventory_adjustments',
                    'label' => 'Recorded non-sales stock adjustments',
                    'as_of' => $adjustmentSourceDate,
                ]],
                'answer_type' => 'fact',
                'confidence' => 'high',
                'visit_context' => ['intent' => 'stock_adjustments'],
            ];
        }

        $date = CarbonImmutable::parse($decision['date']);
        $intent = $decision['intent'] ?? '';
        $context = $this->contextService->build($decision['period'] ?? 'monthly', $date);
        $relevantContext = $this->contextForIntent($context, $intent);
        $allowedSources = $this->responseValidator->allowedSourcesFor($intent, $context);

        if ($intent === 'best_sellers' && ($context['best_sellers'] ?? null) === [] && count($allowedSources) === 1) {
            return [
                'status' => 'answered',
                'message' => 'No best-selling products can be ranked from recorded paid sales yet.',
                'sources' => $allowedSources,
                'answer_type' => 'fact',
                'confidence' => 'high',
                'visit_context' => ['intent' => $intent],
            ];
        }

        if ($knownBestSellerQuestion && $screened !== null && ($context['best_sellers'] ?? []) !== []) {
            $canonicalQuestion = $this->modelIntentService->suggest($question);

            if ($canonicalQuestion !== null) {
                $modelDecision = $this->questionPolicy->evaluate($canonicalQuestion, $referenceDate, $visitContext);

                if ($modelDecision['allowed'] && $modelDecision['intent'] === $decision['intent']
                    && $modelDecision['period'] === $decision['period']
                    && $modelDecision['date'] === $decision['date']) {
                    $decision = $modelDecision;
                }
            }
        }

        $relevantContext['allowed_sources'] = $allowedSources;
        $prompt = $this->promptBuilder->build($decision['safe_question'] ?? '', $relevantContext);

        try {
            $rawResponse = $this->geminiClient->generateText(
                $prompt['input'],
                $prompt['system_instruction'],
                AssistantResponseValidator::RESPONSE_SCHEMA,
            );
        } catch (ConnectionException|RequestException|RuntimeException) {
            return $this->verifiedSalesFallback($decision, $relevantContext, $allowedSources)
                ?? $this->verifiedUnpaidFallback($decision, $relevantContext, $allowedSources)
                ?? [
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
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $context
     * @param  array<int, array{type: string, label: string, as_of: string}>  $allowedSources
     * @return array<string, mixed>|null
     */
    private function verifiedSalesFallback(array $decision, array $context, array $allowedSources): ?array
    {
        $sales = $context['sales'] ?? null;
        $summary = is_array($sales) ? ($sales['summary'] ?? null) : null;

        if (($decision['intent'] ?? null) !== 'sales_summary' || ! is_array($summary)
            || count($allowedSources) !== 1 || $allowedSources[0]['type'] !== 'sales'
            || ! in_array($decision['period'] ?? null, ['daily', 'weekly', 'monthly', 'yearly'], true)
            || ($sales['period'] ?? null) !== $decision['period'] || ($sales['date'] ?? null) !== $decision['date']
            || ! is_int($summary['transaction_count'] ?? null) || $summary['transaction_count'] < 0
            || ! is_float($summary['total_sales'] ?? null) || ! is_finite($summary['total_sales'])
            || $summary['total_sales'] < 0) {
            return null;
        }

        $count = $summary['transaction_count'];

        return [
            'status' => 'answered',
            'message' => 'Recorded '.$decision['period'].' sales for '.$decision['date'].': '.$count.' '.($count === 1 ? 'transaction' : 'transactions').' totaling PHP '.number_format($summary['total_sales'], 2, '.', ',').'.',
            'sources' => $allowedSources,
            'answer_type' => 'fact',
            'confidence' => 'high',
            'visit_context' => ['intent' => 'sales_summary'],
        ];
    }

    /**
     * @param  array<string, mixed>  $decision
     * @param  array<string, mixed>  $context
     * @param  array<int, array{type: string, label: string, as_of: string}>  $allowedSources
     * @return array<string, mixed>|null
     */
    private function verifiedUnpaidFallback(array $decision, array $context, array $allowedSources): ?array
    {
        $summary = $context['unpaid_summary'] ?? null;

        if (($decision['intent'] ?? null) !== 'unpaid_summary' || ! is_array($summary)
            || count($allowedSources) !== 1 || $allowedSources[0]['type'] !== 'unpaid_summary'
            || ! is_int($summary['pending_transaction_count'] ?? null) || $summary['pending_transaction_count'] < 0
            || ! is_float($summary['outstanding_balance'] ?? null) || ! is_finite($summary['outstanding_balance'])
            || $summary['outstanding_balance'] < 0) {
            return null;
        }

        $count = $summary['pending_transaction_count'];

        return [
            'status' => 'answered',
            'message' => 'Recorded aggregate unpaid balances: '.$count.' pending '.($count === 1 ? 'sale' : 'sales').' with PHP '.number_format($summary['outstanding_balance'], 2, '.', ',').' outstanding.',
            'sources' => $allowedSources,
            'answer_type' => 'fact',
            'confidence' => 'high',
            'visit_context' => ['intent' => 'unpaid_summary'],
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
