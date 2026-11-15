<?php

namespace App\Services;

class AssistantStoreTurnScreen
{
    /** @return array{turn: string, period: string}|null */
    public function screenSalesFollowUp(string $question): ?array
    {
        $question = trim($question);

        if ($question === '' || strlen($question) > 100 || preg_match('/\A[a-zA-Z\s?.!\x27-]+\z/D', $question) !== 1) {
            return null;
        }

        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!\x27-]/', ' ', strtolower($question)) ?? '') ?? '');
        $turn = preg_replace('/\b(?:previous|prior) (week|month|year)\b/', 'last $1', $turn) ?? '';

        if (preg_match('/\A(?:(?:could you show (?:me )?|can you show (?:me )?|what about |and )(?:the )?|how (?:did we do|were we doing|are we doing) (?:in |during )?)(today|yesterday|this week|this month|this year|last week|last month|last year)(?: too)?\z/D', $turn, $matches) !== 1) {
            return null;
        }

        return ['turn' => $turn, 'period' => $matches[1]];
    }

    private const WORDS = [
        'a', 'about', 'all', 'and', 'are', 'at', 'balance', 'balances', 'best', 'can',
        'check', 'collected', 'collection', 'collections', 'could', 'count', 'counts',
        'current', 'did', 'do', 'doing', 'for', 'from', 'give', 'go', 'going', 'has', 'have', 'how',
        'i', 'in', 'inventory', 'is', 'item', 'items', 'last', 'latest', 'list', 'low', 'many',
        'looking', 'me', 'month', 'most', 'much', 'my', 'newest', 'of', 'on', 'one', 'or', 'our',
        'outstanding', 'owe', 'owed', 'owing', 'payment', 'payments', 'pending',
        'please', 'product', 'products', 'quantity', 'recent', 'recently', 'revenue', 'running',
        'sale', 'sales', 'see', 'sell', 'sellers', 'selling', 'shop', 'show', 'sold',
        'stock', 'store', 'summarize', 'summary', 'talk', 'tell', 'the', 'there',
        'this', 'today', 'top', 'total', 'totals', 'transaction', 'transactions',
        'unpaid', 'value', 'we', 'week', 'what', 'when', 'which', 'with', 'year',
        'were', 'which', 'yesterday', 'you', 'your',
    ];

    /** @return array{turn: string, topic: string, period: ?string, count_goal: bool, low_stock: bool, corrected: bool}|null */
    public function screen(string $question): ?array
    {
        $question = trim($question);

        if ($question === '' || strlen($question) > 220 || preg_match('/\A[a-zA-Z\s?.!,\x27-]+\z/D', $question) !== 1) {
            return null;
        }

        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!,\x27-]/', ' ', strtolower($question)) ?? '') ?? '');
        $turn = preg_replace('/\b(?:previous|prior) (week|month|year)\b/', 'last $1', $turn) ?? '';
        $words = explode(' ', $turn);
        $corrected = false;

        foreach ($words as &$word) {
            if ($word === 'wahat') {
                $word = 'what';
                $corrected = true;
            }
        }
        unset($word);

        if (count($words) > 30 || array_diff($words, self::WORDS) !== []) {
            return null;
        }

        $turn = implode(' ', $words);
        $unpaid = $this->hasAny($words, ['unpaid', 'pending', 'outstanding', 'owe', 'owed', 'owing']);
        $bestSellers = $this->hasAny($words, ['best', 'top', 'sellers'])
            || ($this->hasAny($words, ['sell', 'selling', 'sold']) && $this->hasAny($words, ['most']));
        $collections = $this->hasAny($words, ['collected', 'collection', 'collections']);
        $inventory = $this->hasAny($words, ['inventory', 'stock'])
            || ($this->hasAny($words, ['low']) && $this->hasAny($words, ['product', 'products', 'item', 'items']));
        $sales = $this->hasAny($words, ['sale', 'sales', 'transaction', 'transactions', 'revenue']);

        if (count(array_filter([$unpaid, $bestSellers, $collections, $inventory, $sales])) !== 1) {
            return null;
        }

        preg_match_all('/\b(today|yesterday|this week|this month|this year|last week|last month|last year)\b/', $turn, $periods);

        if (count($periods[0]) > 1
            || ($this->hasAny($words, ['today', 'yesterday', 'week', 'month', 'year', 'last']) && $periods[0] === [])) {
            return null;
        }

        $topic = match (true) {
            $unpaid => 'unpaid',
            $bestSellers => 'best_sellers',
            $collections => 'collection_dates',
            $inventory => 'inventory',
            default => 'sales',
        };
        $period = $periods[1][0] ?? null;

        if (($topic !== 'sales' && $period !== null)
            || (in_array($topic, ['sales', 'unpaid'], true)
                && $this->hasAny($words, ['all', 'list', 'latest', 'newest', 'recent', 'product', 'products', 'item', 'items']))
            || ($topic === 'inventory' && ($this->hasAny($words, ['all', 'list'])
                || ($this->hasAny($words, ['product', 'products', 'item', 'items'])
                    && ! $this->hasAny($words, ['low', 'many', 'quantity', 'total', 'value']))))
            || (in_array($topic, ['best_sellers', 'collection_dates'], true) && $this->hasAny($words, ['all']))
            || (in_array($topic, ['best_sellers', 'collection_dates'], true)
                && $this->hasAny($words, ['many', 'count', 'counts']))) {
            return null;
        }

        return [
            'turn' => $turn,
            'topic' => $topic,
            'period' => $period,
            'count_goal' => $this->hasAny($words, ['many', 'count', 'counts', 'much', 'total', 'totals', 'revenue', 'balance', 'balances']),
            'low_stock' => $this->hasAny($words, ['low']),
            'corrected' => $corrected,
        ];
    }

    /** @param  list<string>  $words */
    private function hasAny(array $words, array $terms): bool
    {
        return array_intersect($words, $terms) !== [];
    }
}
