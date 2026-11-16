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

        if (isset($responseSchema['properties']['scope'])) {
            if (! str_contains($systemInstruction ?? '', 'Interpret English and Cebuano by meaning')
                || ($responseSchema['required'] ?? []) !== ['intent', 'period', 'scope']) {
                throw new RuntimeException('The DEMO natural classifier requires the complete approved schema.');
            }

            $result = match ($prompt) {
                'Current screened store turn: give me the big picture on how the shop did last month',
                'Validated pending goal: sales_period. Current screened store turn: give me the big picture on how the shop did last month' => ['store_goal', 'last month'],
                'Current screened store turn: how much did the register ring up yesterday' => ['sales_summary', 'yesterday'],
                'Current screened store turn: pila atong halin last week' => ['sales_summary', 'last week'],
                'Current screened store turn: pila atong halin gahapon' => ['sales_summary', 'yesterday'],
                'Current screened store turn: are we close to running out of anything' => ['low_stock', 'none'],
                'Current screened store turn: what flew off the rack' => ['best_sellers', 'none'],
                'Current screened store turn: can i see stock and its value' => ['inventory_summary', 'none'],
                'Current screened store turn: how much money came in from sales yesterday' => ['sales_summary', 'yesterday'],
                'Current screened store turn: can you give me an idea of how sales are doing' => ['sales_goal', 'none'],
                'Current screened store turn: how many are unpaid yet' => ['unpaid_summary', 'none'],
                'Validated pending goal: sales_goal. Current screened store turn: just the totals please' => ['sales_period', 'none'],
                'Validated pending goal: sales_period. Current screened store turn: yesterday would be great' => ['sales_summary', 'yesterday'],
                'Validated pending goal: unpaid_goal. Current screened store turn: the whole balance please',
                'Validated pending goal: unpaid_goal. Current screened store turn: pila tanan nga wala nabayran' => ['unpaid_summary', 'none'],
                'Validated pending period: last month. Current screened store turn: sales totals' => ['sales_summary', 'last month'],
                default => null,
            };

            if ($result === null) {
                throw new RuntimeException('The DEMO natural classifier accepts only fixed screened fictional examples.');
            }

            if (! in_array($result[0], $responseSchema['properties']['intent']['enum'] ?? [], true)
                || ! in_array($result[1], $responseSchema['properties']['period']['enum'] ?? [], true)
                || ! in_array('overall', $responseSchema['properties']['scope']['enum'] ?? [], true)) {
                throw new RuntimeException('The DEMO natural classifier requires the matching skill, period and scope.');
            }

            return json_encode(['intent' => $result[0], 'period' => $result[1], 'scope' => 'overall'], JSON_THROW_ON_ERROR);
        }

        $screenedSalesPeriod = match ($prompt) {
            'Current screened store turn: could we talk about how our sales are doing last month',
            'Current screened store turn: how did our sales go last month',
            'Current screened store turn: how are our sales descriptor last month',
            'Current screened store turn: how did our sales descriptor last month',
            'Current screened store turn: summarize sales last month',
            'Verified topic label: sales. Current screened store turn: could you show the last month too',
            'Verified topic label: sales. Current screened store turn: how did we do last month' => 'last month',
            default => null,
        };

        if (isset($responseSchema['properties']['intent'])
            && $prompt === 'Current screened store turn: summarize inventory'
            && str_contains($systemInstruction ?? '', 'Never provide an answer, store fact, customer detail')
            && in_array('inventory_summary', $responseSchema['properties']['intent']['enum'] ?? [], true)
            && in_array('none', $responseSchema['properties']['period']['enum'] ?? [], true)) {
            return '{"intent":"inventory_summary","period":"none"}';
        }

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
            && $prompt === 'Current screened store turn: which items are running descriptor low'
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
            throw new RuntimeException('The DEMO fake supports only verified answers, clarification styles, and fixed screened classifier turns.');
        }

        $prefix = "Verified system context (JSON):\n";
        $position = strpos($prompt, $prefix);

        if ($position === false) {
            throw new RuntimeException('No verified context was supplied to the DEMO fake.');
        }

        $context = json_decode(substr($prompt, $position + strlen($prefix)), true, 512, JSON_THROW_ON_ERROR);
        $source = $context['allowed_sources'][0] ?? null;
        $sales = $context['sales'] ?? null;

        if (($source['type'] ?? null) === 'inventory' && ($source['label'] ?? null) === 'Inventory summary'
            && is_string($source['as_of'] ?? null)) {
            $inventory = $context['inventory']['stats'] ?? [];
            $productCount = $inventory['product_count'] ?? null;
            $units = $inventory['total_units'] ?? null;
            $value = $inventory['stock_value'] ?? null;

            if (! is_int($productCount) || $productCount < 0 || ! is_int($units) || $units < 0 || ! is_numeric($value) || $value < 0) {
                throw new RuntimeException('Only verified inventory summaries can be used by the DEMO fake.');
            }

            return json_encode([
                'answer' => sprintf(
                    'Recorded inventory: %d active %s, %d %s, stock value PHP %s.',
                    $productCount,
                    $productCount === 1 ? 'product' : 'products',
                    $units,
                    $units === 1 ? 'unit' : 'units',
                    $value,
                ),
                'answer_type' => 'fact',
                'sources' => [$source],
                'confidence' => 'high',
                'refusal' => false,
            ], JSON_THROW_ON_ERROR);
        }

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
