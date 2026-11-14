<?php

namespace Tests\Support;

use App\Services\GeminiClient;
use JsonException;
use RuntimeException;

/** Only bound in an explicitly opted-in local DEMO browser process; never contacts a provider. */
class FakeGeminiClient extends GeminiClient
{
    /**
     * @param  array<string, mixed>|null  $responseSchema
     *
     * @throws JsonException
     */
    public function generateText(string $prompt, ?string $systemInstruction = null, ?array $responseSchema = null): string
    {
        if (isset($responseSchema['properties']['style'])) {
            return '{"style":"warm","suggested_skill":"none"}';
        }

        if (! isset($responseSchema['properties']['answer'])) {
            throw new RuntimeException('The DEMO fake supports only sales answers and clarification styles.');
        }

        $prefix = "Verified system context (JSON):\n";
        $position = strpos($prompt, $prefix);

        if ($position === false) {
            throw new RuntimeException('No verified context was supplied to the DEMO fake.');
        }

        $context = json_decode(substr($prompt, $position + strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        $source = $context['allowed_sources'][0] ?? null;
        $sales = $context['sales'] ?? null;

        if (! is_array($source) || ($source['type'] ?? null) !== 'sales'
            || ! is_array($sales) || ! is_array($sales['summary'] ?? null)
            || ! is_numeric($sales['summary']['total_sales'] ?? null)
            || ! is_int($sales['summary']['transaction_count'] ?? null)
            || ! is_string($sales['period'] ?? null) || ! is_string($sales['date'] ?? null)) {
            throw new RuntimeException('Only verified sales summaries can be used by the DEMO fake.');
        }

        return json_encode([
            'answer' => 'Recorded sales for '.$sales['period'].' on '.$sales['date'].': '.$sales['summary']['transaction_count'].' transactions totaling PHP '.$sales['summary']['total_sales'].'.',
            'answer_type' => 'fact',
            'sources' => [$source],
            'confidence' => 'high',
            'refusal' => false,
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<int, float> */
    public function embedContent(string $text, string $taskType = 'RETRIEVAL_DOCUMENT'): array
    {
        throw new RuntimeException('The DEMO fake never generates embeddings.');
    }
}
