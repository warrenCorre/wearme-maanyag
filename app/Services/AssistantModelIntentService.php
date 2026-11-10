<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use JsonException;
use LogicException;
use RuntimeException;

class AssistantModelIntentService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'intent' => ['type' => 'string', 'enum' => [
                'none', 'sales_goal', 'sales_period', 'sales_summary', 'inventory_summary',
                'low_stock', 'best_sellers', 'collection_dates', 'unpaid_goal', 'unpaid_summary',
            ]],
            'period' => ['type' => 'string', 'enum' => ['none', 'today', 'yesterday', 'this week', 'this month', 'this year']],
        ],
        'required' => ['intent', 'period'],
    ];

    private const SYSTEM_INSTRUCTION = <<<'INSTRUCTION'
        Classify one screened, non-identifying current store question. Return only JSON with intent and period from the supplied schema. Choose an approved store skill or clarification, or none if uncertain. Never provide an answer, store fact, customer detail, identifier, action, SQL, extra field, or new skill. No previous turns or store data are available. Broad sales without a goal needs sales_goal; sales totals or transaction counts without a period need sales_period; broad unpaid needs unpaid_goal. Laravel independently checks your suggestion.
        INSTRUCTION;

    public function __construct(private GeminiClient $geminiClient, private AssistantStoreTurnScreen $turnScreen) {}

    public function suggest(string $question): ?string
    {
        if (! config('services.gemini.allow_conversation', false) || ($screened = $this->turnScreen->screen($question)) === null) {
            return null;
        }

        try {
            $raw = $this->geminiClient->generateText(
                'Current screened store turn: '.$screened['turn'],
                self::SYSTEM_INSTRUCTION,
                self::RESPONSE_SCHEMA,
            );
            $response = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (ConnectionException|RequestException|RuntimeException|LogicException|JsonException) {
            return null;
        }

        if (! is_array($response) || count($response) !== 2
            || ! is_string($response['intent'] ?? null) || ! is_string($response['period'] ?? null)) {
            return null;
        }

        $intent = $response['intent'];
        $period = $response['period'];

        if ($intent === 'sales_summary' && $screened['topic'] === 'sales'
            && $screened['period'] !== null && $period === $screened['period']) {
            return 'Show me sales for '.$period;
        }

        if ($period !== 'none' || $screened['period'] !== null) {
            return null;
        }

        return match ($screened['topic']) {
            'sales' => $intent === ($screened['count_goal'] ? 'sales_period' : 'sales_goal')
                ? ($screened['count_goal'] ? 'How many transactions?' : 'Sales?') : null,
            'unpaid' => $intent === ($screened['count_goal'] ? 'unpaid_summary' : 'unpaid_goal')
                ? ($screened['count_goal'] ? 'How many unpaid transactions?' : 'Unpaid?') : null,
            'inventory' => $intent === ($screened['low_stock'] ? 'low_stock' : 'inventory_summary')
                ? ($screened['low_stock'] ? 'List low-stock products' : 'Summarize inventory status') : null,
            'best_sellers' => $intent === 'best_sellers' ? 'List best-selling products' : null,
            'collection_dates' => $intent === 'collection_dates' ? 'List recently collected products' : null,
            default => null,
        };
    }
}
