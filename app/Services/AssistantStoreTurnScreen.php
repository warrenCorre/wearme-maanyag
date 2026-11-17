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
        'a', 'about', 'actions', 'activity', 'all', 'and', 'anything', 'are', 'at', 'balance', 'balances', 'best', 'can',
        'check', 'collected', 'collection', 'collections', 'could', 'count', 'counts',
        'current', 'did', 'do', 'doing', 'else', 'for', 'from', 'give', 'go', 'going', 'happened', 'happening', 'has', 'have', 'how',
        'i', 'in', 'inventory', 'is', 'item', 'items', 'just', 'last', 'latest', 'list', 'low', 'many',
        'looking', 'me', 'month', 'most', 'much', 'my', 'newest', 'of', 'on', 'one', 'or', 'our',
        'outstanding', 'owe', 'owed', 'owing', 'payment', 'payments', 'pending',
        'please', 'product', 'products', 'quantity', 'recent', 'recently', 'revenue', 'running',
        'sale', 'sales', 'see', 'sell', 'sellers', 'selling', 'shop', 'show', 'sold',
        'stock', 'store', 'summarize', 'summary', 'talk', 'tell', 'the', 'there',
        'this', 'today', 'top', 'total', 'totals', 'transaction', 'transactions',
        'unpaid', 'value', 'we', 'week', 'what', 'when', 'which', 'with', 'year',
        'were', 'which', 'yesterday', 'you', 'your',
    ];

    private const UNSAFE_TERMS = '/\b(?:account|address|api|brand|buyer|category|city|client|code|color|contact|customer|email|id|identifier|invoice|key|location|mobile|name|order|password|person|phone|pin|province|receipt|reference|region|secret|size|token|whose|who|ignore|ignoring|disregard|instruction|instructions|prompt|system|developer|bypass|override|forget|pretend|role|rules|quarter|quarters|daily|weekly|monthly|yearly)\b/';

    private const DESCRIPTIVE_FRAME = '/\A(?:how (?:did|were|are|is|do|does|has|have) (?:(?:our|the) )?(?:sales|inventory|unpaid balances|best sellers|collections)(?: [a-z]+){1,5}|what is the (?:[a-z]+ )?(?:inventory|collection) [a-z]+|which (?:items|products) are running [a-z]+ low)\z/D';

    private const SALES_PERIOD_PATTERN = 'today|yesterday|(?:this|last) (?:week|month|year)';

    private const CURRENT_TIME_PATTERN = 'for now|right now|at the moment|now';

    private const NATURAL_PERIODS = [
        'today' => 'today|karon',
        'yesterday' => 'yesterday|gahapon|kagahapon',
        'this week' => 'this week|karong semana',
        'last week' => 'last week|(?:niaging|miaging) semana',
        'this month' => 'this month|karong bulan',
        'last month' => 'last month|(?:niaging|miaging) bulan',
        'this year' => 'this year|karong tuig',
        'last year' => 'last year|(?:niaging|miaging) tuig',
    ];

    private const NATURAL_UNSAFE_TERMS = '/\b(?:'
        .'add|create|record|update|edit|change|delete|remove|archive|settle|pay|mark|execute|reveal|print|download|export|'
        .'sql|database|credentials|adjustments?|damaged?|individual|specific|'
        .'weather|politics|medical|recipe|next|ago|before|since|between|tomorrow|'
        .'monday|tuesday|wednesday|thursday|friday|saturday|sunday|'
        .'or|also|plus|by|with|under|over|at|per|ni|kang|para|ug|og|apil|'
        .'ngalan|pangalan|numero|telepono|adres|kostumer|kustomer|usba|ilisan|bayari|ibayad|tangtanga|dugangi|idugang|iupdate|sunod'
        .')\b/';

    /** @return array{turn: string, period: ?string}|null */
    public function screenNaturalIntent(string $question, bool $allowPublicChat = false, bool $allowBriefPublicChat = false): ?array
    {
        $question = str_replace('’', "'", trim($question));

        if ($question === '' || strlen($question) > 220
            || preg_match('/\A[\p{L} ?.!,\x27-]+\z/uD', $question) !== 1) {
            return null;
        }

        $question = preg_replace('/\A(?:hi|hello|hey|good (?:morning|afternoon|evening))(?:,? (?:there|maanyag))?(?:[,!]\s*|\s+)(?=\S)/i', '', $question) ?? $question;
        $question = preg_replace('/\A(what|how)\x27s(?= )/i', '$1 is', $question) ?? $question;
        $capitalizationCheck = preg_replace_callback(
            '/\b[a-zA-Z]+\b/',
            fn (array $match): string => in_array(strtolower($match[0]), self::WORDS, true) ? strtolower($match[0]) : $match[0],
            $question,
        ) ?? $question;

        if (preg_match('/[?.!]\s+\S|,\s*(?:what|how|which|pila|unsa)\b/i', $question) === 1
            || preg_match('/\b(?:[A-Z][a-z]+|[A-Z]{2,})\b|[a-z][A-Z]/', substr($capitalizationCheck, strpos($capitalizationCheck, ' ') ?: strlen($capitalizationCheck))) === 1) {
            return null;
        }

        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!,\x27-]/u', ' ', mb_strtolower($question)) ?? '') ?? '');
        $turn = preg_replace('/\b(?:previous|prior) (week|month|year)\b/', 'last $1', $turn) ?? '';

        if ((substr_count($turn, ' ') < 2 && $this->screen($question) === null && ! ($allowPublicChat && $allowBriefPublicChat))
            || ($this->screenSalesFollowUp($question) !== null && ! str_starts_with($turn, 'how '))
            || preg_match('/\b(?:list|show) (?:all|every)\b/', $turn) === 1
            || (! $allowPublicChat && preg_match('/\b(?:jokes?|chat|funny|fashion)\b/', $turn) === 1)) {
            return null;
        }

        $periodPattern = implode('|', self::NATURAL_PERIODS);
        $genericStore = '\b(?:in|at|around|with) (?:the|our|this) (?:store|shop)';
        $timeSuffix = '(?:'.self::CURRENT_TIME_PATTERN.'|(?:(?:for|in|during) )?(?:'.$periodPattern.'))';
        $scopeCheck = preg_replace(
            '/'.$genericStore.'(?= '.$timeSuffix.'\z|\z)|\b(?:'.self::CURRENT_TIME_PATTERN.')\z/',
            '',
            $turn,
        ) ?? $turn;

        $recentActivity = preg_match('/\b(?:latest|newest|recent)\b/', $scopeCheck) === 1;
        $overallActivity = preg_match('/\b(?:activity|activities|actions?|happened|happening|going on|updates?)\b/', $scopeCheck) === 1;

        if (substr_count($turn, ' ') >= 30 || preg_match(self::UNSAFE_TERMS, $turn) === 1
            || ($recentActivity && ! $overallActivity)
            || preg_match(self::NATURAL_UNSAFE_TERMS, $scopeCheck) === 1
            || preg_match('/\b(?:for|in|during)\s+(?!(?:'.$periodPattern.'|stock|total|from (?:(?:our|the) )?(?:sales|store|shop))\b)/', $scopeCheck) === 1
            || preg_match('/\bfrom\s+(?!(?:(?:our|the) )?(?:sales|store|shop)\b)/', $turn) === 1
            || preg_match('/\bof\s+(?!(?:anything|stock|inventory|sales|how|what|whether|(?:our|the) (?:inventory|stock|sales))\b)/', $turn) === 1
            || preg_match('/\b(?:sales|inventory|stock|balances|sellers|collections?|products?|items?) of\b/', $turn) === 1
            || preg_match('/\band (?:what|how|which|pila|unsa)\b/', $turn) === 1
            || preg_match('/\b(?:sales|revenue|halin)\b.*\b(?:inventory|stock)\b|\b(?:inventory|stock)\b.*\b(?:sales|revenue|halin)\b/', $turn) === 1
            || preg_match('/\b(?:sales|inventory|transactions|balances|sellers)\s+(?!(?:are|is|did|do|go|for|in|on|the|our)\b)[a-z]{1,3}\b/', $turn) === 1
            || preg_match('/\b(?:a|an) (?!(?:brief|quick|overall|current|general|short)\b)[a-z]+ (?:overview|summary|snapshot)\b/', $turn) === 1
            || preg_match('/\b(?:what did|how was|how were|historical)\b.*\b(?:inventory|stock)\b/', $turn) === 1) {
            return null;
        }

        $periods = [];

        foreach (self::NATURAL_PERIODS as $period => $pattern) {
            preg_match_all('/\b(?:'.$pattern.')\b/u', $turn, $matches);
            array_push($periods, ...array_fill(0, count($matches[0]), $period));
        }

        if (count($periods) > 1
            || (preg_match('/\b(?:today|yesterday|week|month|year|last|this|semana|bulan|tuig)\b/', $scopeCheck) === 1 && $periods === [])) {
            return null;
        }

        return ['turn' => $turn, 'period' => $periods[0] ?? null];
    }

    /** @return array{turn: string, topic: string, period: ?string, count_goal: bool, low_stock: bool, corrected: bool}|null */
    public function screen(string $question): ?array
    {
        $question = trim($question);

        if ($question === '' || strlen($question) > 220 || preg_match('/\A[a-zA-Z\s?.!,\x27-]+\z/D', $question) !== 1) {
            return null;
        }

        $question = preg_replace('/\A(?:hi|hello|hey|good (?:morning|afternoon|evening))(?:,? (?:there|maanyag))?(?:[,!]\s*|\s+)(?=\S)/i', '', $question) ?? $question;

        if (preg_match('/\b(?:[A-Z][a-z]+|[A-Z]{2,})\b|[a-z][A-Z]/', substr($question, strpos($question, ' ') ?: strlen($question))) === 1) {
            return null;
        }

        $turn = trim(preg_replace('/\s+/', ' ', preg_replace('/[?.!,\x27-]/', ' ', strtolower($question)) ?? '') ?? '');
        $turn = preg_replace('/\b(?:previous|prior) (week|month|year)\b/', 'last $1', $turn) ?? '';

        if (substr_count($turn, ' ') >= 30 || preg_match(self::UNSAFE_TERMS, $turn) === 1
            || preg_match('/\b(?:by|from|with|under|over|at|per)\b/', $turn) === 1
            || preg_match('/\b(?:sales|inventory|balances|sellers|collections?|products?|items?) of\b/', $turn) === 1
            || preg_match('/\b(?:for|in|during)\s+(?!(?:today|yesterday|this (?:week|month|year)|last (?:week|month|year)|stock)\b)[a-z]+\b/', $turn) === 1) {
            return null;
        }

        $turn = $this->normalizeSummaryQuestion($turn);
        $words = explode(' ', $turn);
        $corrected = false;

        foreach ($words as &$word) {
            if ($word === 'wahat') {
                $word = 'what';
                $corrected = true;
            }
        }
        unset($word);

        $unfamiliar = array_diff($words, self::WORDS);
        $isDescriptiveFrame = preg_match(self::DESCRIPTIVE_FRAME, $turn) === 1;

        if (count($unfamiliar) > 2) {
            return null;
        }

        foreach ($unfamiliar as $word) {
            if (strlen($word) < 4 || (! $isDescriptiveFrame
                && preg_match('/\A[a-z]{4,}(?:ing|ly)\z/D', $word) !== 1)) {
                return null;
            }
        }

        if ($unfamiliar !== [] && $this->hasAny($words, ['and', 'or'])) {
            return null;
        }

        $screenedTurn = implode(' ', array_map(
            fn (string $word): string => in_array($word, self::WORDS, true) ? $word : 'descriptor',
            $words,
        ));
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

        preg_match_all('/\b('.self::SALES_PERIOD_PATTERN.')\b/', $turn, $periods);

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
            'turn' => $screenedTurn,
            'topic' => $topic,
            'period' => $period,
            'count_goal' => $this->hasAny($words, ['many', 'count', 'counts', 'much', 'total', 'totals', 'revenue', 'balance', 'balances']),
            'low_stock' => $this->hasAny($words, ['low']),
            'corrected' => $corrected,
        ];
    }

    private function normalizeSummaryQuestion(string $turn): string
    {
        $subject = '(?:our |the )?(sales|inventory)';
        $period = '(?:(?: for| in| during)? ('.self::SALES_PERIOD_PATTERN.'))?';
        $politeRequest = '/\A(?:can|could|would) you (?:give|show) (?:me|us) (?:a |an |the )?(?:(?:brief|quick|overall) )?(?:overview|snapshot|summary) of '.$subject.$period.'\z/';
        $indirectQuestion = '/\Awhat (?:does|did) '.$subject.' look like'.$period.'\z/';

        if (preg_match($politeRequest, $turn, $matches) !== 1 && preg_match($indirectQuestion, $turn, $matches) !== 1) {
            return $turn;
        }

        if ($matches[1] === 'inventory' && str_starts_with($turn, 'what did ')) {
            return $turn;
        }

        return rtrim('summarize '.$matches[1].' '.($matches[2] ?? ''));
    }

    /** @param  list<string>  $words */
    private function hasAny(array $words, array $terms): bool
    {
        return array_intersect($words, $terms) !== [];
    }
}
