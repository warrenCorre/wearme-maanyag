<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AssistantQuestionPolicy
{
    public function __construct(private AssistantSafeTurnScreen $turnScreen) {}

    /**
     * @return array{allowed: bool, intent: ?string, period: ?string, date: string, safe_question: ?string, social_reply?: string, clarification?: string, chat_turn?: string, model_candidate?: bool, reason: ?string}
     */
    public function evaluate(string $question, ?CarbonInterface $referenceDate = null, ?array $visitContext = null): array
    {
        $date = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::today());
        $normalizedQuestion = $this->normalize($question);

        if ($normalizedQuestion === '') {
            return $this->denied($date, 'Please ask a supported store question.');
        }

        $socialReply = $this->socialReplyFor($normalizedQuestion);

        if ($socialReply !== null) {
            return $this->localSocial($date, $socialReply);
        }

        if (preg_match('/\b(add|create|record|update|edit|change|delete|remove|archive|settle|pay)\b/', $normalizedQuestion) === 1) {
            return $this->denied($date, 'The assistant cannot perform or arrange write actions.');
        }

        if (preg_match('/\b(customer|client|buyer|person|individual|whose|who|name|contact|phone|email|mobile)\b/', $normalizedQuestion) === 1) {
            return $this->denied($date, 'Customer-specific or identifying questions are not supported.');
        }

        $normalizedQuestion = $this->withoutSocialOpening($normalizedQuestion);

        $pending = $visitContext['pending'] ?? null;

        if ($pending === 'sales_goal') {
            if (in_array($normalizedQuestion, ['totals', 'total', 'sales totals', 'revenue', 'transactions', 'transaction counts'], true)) {
                return $this->clarification($date, 'sales_period');
            }

            if (in_array($normalizedQuestion, ['best sellers', 'top sellers'], true)) {
                return $this->evaluate('List best-selling products', $date);
            }
        }

        if ($pending === 'sales_period' && in_array($normalizedQuestion, ['today', 'yesterday', 'this week', 'this month', 'this year'], true)) {
            return $this->evaluate('Show me sales for '.$normalizedQuestion, $date);
        }

        if ($pending === 'unpaid_goal') {
            if (in_array($normalizedQuestion, ['aggregate', 'total', 'overall', 'balance', 'unpaid balance', 'aggregate unpaid'], true)) {
                return $this->evaluate('What is the total unpaid balance?', $date);
            }

            if (in_array($normalizedQuestion, ['one', 'a sale', 'one sale', 'particular', 'particular sale', 'a particular sale', 'latest', 'latest sale', 'the latest one', 'recent sale', 'specific sale', 'sale details'], true)) {
                return $this->localLatestUnpaidSale($date);
            }
        }

        if (($visitContext['chat_topic'] ?? null) === 'thrift'
            && in_array($normalizedQuestion, ['joke', 'another joke'], true)) {
            return $this->generalChat($date, 'tell me another joke about thrift shopping');
        }

        if (preg_match('/^(?:(?:show(?: me)?|what is|what about) )?(?:the )?(?:latest|most recent) (?:unpaid|pending) (?:sale|transaction)$/', $normalizedQuestion) === 1) {
            return $this->localLatestUnpaidSale($date);
        }

        if (preg_match('/^(?:(?:what|how) about |(?:and|also) )?sales$/', $normalizedQuestion) === 1) {
            return $this->clarification($date, 'sales_goal');
        }

        if (preg_match('/^(?:(?:what|how) about |(?:and|also) )?unpaid$/', $normalizedQuestion) === 1) {
            return $this->clarification($date, 'unpaid_goal');
        }

        if (preg_match('/^(?:(?:show|give|summarize)\s+(?:me\s+)?|what(?:\s+(?:is|are|was|were)|s)\s+)?(?:the\s+)?(?:sales(?:\s+summary)?|total sales|sales total|revenue summary|transaction summary)\s+(?:for|in|during)\s+(today|yesterday|this week|this month|this year|daily|weekly|monthly|yearly)$/', $normalizedQuestion, $matches) === 1) {
            $periodPhrase = $matches[1];
            $period = match ($periodPhrase) {
                'today', 'yesterday', 'daily' => 'daily',
                'this week', 'weekly' => 'weekly',
                'this month', 'monthly' => 'monthly',
                'this year', 'yearly' => 'yearly',
            };

            if ($periodPhrase === 'yesterday') {
                $date = $date->subDay();
            }

            return $this->allowed(
                'sales_summary',
                $period,
                $date,
                "Summarize {$period} sales for {$date->toDateString()} using only the verified context.",
            );
        }

        if (preg_match('/^(?:(?:show|list|give|summarize)\s+(?:me\s+)?|what\s+(?:are|is)\s+)?(?:the\s+)?(?:low stock(?: products| items)?|items? low in stock|inventory summary|inventory status|total inventory|inventory value|stock summary)$/', $normalizedQuestion) === 1) {
            $isLowStockQuestion = preg_match('/low stock|low in stock/', $normalizedQuestion) === 1;

            return $this->allowed(
                $isLowStockQuestion ? 'low_stock' : 'inventory_summary',
                null,
                $date,
                $isLowStockQuestion
                    ? 'List current low-stock products with quantities and thresholds using only the verified context.'
                    : 'Summarize current inventory quantities and value using only the verified context.',
            );
        }

        if (preg_match('/^(?:(?:show|list|give|summarize)\s+(?:me\s+)?|what\s+(?:are|is)\s+)?(?:the\s+)?(?:best(?: selling)? products?|top(?: selling)? products?|best sellers|top sellers)$/', $normalizedQuestion) === 1) {
            return $this->allowed(
                'best_sellers',
                null,
                $date,
                'Summarize the top-selling products by paid units sold and sales total using only the verified context.',
            );
        }

        if (preg_match('/^(?:(?:show|list|give)\s+(?:me\s+)?|what\s+are\s+)?(?:the\s+)?(?:recently collected products|products collected recently|latest collection dates|recent collection dates)$/', $normalizedQuestion) === 1) {
            return $this->allowed(
                'collection_dates',
                null,
                $date,
                'List the most recently collected active products and their recorded collection dates using only the verified context.',
            );
        }

        if (
            preg_match('/^(?:(?:what is|show|give me|summarize|how much(?: is)?)\s+)?(?:the\s+)?(?:total|overall|aggregate)\s+(?:unpaid|outstanding)\s+(?:balance|amount|transactions?|payments?|count)(?:\s+summary)?$/', $normalizedQuestion) === 1
            || preg_match('/^how many (?:unpaid|pending) (?:transactions?|payments?)$/', $normalizedQuestion) === 1
        ) {
            return $this->allowed(
                'unpaid_summary',
                null,
                $date,
                'Report only aggregate pending sale counts and outstanding balances using the verified context. Never disclose customer details.',
            );
        }

        if ($visitContext !== null && in_array($visitContext['intent'] ?? null, [
            'sales_summary', 'inventory_summary', 'low_stock', 'best_sellers', 'collection_dates', 'unpaid_summary',
        ], true)) {
            $followUp = preg_replace('/^(?:(?:and|also|then)\s+|(?:what|how)\s+about\s+)(?:the\s+)?/', '', $normalizedQuestion) ?? '';

            if ($followUp !== $normalizedQuestion) {
                if (preg_match('/^sales (?:for|in|during) (?:today|yesterday|this week|this month|this year)$/', $followUp) === 1) {
                    return $this->evaluate('Show me '.$followUp, $date);
                }

                $canonicalQuestion = match ($followUp) {
                    'inventory', 'inventory status' => 'Summarize inventory status',
                    'low stock', 'low stock products' => 'List low-stock products',
                    'best sellers', 'best selling products' => 'List best-selling products',
                    'collection dates', 'recently collected products' => 'List recently collected products',
                    'unpaid balance', 'outstanding balance' => 'What is the total unpaid balance?',
                    'today', 'yesterday', 'this week', 'this month', 'this year' => $visitContext['intent'] === 'sales_summary'
                        ? 'Show me sales for '.$followUp : null,
                    default => null,
                };

                if ($canonicalQuestion !== null) {
                    return $this->evaluate($canonicalQuestion, $date);
                }
            }

            if ($visitContext['intent'] === 'unpaid_summary' && in_array($normalizedQuestion, ['how much is left', 'how much is left unpaid'], true)) {
                return $this->evaluate('What is the total unpaid balance?', $date);
            }
        }

        $socialReply = $this->socialReplyFor($normalizedQuestion);

        if ($socialReply !== null) {
            return $this->localSocial($date, $socialReply);
        }

        $routing = (new AssistantIntentRouter)->route($normalizedQuestion);

        if ($routing !== null) {
            return $routing['type'] === 'clarification'
                ? $this->clarification($date, $routing['value'])
                : $this->evaluate($routing['value'], $date);
        }

        $chatTurn = $this->turnScreen->screen($question);

        if ($chatTurn !== null) {
            return $this->generalChat($date, $chatTurn);
        }

        return $this->denied($date, 'The question is unsupported or ambiguous; ask a clear sales, inventory, collection-date, best-seller, or aggregate unpaid question. I can also respond to a greeting or brief light chat, but not unrelated factual questions.', true);
    }

    private function withoutSocialOpening(string $question): string
    {
        return preg_replace('/^(?:hi|hello|hey|good (?:morning|afternoon|evening))(?:,? (?:there|maanyag))?(?:[,!]\s*|\s+)(?=\S)/u', '', $question) ?? $question;
    }

    private function socialReplyFor(string $question): ?string
    {
        if (preg_match('/^(hi|hello|hey)(?:,? (?:there|maanyag))?$/', $question, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^good (morning|afternoon|evening)(?:,? maanyag)?$/', $question, $matches) === 1) {
            return $matches[1];
        }

        return match (true) {
            preg_match('/^(?:thanks|thank you|salamat)(?:,? maanyag)?$/', $question) === 1 => 'thanks',
            preg_match('/^(?:how are you|how are you doing|hows it going|how is it going|hows your day|whats up|what are you up to)(?:,? maanyag)?$/', $question) === 1 => 'check_in',
            preg_match('/^(?:what can you do|how can you help|who are you|can we chat|can you chat with me)(?:,? maanyag)?$/', $question) === 1 => 'help',
            preg_match('/^(?:bye|goodbye|see you|see you later)(?:,? maanyag)?$/', $question) === 1 => 'goodbye',
            preg_match('/^(?:tell me a joke|can you tell me a joke|say something funny)$/', $question) === 1 => 'joke',
            default => null,
        };
    }

    private function normalize(string $question): string
    {
        $question = strtolower(trim(str_replace(['-', '_'], ' ', $question)));
        $question = str_replace(["'", '’'], '', $question);
        $question = preg_replace('/[?.!,]+$/u', '', $question) ?? '';

        return preg_replace('/\s+/u', ' ', $question) ?? '';
    }

    /**
     * @return array{allowed: true, intent: string, period: ?string, date: string, safe_question: string, reason: null}
     */
    private function allowed(string $intent, ?string $period, CarbonImmutable $date, string $safeQuestion): array
    {
        return [
            'allowed' => true,
            'intent' => $intent,
            'period' => $period,
            'date' => $date->toDateString(),
            'safe_question' => $safeQuestion,
            'reason' => null,
        ];
    }

    /**
     * @return array{allowed: true, intent: 'local_social', period: null, date: string, safe_question: null, social_reply: string, reason: null}
     */
    private function localSocial(CarbonImmutable $date, string $reply): array
    {
        return [
            'allowed' => true,
            'intent' => 'local_social',
            'period' => null,
            'date' => $date->toDateString(),
            'safe_question' => null,
            'social_reply' => $reply,
            'reason' => null,
        ];
    }

    /** @return array{allowed: true, intent: 'general_chat', period: null, date: string, safe_question: null, chat_turn: string, reason: null} */
    private function generalChat(CarbonImmutable $date, string $turn): array
    {
        return [
            'allowed' => true,
            'intent' => 'general_chat',
            'period' => null,
            'date' => $date->toDateString(),
            'safe_question' => null,
            'chat_turn' => $turn,
            'reason' => null,
        ];
    }

    /**
     * @return array{allowed: true, intent: 'clarification', period: null, date: string, safe_question: null, clarification: string, reason: null}
     */
    private function clarification(CarbonImmutable $date, string $topic): array
    {
        return [
            'allowed' => true,
            'intent' => 'clarification',
            'period' => null,
            'date' => $date->toDateString(),
            'safe_question' => null,
            'clarification' => $topic,
            'reason' => null,
        ];
    }

    /**
     * @return array{allowed: true, intent: 'latest_unpaid_sale', period: null, date: string, safe_question: null, reason: null}
     */
    private function localLatestUnpaidSale(CarbonImmutable $date): array
    {
        return [
            'allowed' => true,
            'intent' => 'latest_unpaid_sale',
            'period' => null,
            'date' => $date->toDateString(),
            'safe_question' => null,
            'reason' => null,
        ];
    }

    /**
     * @return array{allowed: false, intent: null, period: null, date: string, safe_question: null, reason: string, model_candidate?: bool}
     */
    private function denied(CarbonImmutable $date, string $reason, bool $modelCandidate = false): array
    {
        return [
            'allowed' => false,
            'intent' => null,
            'period' => null,
            'date' => $date->toDateString(),
            'safe_question' => null,
            'reason' => $reason,
            ...($modelCandidate ? ['model_candidate' => true] : []),
        ];
    }
}
