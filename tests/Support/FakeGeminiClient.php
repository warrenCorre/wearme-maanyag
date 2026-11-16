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

        $screenedSalesPeriod = match ($prompt) {
            'Current screened store turn: could we talk about how our sales are doing last month',
            'Current screened store turn: how did our sales go last month',
            'Current screened store turn: how are our sales performing last month',
            'Verified topic label: sales. Current screened store turn: could you show the last month too',
            'Verified topic label: sales. Current screened store turn: how did we do last month' => 'last month',
            default => null,
        };

        if (isset($responseSchema['properties']['intent'])
            && $screenedSalesPeriod !== null
            && str_contains($systemInstruction ?? '', 'Never provide an answer, store fact, customer detail')
            && in_array('sales_summary', $responseSchema['properties']['intent']['enum'] ?? [], true)
            && in_array($screenedSalesPeriod, $responseSchema['properties']['period']['enum'] ?? [], true)) {
            return json_encode(['intent' => 'sales_summary', 'period' => $screenedSalesPeriod], JSON_THROW_ON_ERROR);
        }

        if (isset($responseSchema['properties']['intent'])
            && $prompt === 'Current screened store turn: which items are running low'
            && str_contains($systemInstruction ?? '', 'Never provide an answer, store fact, customer detail')
            && in_array('low_stock', $responseSchema['properties']['intent']['enum'] ?? [], true)
            && in_array('none', $responseSchema['properties']['period']['enum'] ?? [], true)) {
            return '{"intent":"low_stock","period":"none"}';
        }

        if (isset($responseSchema['properties']['intent'])
            && $prompt === 'Current screened store turn: which items are running critically low'
            && str_contains($systemInstruction ?? '', 'Never provide an answer, store fact, customer detail')
            && in_array('low_stock', $responseSchema['properties']['intent']['enum'] ?? [], true)
            && in_array('none', $responseSchema['properties']['period']['enum'] ?? [], true)) {
            return '{"intent":"low_stock","period":"none"}';
        }

        if (isset($responseSchema['properties']['intent'])
            && $prompt === 'Current screened store turn: list best selling products'
            && str_contains($systemInstruction ?? '', 'Never provide an answer, store fact, customer detail')
            && in_array('best_sellers', $responseSchema['properties']['intent']['enum'] ?? [], true)
            && in_array('none', $responseSchema['properties']['period']['enum'] ?? [], true)) {
            return '{"intent":"best_sellers","period":"none"}';
        }

        if (! isset($responseSchema['properties']['answer'])) {
            throw new RuntimeException('The DEMO fake supports only sales answers, clarification styles, and fixed screened sales classifier turns.');
        }

        $prefix = "Verified system context (JSON):\n";
        $position = strpos($prompt, $prefix);

        if ($position === false) {
            throw new RuntimeException('No verified context was supplied to the DEMO fake.');
        }

        $context = json_decode(substr($prompt, $position + strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        $source = $context['allowed_sources'][0] ?? null;
        $sales = $context['sales'] ?? null;

        if (($source['type'] ?? null) === 'inventory' && ($source['label'] ?? null) === 'Low-stock products'
            && is_array($context['inventory']['low_stock_products'] ?? null)
            && is_string($source['as_of'] ?? null)) {
            $products = $context['inventory']['low_stock_products'];

            foreach ($products as $product) {
                if (! is_array($product) || ! is_string($product['product_name'] ?? null)
                    || ! str_starts_with($product['product_name'], 'DEMO ')
                    || ! is_int($product['quantity'] ?? null) || ! is_int($product['low_stock_threshold'] ?? null)
                    || $product['quantity'] > $product['low_stock_threshold']) {
                    throw new RuntimeException('The DEMO fake accepts only verified DEMO low-stock items.');
                }
            }

            $answer = $products === [] ? 'No recorded low-stock products.'
                : 'Recorded low-stock products: '.implode(', ', array_map(
                    fn (array $product): string => $product['product_name'].' ('.$product['quantity'].' units)', $products,
                )).'.';

            return json_encode([
                'answer' => $answer,
                'answer_type' => 'fact',
                'sources' => [$source],
                'confidence' => 'high',
                'refusal' => false,
            ], JSON_THROW_ON_ERROR);
        }

        if (($source['type'] ?? null) === 'best_sellers' && ($source['label'] ?? null) === 'Best sellers'
            && is_array($context['best_sellers'] ?? null) && $context['best_sellers'] !== []
            && is_string($source['as_of'] ?? null)) {
            $products = $context['best_sellers'];

            foreach ($products as $product) {
                if (! is_array($product) || ! is_string($product['product_name'] ?? null)
                    || ! str_starts_with($product['product_name'], 'DEMO ')
                    || ! is_int($product['units_sold'] ?? null) || $product['units_sold'] < 1
                    || ! is_numeric($product['sales_total'] ?? null)) {
                    throw new RuntimeException('The DEMO fake accepts only verified DEMO paid-sale rankings.');
                }
            }

            return json_encode([
                'answer' => 'Recorded best sellers from paid sales: '.implode(', ', array_map(
                    fn (array $product): string => $product['product_name'].' ('.$product['units_sold'].' paid '.($product['units_sold'] === 1 ? 'unit' : 'units').')', $products,
                )).'.',
                'answer_type' => 'fact',
                'sources' => [$source],
                'confidence' => 'high',
                'refusal' => false,
            ], JSON_THROW_ON_ERROR);
        }

        if (! is_array($source) || ($source['type'] ?? null) !== 'sales'
            || ! is_array($sales) || ! is_array($sales['summary'] ?? null)
            || ! is_numeric($sales['summary']['total_sales'] ?? null)
            || ! is_int($sales['summary']['transaction_count'] ?? null)
            || ! is_string($sales['period'] ?? null) || ! is_string($sales['date'] ?? null)) {
            throw new RuntimeException('Only verified sales summaries can be used by the DEMO fake.');
        }

        if ($sales['period'] === 'yearly' && $sales['date'] === '2026-11-15') {
            throw new RuntimeException('Synthetic DEMO sales-answer timeout.');
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
