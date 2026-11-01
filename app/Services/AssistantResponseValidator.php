<?php

namespace App\Services;

use DateTimeImmutable;
use JsonException;

class AssistantResponseValidator
{
    public const RESPONSE_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'answer' => [
                'type' => 'string',
                'description' => 'Concise plain-text answer grounded only in the verified context. Do not use Markdown code fences or numbered lists.',
            ],
            'answer_type' => [
                'type' => 'string',
                'enum' => ['fact', 'calculation', 'summary', 'refusal'],
                'description' => 'Use refusal only when the verified context cannot answer the question.',
            ],
            'sources' => [
                'type' => 'array',
                'description' => 'Exactly one allowed source for a non-refusal answer; empty for a refusal.',
                'maxItems' => 1,
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => [
                            'type' => 'string',
                            'enum' => ['sales', 'inventory', 'best_sellers', 'unpaid_summary'],
                            'description' => 'Source type copied from allowed_sources.',
                        ],
                        'label' => [
                            'type' => 'string',
                            'description' => 'Source label copied exactly from allowed_sources.',
                        ],
                        'as_of' => [
                            'type' => 'string',
                            'format' => 'date',
                            'description' => 'Source date copied exactly from allowed_sources.',
                        ],
                    ],
                    'required' => ['type', 'label', 'as_of'],
                    'additionalProperties' => false,
                ],
            ],
            'confidence' => [
                'type' => 'string',
                'enum' => ['high', 'medium', 'low'],
            ],
            'refusal' => [
                'type' => 'boolean',
                'description' => 'True only when the verified context cannot answer the question.',
            ],
        ],
        'required' => ['answer', 'answer_type', 'sources', 'confidence', 'refusal'],
        'additionalProperties' => false,
    ];

    private const REQUIRED_KEYS = ['answer', 'answer_type', 'sources', 'confidence', 'refusal'];

    private const ANSWER_TYPES = ['fact', 'calculation', 'summary', 'refusal'];

    private const CONFIDENCE_LEVELS = ['high', 'medium', 'low'];

    /**
     * @var array<string, array{type: string, label: string, as_of_key: string}>
     */
    private const SOURCE_BY_INTENT = [
        'sales_summary' => [
            'type' => 'sales',
            'label' => 'Sales summary',
            'as_of_key' => 'sales',
        ],
        'inventory_summary' => [
            'type' => 'inventory',
            'label' => 'Inventory summary',
            'as_of_key' => 'inventory',
        ],
        'low_stock' => [
            'type' => 'inventory',
            'label' => 'Low-stock products',
            'as_of_key' => 'inventory',
        ],
        'best_sellers' => [
            'type' => 'best_sellers',
            'label' => 'Best sellers',
            'as_of_key' => 'best_sellers',
        ],
        'unpaid_summary' => [
            'type' => 'unpaid_summary',
            'label' => 'Unpaid summary',
            'as_of_key' => 'unpaid_summary',
        ],
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array{type: string, label: string, as_of: string}>
     */
    public function allowedSourcesFor(string $intent, array $context): array
    {
        $source = self::SOURCE_BY_INTENT[$intent] ?? null;

        if ($source === null) {
            return [];
        }

        $asOf = $context['as_of'][$source['as_of_key']] ?? null;

        if (! is_string($asOf) || ! $this->isValidDate($asOf)) {
            return [];
        }

        return [[
            'type' => $source['type'],
            'label' => $source['label'],
            'as_of' => $asOf,
        ]];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<int, array{type: string, label: string, as_of: string}>  $allowedSources
     * @return array{
     *     valid: bool,
     *     response: null|array{
     *         answer: string,
     *         answer_type: string,
     *         sources: array<int, array{type: string, label: string, as_of: string}>,
     *         confidence: string,
     *         refusal: bool
     *     },
     *     error: null|string
     * }
     */
    public function validate(string $raw, array $context, array $allowedSources): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            return $this->invalid('malformed_json');
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->invalid('malformed_json');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->invalid('invalid_shape');
        }

        $actualKeys = array_keys($decoded);
        $expectedKeys = self::REQUIRED_KEYS;
        sort($actualKeys);
        sort($expectedKeys);

        if ($actualKeys !== $expectedKeys) {
            return $this->invalid('invalid_shape');
        }

        $answer = $decoded['answer'];
        $answerType = $decoded['answer_type'];
        $sources = $decoded['sources'];
        $confidence = $decoded['confidence'];
        $refusal = $decoded['refusal'];

        if (! is_string($answer) || trim($answer) === '') {
            return $this->invalid('empty_answer');
        }

        $answer = trim($answer);

        if (mb_strlen($answer) > 2000) {
            return $this->invalid('answer_too_long');
        }

        if (! is_string($answerType) || ! in_array($answerType, self::ANSWER_TYPES, true)) {
            return $this->invalid('invalid_answer_type');
        }

        if (! is_string($confidence) || ! in_array($confidence, self::CONFIDENCE_LEVELS, true)) {
            return $this->invalid('invalid_confidence');
        }

        if (! is_bool($refusal)) {
            return $this->invalid('invalid_refusal');
        }

        if (($refusal && $answerType !== 'refusal') || (! $refusal && $answerType === 'refusal')) {
            return $this->invalid('refusal_mismatch');
        }

        if (! is_array($sources) || ! array_is_list($sources)) {
            return $this->invalid('invalid_source');
        }

        if (count($sources) > 1) {
            return $this->invalid('invalid_source');
        }

        if ($refusal) {
            if ($sources !== []) {
                return $this->invalid('unexpected_sources');
            }
        } else {
            if (count($sources) !== 1) {
                return $this->invalid('missing_sources');
            }

            if (count($allowedSources) !== 1) {
                return $this->invalid('unsupported_source');
            }

            $source = $sources[0];

            if (! is_array($source) || array_is_list($source)) {
                return $this->invalid('invalid_source');
            }

            $sourceKeys = array_keys($source);
            $expectedSourceKeys = ['type', 'label', 'as_of'];
            sort($sourceKeys);
            sort($expectedSourceKeys);

            if ($sourceKeys !== $expectedSourceKeys) {
                return $this->invalid('invalid_source');
            }

            foreach ($expectedSourceKeys as $key) {
                if (! is_string($source[$key] ?? null) || trim($source[$key]) === '') {
                    return $this->invalid('invalid_source');
                }
            }

            if (! $this->isValidDate($source['as_of'])) {
                return $this->invalid('invalid_source');
            }

            $allowedSource = $allowedSources[0];

            if (
                $source['type'] !== $allowedSource['type']
                || $source['label'] !== $allowedSource['label']
                || $source['as_of'] !== $allowedSource['as_of']
            ) {
                return $this->invalid('unsupported_source');
            }
        }

        if ($this->findUngroundedNumber($answer, $context) !== null) {
            return $this->invalid('ungrounded_number');
        }

        return [
            'valid' => true,
            'response' => [
                'answer' => $answer,
                'answer_type' => $answerType,
                'sources' => $sources,
                'confidence' => $confidence,
                'refusal' => $refusal,
            ],
            'error' => null,
        ];
    }

    /**
     * @return array{valid: false, response: null, error: string}
     */
    private function invalid(string $error): array
    {
        return [
            'valid' => false,
            'response' => null,
            'error' => $error,
        ];
    }

    private function isValidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function findUngroundedNumber(string $answer, array $context): ?string
    {
        $allowedNumbers = $this->collectNumbers($context);

        preg_match_all('/(?<!\d)-?\d[\d,]*(?:\.\d+)?/', $answer, $matches);

        foreach ($matches[0] as $token) {
            $value = (float) str_replace(',', '', $token);

            if (! $this->isAllowedNumber($value, $allowedNumbers)) {
                return $token;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, float>
     */
    private function collectNumbers(array $context): array
    {
        $numbers = [];
        $this->walkNumbers($context, $numbers);

        return $numbers;
    }

    /**
     * @param  array<int, float>  $numbers
     */
    private function walkNumbers(mixed $value, array &$numbers): void
    {
        if (is_int($value) || is_float($value)) {
            $numbers[] = (float) $value;

            return;
        }

        if (is_string($value)) {
            if ($this->isValidDate($value)) {
                foreach (explode('-', $value) as $part) {
                    $numbers[] = (float) $part;
                }

                return;
            }

            preg_match_all('/\d[\d,]*(?:\.\d+)?/', $value, $matches);

            foreach ($matches[0] as $token) {
                $numbers[] = (float) str_replace(',', '', $token);
            }

            return;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $this->walkNumbers($item, $numbers);
            }
        }
    }

    /**
     * @param  array<int, float>  $allowedNumbers
     */
    private function isAllowedNumber(float $value, array $allowedNumbers): bool
    {
        foreach ($allowedNumbers as $allowed) {
            if (abs($allowed - $value) < 0.0001) {
                return true;
            }
        }

        return false;
    }
}
