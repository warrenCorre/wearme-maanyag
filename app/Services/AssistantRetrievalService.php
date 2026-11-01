<?php

namespace App\Services;

use InvalidArgumentException;

class AssistantRetrievalService
{
    private const MAX_RESULTS = 10;

    private const APPROVED_SOURCE_TYPES = [
        'sales_summary',
        'inventory_summary',
        'low_stock',
        'best_sellers',
        'unpaid_summary',
    ];

    /**
     * Rank only current, privacy-vetted candidates prepared by a trusted caller.
     * A source type does not by itself prove that content is safe or fresh.
     *
     * @param  array<int, int|float>  $queryVector
     * @param  array<int, array<string, mixed>>  $records
     * @return array<int, array{source_type: string, source_id: int, content_text: string, score: float}>
     */
    public function retrieve(array $queryVector, array $records, int $limit = 5): array
    {
        if ($limit < 1 || $limit > self::MAX_RESULTS) {
            throw new InvalidArgumentException('The retrieval limit must be between 1 and 10.');
        }

        if (! $this->validVector($queryVector, count($queryVector))) {
            return [];
        }

        $queryMagnitude = $this->magnitude($queryVector);

        if (! is_finite($queryMagnitude) || $queryMagnitude <= 0) {
            return [];
        }

        $matches = [];

        foreach ($records as $record) {
            if (! is_array($record)
                || ! in_array($record['source_type'] ?? null, self::APPROVED_SOURCE_TYPES, true)
                || ! is_int($record['source_id'] ?? null)
                || $record['source_id'] < 1
                || ! is_string($record['content_text'] ?? null)
                || trim($record['content_text']) === ''
            ) {
                continue;
            }

            $vector = $record['embedding_vector'] ?? null;

            if (! $this->validVector($vector, count($queryVector))) {
                continue;
            }

            $magnitude = $this->magnitude($vector);

            if (! is_finite($magnitude) || $magnitude <= 0) {
                continue;
            }

            $dotProduct = 0.0;

            foreach ($queryVector as $position => $component) {
                $dotProduct += $component * $vector[$position];
            }

            $score = $dotProduct / ($queryMagnitude * $magnitude);

            if (! is_finite($score) || $score <= 0) {
                continue;
            }

            $matches[] = [
                'source_type' => $record['source_type'],
                'source_id' => $record['source_id'],
                'content_text' => $record['content_text'],
                'score' => min(1.0, $score),
            ];
        }

        usort($matches, static fn (array $first, array $second): int => ($second['score'] <=> $first['score'])
            ?: ($first['source_type'] <=> $second['source_type'])
            ?: ($first['source_id'] <=> $second['source_id'])
        );

        return array_slice($matches, 0, $limit);
    }

    private function validVector(mixed $vector, int $dimension): bool
    {
        if (! is_array($vector) || $dimension < 1 || ! array_is_list($vector) || count($vector) !== $dimension) {
            return false;
        }

        foreach ($vector as $component) {
            if ((! is_int($component) && ! is_float($component)) || ! is_finite((float) $component)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int, int|float>  $vector
     */
    private function magnitude(array $vector): float
    {
        $squaredMagnitude = 0.0;

        foreach ($vector as $component) {
            $squaredMagnitude += $component * $component;
        }

        return sqrt($squaredMagnitude);
    }
}
