<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use JsonException;
use LogicException;
use RuntimeException;

class AssistantConversationService
{
    private const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'style' => ['type' => 'string', 'enum' => ['warm', 'bright', 'gentle']],
            'suggested_skill' => ['type' => 'string', 'enum' => ['none']],
        ],
        'required' => ['style', 'suggested_skill'],
    ];

    private const SYSTEM_INSTRUCTION = <<<'INSTRUCTION'
        You are Maanyag, a warm, brief assistant for the Store Owner. Choose a reply style for the current safe conversational topic only. Return JSON with style (warm, bright, or gentle) and suggested_skill (none). No store facts, customer information, personal details, previous turns, or unsupported skills. Laravel will supply all user-facing words and verified business facts.
        INSTRUCTION;

    public function __construct(private GeminiClient $geminiClient) {}

    public function reply(string $category, string $key): ?string
    {
        if (! config('services.gemini.allow_conversation', false)) {
            return null;
        }

        $replies = $this->replies($category, $key);

        if ($replies === null) {
            return null;
        }

        try {
            $raw = $this->geminiClient->generateText(
                'Current safe topic: '.$category.' / '.$key.'. Select a short, friendly style for this turn.',
                self::SYSTEM_INSTRUCTION,
                self::RESPONSE_SCHEMA,
            );
            $response = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (ConnectionException|RequestException|RuntimeException|LogicException|JsonException) {
            return null;
        }

        if (! is_array($response) || count($response) !== 2
            || ! array_key_exists('style', $response) || ! array_key_exists('suggested_skill', $response)
            || $response['suggested_skill'] !== 'none'
            || ! is_string($response['style'])) {
            return null;
        }

        return $replies[$response['style']] ?? null;
    }

    /**
     * @return array{warm: string, bright: string, gentle: string}|null
     */
    private function replies(string $category, string $key): ?array
    {
        if ($category === 'clarification') {
            return match ($key) {
                'sales_goal' => [
                    'warm' => 'Sure! Are you looking for sales totals, transaction counts, best sellers, or unpaid balances?',
                    'bright' => 'Happy to help! Do you mean sales totals, transaction counts, best sellers, or unpaid balances?',
                    'gentle' => 'Let’s narrow it down: sales totals, transaction counts, best sellers, or unpaid balances?',
                ],
                'sales_period' => [
                    'warm' => 'Of course! For which period: today, yesterday, this week, this month, or this year?',
                    'bright' => 'Let’s check your sales totals. Today, yesterday, this week, this month, or this year?',
                    'gentle' => 'Which period would you like for sales totals: today, yesterday, this week, this month, or this year?',
                ],
                'unpaid_goal' => [
                    'warm' => 'Do you mean the aggregate unpaid balance or the latest pending sale? I can show either one.',
                    'bright' => 'Let’s clarify: would you like the aggregate unpaid balance or the latest pending sale?',
                    'gentle' => 'Are you asking about the aggregate unpaid balance or the latest pending sale? Both are available.',
                ],
                default => null,
            };
        }

        if ($category !== 'social') {
            return null;
        }

        return match ($key) {
            'hi', 'hello', 'hey', 'morning', 'afternoon', 'evening' => [
                'warm' => 'Hi! I’m Maanyag. How can I help with the store today?',
                'bright' => 'Hey! Lovely to hear from you. Want to chat briefly or ask about the store?',
                'gentle' => 'Hello! I’m here for a quick chat or a supported store question.',
            ],
            'thanks' => [
                'warm' => 'You’re welcome! I’m here whenever you need a hand with the store.',
                'bright' => 'Glad to help! Ask me another supported store question anytime.',
                'gentle' => 'Anytime. Would you like help with another store topic?',
            ],
            'check_in', 'help' => [
                'warm' => 'I’m here and ready to help. Would you like a quick chat or a supported store topic?',
                'bright' => 'Happy to chat briefly! I can also help with verified store questions.',
                'gentle' => 'I can chat briefly and help with supported store questions, but I cannot change records.',
            ],
            'goodbye' => [
                'warm' => 'Take care! I’ll be here when you have another store question.',
                'bright' => 'See you soon! Come back whenever you need help with the store.',
                'gentle' => 'Goodbye! I’m here when you need a supported store answer.',
            ],
            'joke' => [
                'warm' => 'Why did the hanger stay calm? It knew how to hang in there.',
                'bright' => 'Why did the shirt visit the thrift store? It wanted a fresh start!',
                'gentle' => 'A tiny store joke: the hanger always knows how to hang in there.',
            ],
            default => null,
        };
    }
}
