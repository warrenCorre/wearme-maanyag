<?php

namespace App\Services;

class AssistantIntentRouter
{
    private const WORDS = [
        'a', 'about', 'all', 'amount', 'an', 'and', 'any', 'are', 'at', 'balance', 'balances',
        'been', 'best', 'can', 'check', 'collected', 'collection', 'collections', 'could',
        'count', 'counts', 'current', 'currently', 'date', 'dates', 'did', 'do', 'doing', 'far',
        'find', 'for', 'from', 'get', 'give', 'going', 'has', 'have', 'how', 'hows', 'i', 'in',
        'inventory', 'is', 'item', 'items', 'latest', 'lately', 'left', 'list', 'looking',
        'low', 'make', 'many', 'me', 'money', 'month', 'most', 'much', 'my', 'newest', 'number',
        'of', 'on', 'one', 'or', 'our', 'out', 'outstanding', 'owe', 'owed', 'owing',
        'payment', 'payments', 'pending', 'please', 'product', 'products', 'pull', 'quantity',
        'quantities', 'recent', 'recently', 'revenue', 'running', 'sale', 'sales', 'see',
        'sell', 'sellers', 'selling', 'shop', 'show', 'so', 'some', 'sold', 'stock',
        'store', 'still', 'summarize', 'summary', 'tell', 'the', 'there', 'this',
        'today', 'top', 'total', 'totals', 'transaction', 'transactions', 'unpaid',
        'units', 'up', 'us', 'value', 'was', 'we', 'week', 'what', 'whats', 'when', 'which',
        'with', 'year', 'yesterday', 'you', 'your',
    ];

    /**
     * Only returns fixed questions for existing skills; unknown words never become skill arguments.
     *
     * @return array{type: 'question'|'clarification', value: string}|null
     */
    public function route(string $normalizedQuestion): ?array
    {
        $question = trim(preg_replace('/\s+/', ' ', str_replace(',', ' ', $normalizedQuestion)) ?? '');

        if ($question === '' || preg_match('/^[a-z ]+$/D', $question) !== 1) {
            return null;
        }

        $words = explode(' ', $question);

        if (array_diff($words, self::WORDS) !== []) {
            return null;
        }

        $unpaid = $this->hasAny($words, ['unpaid', 'pending', 'outstanding', 'owe', 'owed', 'owing']);
        $bestSellers = $this->hasAny($words, ['best', 'top', 'sellers'])
            || ($this->hasAny($words, ['sell', 'selling', 'sold']) && $this->hasAny($words, ['most']));
        $collections = $this->hasAny($words, ['collected', 'collection', 'collections']);
        $inventory = $this->hasAny($words, ['inventory', 'stock'])
            || ($this->hasAny($words, ['low']) && $this->hasAny($words, ['product', 'products', 'item', 'items']));
        $salesTerm = $this->hasAny($words, ['sale', 'sales', 'transaction', 'transactions', 'revenue']);
        $sales = $salesTerm && ! $unpaid;

        if (($unpaid && $salesTerm && $this->hasAny($words, ['and', 'or']))
            || count(array_filter([$unpaid, $bestSellers, $collections, $inventory, $sales])) !== 1) {
            return null;
        }

        $recent = $this->hasAny($words, ['latest', 'newest', 'recent']);
        preg_match_all('/\b(today|yesterday|this week|this month|this year)\b/', $question, $periods);

        if (count($periods[0]) > 1
            || ($this->hasAny($words, ['today', 'yesterday', 'week', 'month', 'year']) && $periods[0] === [])) {
            return null;
        }

        $period = $periods[1][0] ?? null;

        if ($unpaid) {
            if ($period !== null || $this->hasAny($words, ['list', 'all', 'product', 'products', 'item', 'items', 'revenue'])) {
                return null;
            }

            if ($recent) {
                if ($this->hasAny($words, ['sale', 'transaction'])
                    && ! $this->hasAny($words, ['sales', 'transactions', 'many', 'count', 'counts'])) {
                    return $this->question('Show the latest unpaid sale');
                }

                if (preg_match('/^(?:(?:show(?: me)?|what about|what is) (?:the )?)?latest pending$/', $question) === 1) {
                    return $this->clarification('latest_pending_sale_confirmation');
                }

                return null;
            }

            if ($this->hasAny($words, ['many', 'count', 'counts', 'number'])
                && $this->hasAny($words, ['much', 'total', 'totals', 'amount'])) {
                return null;
            }

            if ($this->hasAny($words, ['many', 'count', 'counts', 'number'])) {
                return $this->question('How many unpaid transactions?');
            }

            if ($this->hasAny($words, ['much', 'total', 'totals', 'amount'])) {
                return $this->question('What is the total unpaid balance?');
            }

            return $this->clarification('unpaid_goal');
        }

        if ($sales) {
            if ($recent || $this->hasAny($words, ['list', 'all', 'product', 'products', 'item', 'items'])) {
                return null;
            }

            if ($period !== null) {
                return $this->question('Show me sales for '.$period);
            }

            return $this->clarification($this->hasAny($words, ['much', 'many', 'total', 'totals', 'count', 'counts', 'number', 'revenue'])
                ? 'sales_period' : 'sales_goal');
        }

        if ($inventory) {
            if ($recent || $period !== null || $this->hasAny($words, ['list', 'all'])
                || ($this->hasAny($words, ['product', 'products', 'item', 'items'])
                    && ! $this->hasAny($words, ['low', 'running', 'many', 'quantity', 'quantities', 'total', 'value']))) {
                return null;
            }

            return $this->question($this->hasAny($words, ['low', 'running'])
                ? 'List low-stock products' : 'Summarize inventory status');
        }

        if ($bestSellers) {
            return $recent || $period !== null || $this->hasAny($words, ['all', 'many', 'count', 'counts'])
                ? null : $this->question('List best-selling products');
        }

        return $period !== null || $this->hasAny($words, ['all', 'many', 'count', 'counts'])
            ? null : $this->question('List recently collected products');
    }

    /** @param  list<string>  $words */
    private function hasAny(array $words, array $terms): bool
    {
        return array_intersect($words, $terms) !== [];
    }

    /** @return array{type: 'question', value: string} */
    private function question(string $question): array
    {
        return ['type' => 'question', 'value' => $question];
    }

    /** @return array{type: 'clarification', value: string} */
    private function clarification(string $topic): array
    {
        return ['type' => 'clarification', 'value' => $topic];
    }
}
