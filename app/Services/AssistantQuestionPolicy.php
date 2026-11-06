<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class AssistantQuestionPolicy
{
    /**
     * @return array{allowed: bool, intent: ?string, period: ?string, date: string, safe_question: ?string, reason: ?string}
     */
    public function evaluate(string $question, ?CarbonInterface $referenceDate = null): array
    {
        $date = CarbonImmutable::instance($referenceDate ?? CarbonImmutable::today());
        $normalizedQuestion = $this->normalize($question);

        if ($normalizedQuestion === '') {
            return $this->denied($date, 'Please ask a supported store question.');
        }

        if (preg_match('/\b(add|create|record|update|edit|change|delete|remove|archive|settle|pay)\b/', $normalizedQuestion) === 1) {
            return $this->denied($date, 'The assistant cannot perform or arrange write actions.');
        }

        if (preg_match('/\b(customer|client|buyer|person|individual|whose|who|name|contact|phone|email|mobile)\b/', $normalizedQuestion) === 1) {
            return $this->denied($date, 'Customer-specific or identifying questions are not supported.');
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

        return $this->denied($date, 'The question is unsupported or ambiguous; ask a clear sales, inventory, collection-date, best-seller, or aggregate unpaid question.');
    }

    private function normalize(string $question): string
    {
        $question = strtolower(trim(str_replace(['-', '_'], ' ', $question)));
        $question = str_replace(["'", '’'], '', $question);
        $question = preg_replace('/[?.!]+$/u', '', $question) ?? '';

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
     * @return array{allowed: false, intent: null, period: null, date: string, safe_question: null, reason: string}
     */
    private function denied(CarbonImmutable $date, string $reason): array
    {
        return [
            'allowed' => false,
            'intent' => null,
            'period' => null,
            'date' => $date->toDateString(),
            'safe_question' => null,
            'reason' => $reason,
        ];
    }
}
